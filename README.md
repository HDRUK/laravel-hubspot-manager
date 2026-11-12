# Laravel HubSpot Manager

A Laravel package for syncing application users to HubSpot contacts, with queued background sync, full CRUD support, and per-attempt logging.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require hdruk/laravel-hubspot-manager
```

Publish the config file:

```bash
php artisan vendor:publish --tag=hubspot-config
```

Run the migration:

```bash
php artisan migrate
```

## Configuration

Add the following to your `.env`:

```env
HUBSPOT_API_KEY=your-private-app-token
HUBSPOT_BASE_URL=https://api.hubapi.com        # default, can be omitted
HUBSPOT_INTEGRATION_ENABLED=true               # default, can be omitted
HUBSPOT_INTEGRATION_PRODUCT_NAME=MyApp         # optional — for your reference
HUBSPOT_SYNC_USER_MODEL=App\Models\User        # default, can be omitted
```

The full config is available at `config/hubspotmanager.php` after publishing.

> **Note:** Use a HubSpot [Private App](https://developers.hubspot.com/docs/api/private-apps) token for `HUBSPOT_API_KEY`, not a legacy API key.

## Automatic sync via the trait

Add `HasHubspotContact` to any Eloquent model you want synced. Model `created`, `updated`, and `deleted` events will automatically dispatch a queued job to HubSpot.

```php
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;

class User extends Authenticatable
{
    use HasHubspotContact;

    public function hubspotOwnership(): HubspotOwnership
    {
        return HubspotOwnership::owns('my_system_flag');
    }
}
```

Every model must declare `hubspotOwnership()`; see [Sharing a contact with other systems](#sharing-a-contact-with-other-systems). The trait declares it abstract, so a model that leaves it out fails as soon as its class is loaded, and in static analysis.

A model that defines no `toHubspotProperties()` or `hubspotOwnership()`, or has no primary key yet, is rejected where the job is constructed rather than failing partway through a queued job.

`toHubspotProperties()` is called when the job is dispatched, not when it runs. The job carries the model's key and properties as they were at the moment of the change, so a retry sends exactly what the first attempt sent, and a deleted model can still be synced. The `product_name` config is read at the same point. This also means `toHubspotProperties()` runs in the request that changed the model, so anything it reads (such as `app()->environment()`) comes from that request rather than from the queue worker.

By default the trait maps `email`, `first_name` / `firstname`, and `last_name` / `lastname` to their HubSpot equivalents. Override `toHubspotProperties()` to customise the mapping:

```php
public function toHubspotProperties(): array
{
    return [
        'email'     => $this->email,
        'firstname' => $this->name,
        'phone'     => $this->phone_number,
        'company'   => $this->organisation->name,
    ];
}
```

### Sharing a contact with other systems

A HubSpot contact is one per email, so it may be shared with other systems, or with other environments of the same system, that hold their own record for the same person. It may also predate all of them. A system cannot see the others, so the package treats every contact as shared: each model names the properties it owns, and acts on no others.

```php
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;

