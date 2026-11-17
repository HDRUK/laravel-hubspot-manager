# Whether sync is on

How the package decides whether to sync a model, and how a consuming application switches it with something other than the env var, such as a feature flag.

```
model's hubspotSyncEnabled()          (optional override, public static)
        │ not overridden
        ▼
HasHubspotContact::hubspotSyncEnabled()
        │
        ▼
Support\HubspotSync::enabledByDefault()   reads HUBSPOT_INTEGRATION_ENABLED, off when missing
```

`Support\HubspotSync::enabledFor($modelClass)` asks the model's class. A class without the method, i.e. a model synced without the trait, falls back to `enabledByDefault()`. `HubspotSync` is the only code that reads `hubspotmanager.default.enabled`.

## Where it is asked

| Where | How | When it is off |
| --- | --- | --- |
| `HasHubspotContact` model events | `static::hubspotSyncEnabled()`, so an override on the model or a subclass is used | No job is dispatched, so the model's ownership and properties are never read |
| `hubspot:sync` command | `HubspotSync::enabledFor()` on the configured `models.users` class | Warns and dispatches nothing |
| `SyncContactToHubspot::handle()` | `HubspotSync::enabledFor()` on the `modelClass` stored in the job | Returns without calling HubSpot or writing a sync log |

It is asked again when a job runs, not only at dispatch. A flag switched off after a job was queued drops that job, so the flag can be used to stop sync in an emergency.

## Run-time risks

These are not checked by PHPStan, so they are covered by `SyncEnabledOverrideTest` or documented:

- **The override must be `public static`.** PHP does not check a class method that replaces a concrete trait method against the trait's signature. A non-static override throws when the command or job calls it.
- **The answer can change between dispatch and handle.** That is intended. The override must not remember its answer, because queue workers are long-lived.
- **Pennant scope.** Pennant's default scope is the logged-in user. Queue workers and console commands have none, so an override should pass an explicit scope, e.g. `Feature::for(null)`.
- **Jobs queued before `modelClass` was added** have the property uninitialised and throw when they run. Drain the queue before deploying.
