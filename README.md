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
HUBSPOT_IDENTITY_PROPERTY=email                # default, can be omitted
```

The full config is available at `config/hubspotmanager.php` after publishing.

> **Note:** Use a HubSpot [Private App](https://developers.hubspot.com/docs/api/private-apps) token for `HUBSPOT_API_KEY`, not a legacy API key.

## Automatic sync via the trait

Add `HasHubspotContact` to any Eloquent model you want synced, and declare the `HubspotContactable` contract. Model `created`, `updated`, and `deleted` events will automatically dispatch a queued job to HubSpot.

```php
use Hdruk\LaravelHubspotManager\Contracts\HubspotContactable;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;

class User extends Authenticatable implements HubspotContactable
{
    use HasHubspotContact;
}
```

The trait implements everything the contract requires, so declaring it is normally the only change. The sync job accepts `Model&HubspotContactable`, so a model that cannot be synced is rejected where the job is constructed rather than failing partway through a queued job.

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

### How a model is matched to a HubSpot contact

Once a model has been synced, the link between it and its HubSpot contact is stored in the `hubspot_contacts` table, so updates and deletes target the correct record without any extra configuration. Installs that predate this table have their links backfilled from `hubspot_sync_logs` when the migration runs.

Contacts are identified in HubSpot by `email`, which is HubSpot's own [primary unique identifier](https://knowledge.hubspot.com/records/deduplication-of-records) for contacts. Set `HUBSPOT_IDENTITY_PROPERTY` to use a different property across all models, or override the method on a single model:

```php
public function hubspotIdentityProperty(): string
{
    return 'hs_object_id';
}
```

The value is read from `toHubspotProperties()`, keyed by HubSpot property name, so it keeps working when your local column is named differently. A model whose identity is missing, blank, or non-scalar has no identity, and will never be matched against an existing contact by value.

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

| Column | Description |
|---|---|
| `user_id` | Primary key of the synced model |
| `action` | `create`, `update`, or `delete` |
| `status_code` | HTTP status returned by HubSpot |
| `hubspot_contact_id` | The HubSpot contact ID (nullable on delete/failure) |
| `error` | Error message on failure, `null` on success |

Access logs via the relationship added by the trait:

```php
$user->hubspotSyncLogs;

$user->hubspotSyncLogs()->where('action', 'create')->first()->wasSuccessful(); // true/false
```

## Contact mapping

The `hubspot_contacts` table holds the current link between a model and its HubSpot contact — one row per model, and present state only. The history of how that state was reached stays in `hubspot_sync_logs`.

| Column | Description |
|---|---|
| `user_id` | Primary key of the synced model, unique |
| `hubspot_contact_id` | The HubSpot contact this model is linked to |
| `archived_at` | Set when the contact is archived in HubSpot, `null` while the link is live |

Note that deleting a model **archives** its HubSpot contact — HubSpot's delete endpoint moves the contact to the recycling bin, where it can be restored for 90 days. It is not a permanent deletion, and does not on its own satisfy a right-to-erasure request.

## Events

`HubspotContactSynced` is fired after every sync attempt, regardless of outcome. Listen to it to trigger downstream logic:

```php
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;

Event::listen(HubspotContactSynced::class, function (HubspotContactSynced $event) {
    // $event->model          — the Eloquent model that was synced
    // $event->action         — 'create' | 'update' | 'delete'
    // $event->statusCode     — HTTP status code
    // $event->hubspotContactId — HubSpot contact ID (nullable)
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
