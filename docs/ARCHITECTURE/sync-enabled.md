# Whether sync is on

How the package decides whether to sync a model, and how a consuming application switches it with something other than the env var, such as a feature flag.

```
model's hubspotSyncEnabled()          (optional override, public static)
        │ not overridden
        ▼
HasHubspotContact::hubspotSyncEnabled()   reads HUBSPOT_INTEGRATION_ENABLED, off when missing
```

The trait's default is the only code that reads `hubspotmanager.default.enabled`.

## The trait is required

Only a model that uses `HasHubspotContact` is synced, directly, through a parent class or through another trait. `SyncContactToHubspot::canSync()` checks this with Laravel's `class_uses_recursive()`.

| Where | When the model has no trait |
| --- | --- |
| `SyncContactToHubspot` constructor | Throws `HubspotConfigurationException::missingTrait`, so nothing is queued |
| `hubspot:sync` command | Checks the configured `models.users` class before building any job, prints the same message and returns `FAILURE` |

With the trait required, the job can rely on everything the trait supplies, and PHP refuses to load a model that uses the trait but omits `hubspotOwnership()`, which the trait declares abstract. That moves "the model declares what it owns" from a run-time check to class load and static analysis.

## Where it is asked

| Where | How | When it is off |
| --- | --- | --- |
| `HasHubspotContact` model events | `static::hubspotSyncEnabled()`, so an override on the model or a subclass is used | No job is dispatched, so the model's ownership and properties are never read |
| `hubspot:sync` command | `$modelClass::hubspotSyncEnabled()` on the configured `models.users` class | Warns and dispatches nothing |
| `SyncContactToHubspot::handle()` | `$this->modelClass::hubspotSyncEnabled()` on the class stored in the job | Returns without calling HubSpot or writing a sync log |

It is asked again when a job runs, not only at dispatch. A flag switched off after a job was queued drops that job, so the flag can be used to stop sync in an emergency.

## Run-time risks

These are not checked by PHPStan, so they are covered by `SyncEnabledOverrideTest` or documented:

- **The model must use the trait.** Checked by reflection when a job is built and when the command starts.
- **The override must be `public static`.** PHP does not check a class method that replaces a concrete trait method against the trait's signature. A non-static override throws when the command or job calls it.
- **The answer can change between dispatch and handle.** That is intended. The override must not remember its answer, because queue workers are long-lived.
- **Pennant scope.** Pennant's default scope is the logged-in user. Queue workers and console commands have none, so an override should pass an explicit scope, e.g. `Feature::for(null)`.
- **Jobs queued before `modelClass` was added** have the property uninitialised and throw when they run. Drain the queue before deploying.