public function hubspotOwnership(): HubspotOwnership
{
    return HubspotOwnership::owns('my_system_flag', 'my_system_role');
}
```

`owns()` needs at least one property name. There is no default, since only the model knows what it owns.

Properties every system sends, such as `email`, `firstname` and `lastname`, are not owned by any one of them. Whichever system syncs last sets them.

### What deleting a model does

Deleting a model does not mean the person is gone. Its owned properties are set to empty, no other property on the contact is sent, and the contact stays in place for the other systems. The package never archives or deletes a HubSpot contact.

### How a model is matched to a HubSpot contact

Once a model has been synced, the link between it and its HubSpot contact is stored in the `hubspot_contacts` table, so updates and deletes target the correct record without any extra configuration.

When a model has no stored link, the package asks HubSpot whether it already holds a contact with the same identity before creating one:

1. **Stored link** — the `hubspot_contacts` row, if there is one.
2. **Lookup by identity** — `GET /crm/v3/objects/contacts/{value}?idProperty={property}`. A contact that predates this package is adopted and updated rather than duplicated.
3. **Create** — only when HubSpot holds no matching contact. If HubSpot rejects the create as a duplicate (409), the contact it names in the error is adopted instead. This covers the case where two queue workers race, or a contact appears between the lookup and the create.

Deletes never reach step 2. A model that this package has no link for sends nothing to HubSpot when deleted.

The lookup matches on `email`, which is HubSpot's own [primary unique identifier](https://knowledge.hubspot.com/records/deduplication-of-records) for contacts, and is not configurable for that reason. The address is read from `toHubspotProperties()`, so it keeps working when your local column is named differently. It is trimmed and lowercased before the lookup, and the same normalised email is stored with the link.

A create or update whose email is missing, blank, or non-scalar is rejected where the job is constructed, with a `HubspotConfigurationException`. Without an email a contact cannot be found again, and looking one up by an empty value would match an arbitrary record. Deletes are exempt, since they act on the stored link alone.

### What changing a model's email does

Each link records the email it was made for, so a sync can tell when a model's email has changed.

The new email is never written to the contact the model is linked to. That contact is keyed on an email other systems may still hold, so rewriting it would leave them linked to a contact that no longer holds their email. The model moves instead:

1. The contact HubSpot holds for the new email is looked up.
2. If it is the contact the model is already linked to (for example, the new email is one of its secondary emails), it is updated in place and nothing moves.
3. Otherwise the model's owned properties are set to empty on the contact it is leaving. Every other property there stays.
4. The contact for the new email is updated if HubSpot holds one, or created if not, with the model's own properties. Properties owned by other systems are never copied. If the model holds no `firstname` or `lastname`, that name is taken from the contact it left.
5. The model is linked to that contact, and the sync log records `resolved_via = moved`.

The link moves last, so a move that fails part way is completed when the job retries.

Links made before emails were recorded hold none. The first sync after upgrading reads the linked contact's email from HubSpot and records it before deciding, which costs one extra request per link, once. If that contact holds no email in HubSpot, the sync fails rather than guess.

## Manual usage via the Facade

```php
use Hdruk\LaravelHubspotManager\Facades\Hubspot;

// Create
$contact = Hubspot::createContact([
    'email'     => 'jane@example.com',
    'firstname' => 'Jane',
    'lastname'  => 'Doe',
]);

// Get by HubSpot contact ID (optionally restrict returned properties)
$contact = Hubspot::getContact('12345', ['email', 'firstname', 'lastname']);

// Update
Hubspot::updateContact('12345', ['phone' => '+44 7700 000000']);

// Delete
Hubspot::deleteContact('12345');

// Search
$results = Hubspot::searchContacts([
    ['propertyName' => 'email', 'operator' => 'EQ', 'value' => 'jane@example.com'],
]);
```

Or resolve the service directly from the container:

```php
$hubspot = app(\Hdruk\LaravelHubspotManager\Services\Hubspot::class);
```

## Sync logging

Every sync attempt — successful or not — is recorded in the `hubspot_sync_logs` table.

| Column               | Description                                       |
| -------------------- | ------------------------------------------------- |
| `user_id`            | Primary key of the synced model                   |
| `action`             | `create`, `update`, or `delete`                   |
| `status_code`        | HTTP status returned by HubSpot                   |
| `hubspot_contact_id` | The HubSpot contact ID (nullable on failure)      |
| `resolved_via`       | How the contact was arrived at, `null` on failure |
| `error`              | Error message on failure, `null` on success       |

`resolved_via` records how the contact was arrived at — `link`, `lookup`, `conflict` or `created` from the steps above, or `moved` when a model followed its changed email to another contact (see [What changing a model's email does](#what-changing-a-models-email-does)) — so you can see how often existing contacts are being adopted rather than duplicated:

```php
HubspotSyncLog::query()
    ->whereNotNull('resolved_via')
    ->selectRaw('resolved_via, count(*) as total')
    ->groupBy('resolved_via')
    ->pluck('total', 'resolved_via');
```

Access logs via the relationship added by the trait:

```php
$user->hubspotSyncLogs;

$user->hubspotSyncLogs()->where('action', 'create')->first()->wasSuccessful(); // true/false
```

## Contact mapping

The `hubspot_contacts` table holds the current link between a model and its HubSpot contact — one row per model, and present state only. The history of how that state was reached stays in `hubspot_sync_logs`.

| Column               | Description                                                                |
| -------------------- | -------------------------------------------------------------------------- |
| `user_id`            | Primary key of the synced model, unique                                    |
| `hubspot_contact_id` | The HubSpot contact this model is linked to                                |
| `unlinked_at`        | Set when the link ends (the model was deleted), `null` while the link is live |

Note that deleting a model leaves its HubSpot contact in place (see [What deleting a model does](#what-deleting-a-model-does)): the person's email, names, and any properties other systems wrote all remain in HubSpot. Deleting a model therefore does not satisfy a right-to-erasure request. Erase the person in HubSpot itself.

## Events

`HubspotContactSynced` is fired after every sync attempt, regardless of outcome. Listen to it to trigger downstream logic:

```php
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;

Event::listen(HubspotContactSynced::class, function (HubspotContactSynced $event) {
    // $event->userId         — primary key of the model that was synced
    // $event->action         — 'create' | 'update' | 'delete'
    // $event->statusCode     — HTTP status code
    // $event->hubspotContactId — HubSpot contact ID (nullable)
    // $event->resolvedVia    — 'link' | 'lookup' | 'conflict' | 'created' | 'moved' (nullable)
});
```

## Bulk sync via Artisan

Re-sync all users (or a specific one) without touching model events:

```bash
# Dispatch sync jobs for every user
php artisan hubspot:sync

# Dispatch a sync job for a specific user by primary key
php artisan hubspot:sync --user=42
```

The command uses the model configured in `HUBSPOT_SYNC_USER_MODEL` and chunks records in batches of 200 to avoid memory exhaustion.

## Batch operations

For high-volume imports use the batch API methods to send up to 100 contacts per request:

```php
// Batch create
Hubspot::createContacts([
    ['email' => 'jane@example.com', 'firstname' => 'Jane'],
    ['email' => 'john@example.com', 'firstname' => 'John'],
]);

// Batch update — each entry must include the HubSpot contact ID
Hubspot::updateContacts([
    ['id' => 'hs-001', 'properties' => ['phone' => '+44 7700 000000']],
    ['id' => 'hs-002', 'properties' => ['phone' => '+44 7700 000001']],
]);

// Batch delete
Hubspot::deleteContacts(['hs-001', 'hs-002']);
```

## Tagging contacts with a product name

Set `HUBSPOT_INTEGRATION_PRODUCT_NAME` in your `.env` and every synced contact will automatically include a `product_name` property. You must create this as a custom contact property in HubSpot first.

## Disabling sync

Set `HUBSPOT_INTEGRATION_ENABLED=false` in your environment to stop all sync jobs from executing (jobs are still dispatched but exit immediately, keeping your queue clean).

## Error handling

Failed API calls throw `HubspotApiException`, which carries the HTTP status code and the raw HubSpot error body:

```php
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;

try {
    Hubspot::createContact(['email' => 'bad-email']);
} catch (HubspotApiException $e) {
    $e->statusCode; // e.g. 400
    $e->response;   // raw response body array
    $e->getMessage(); // HubSpot's error message
}
```

The `SyncContactToHubspot` job retries up to 3 times with exponential backoff (10s → 60s → 300s). When all retries are exhausted, a final `failed` log entry is written with the message `All retries exhausted: ...`.

## Testing

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
