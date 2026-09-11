<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Jobs\SyncOutcome;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class SyncContactJobTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hubspotmanager.default.access.hubspot_base_url' => 'https://api.hubapi.com',
            'hubspotmanager.default.access.hubspot_api_key' => 'test-key',
            'hubspotmanager.default.enabled' => true,
            'hubspotmanager.default.product_name' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function fakeModel(int $id = 1, array $properties = ['email' => 'jane@example.com']): Model
    {
        $model = new class extends Model {
            use HasHubspotContact;

            public int $fakeId = 1;
            /** @var array<string, mixed> */
            public array $fakeProperties = [];

            public function getKey(): mixed
            {
                return $this->fakeId;
            }

            /**
             * @return array<string, mixed>
             */
            public function toHubspotProperties(): array
            {
                return $this->fakeProperties;
            }
        };

        $model->fakeId = $id;
        $model->fakeProperties = $properties;

        return $model;
    }

    private function hubspot(): Hubspot
    {
        return new Hubspot();
    }

    private function seedLink(string $contactId = 'hs-001', ?string $archivedAt = null, int $userId = 1): void
    {
        HubspotContact::create([
            'user_id'            => $userId,
            'hubspot_contact_id' => $contactId,
            'archived_at'        => $archivedAt,
        ]);
    }

    /**
     * Stray requests are blocked rather than sent: an unfaked URL in an
     * Http::fake() array is passed through to the real network, so a test
     * that misses one would call HubSpot for real.
     *
     * An unlinked model is looked up before it is created, so unless a test
     * says otherwise HubSpot holds no matching contact.
     *
     * @param  array<string, mixed>  $responses
     */
    private function fakeHubspot(array $responses = []): void
    {
        Http::preventStrayRequests();

        Http::fake($responses + [
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(
                ['message' => 'resource not found'], 404
            ),
        ]);
    }

    private function runJob(string $action, ?Model $model = null): void
    {
        $job = new SyncContactToHubspot($model ?? $this->fakeModel(), HubspotAction::from($action));
        $job->handle($this->hubspot());
    }

    public function test_create_posts_new_contact_and_writes_sync_log(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        $log = HubspotSyncLog::query()->firstOrFail();
        $this->assertSame(1, $log->user_id);
        $this->assertSame('create', $log->action);
        $this->assertSame(201, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
        $this->assertNull($log->error);
    }

    public function test_create_links_the_model_to_the_new_contact(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
        $this->assertSame(1, HubspotContact::count());
    }

    public function test_create_adopts_an_existing_hubspot_contact_when_one_matches(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-900'              => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'                     => Http::response(['id' => 'hs-new'], 201),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'hs-900'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');

        $this->assertSame('hs-900', HubspotContact::contactIdFor(1));

        $log = HubspotSyncLog::query()->firstOrFail();
        $this->assertSame(200, $log->status_code);
        $this->assertSame('hs-900', $log->hubspot_contact_id);
    }

    public function test_create_creates_when_hubspot_holds_no_matching_contact(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['message' => 'not found'], 404),
            'https://api.hubapi.com/crm/v3/objects/contacts'                     => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'POST');

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
        $this->assertSame(201, HubspotSyncLog::query()->firstOrFail()->status_code);
    }

    public function test_update_adopts_an_existing_hubspot_contact_when_one_matches(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-900'              => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'                     => Http::response(['id' => 'hs-new'], 201),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'hs-900'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');

        $this->assertSame('hs-900', HubspotContact::contactIdFor(1));
    }

    public function test_a_model_with_no_identity_is_never_looked_up(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create', $this->fakeModel(properties: ['firstname' => 'Jane']));

        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_linked_model_is_never_looked_up(): void
    {
        $this->seedLink('hs-001');

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runJob('create');

        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
    }

    public function test_delete_never_adopts_an_unlinked_contact(): void
    {
        $this->fakeHubspot();

        $this->runJob('delete');

        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_a_failed_lookup_does_not_create_a_contact(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['message' => 'boom'], 500),
            'https://api.hubapi.com/crm/v3/objects/contacts'                     => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->expectException(HubspotApiException::class);

        try {
            $this->runJob('create');
        } finally {
            Http::assertNotSent(fn ($r) => $r->method() === 'POST');
            $this->assertNull(HubspotContact::contactIdFor(1));
            $this->assertSame(500, HubspotSyncLog::query()->firstOrFail()->status_code);
        }
    }

    public function test_a_create_rejected_as_a_duplicate_adopts_the_existing_contact(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/247867309742' => Http::response(['id' => '247867309742'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'              => Http::response([
                'status'        => 'error',
                'message'       => 'Contact already exists. Existing ID: 247867309742',
                'correlationId' => '01a09086-5fe9-70ea-8eea-5d3ded31fe2c',
                'category'      => 'CONFLICT',
            ], 409),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '247867309742'));

        $this->assertSame('247867309742', HubspotContact::contactIdFor(1));

        $log = HubspotSyncLog::query()->firstOrFail();
        $this->assertSame(200, $log->status_code);
        $this->assertSame(SyncOutcome::VIA_CONFLICT, $log->resolved_via);
        $this->assertNull($log->error);
    }

    public function test_a_conflict_without_an_id_is_not_recovered(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response([
                'message' => 'Contact already exists.',
            ], 409),
        ]);

        $this->expectException(HubspotApiException::class);

        try {
            $this->runJob('create');
        } finally {
            Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
            $this->assertNull(HubspotContact::contactIdFor(1));
            $this->assertSame(409, HubspotSyncLog::query()->firstOrFail()->status_code);
        }
    }

    public function test_the_sync_log_records_how_the_contact_was_resolved(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);
        $this->runJob('create');
        $this->assertSame(SyncOutcome::VIA_CREATED, HubspotSyncLog::query()->firstOrFail()->resolved_via);

        HubspotSyncLog::query()->delete();

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);
        $this->runJob('update');
        $this->assertSame(SyncOutcome::VIA_LINK, HubspotSyncLog::query()->firstOrFail()->resolved_via);
    }

    public function test_an_adopted_contact_is_recorded_as_resolved_by_lookup(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-900'              => Http::response(['id' => 'hs-900'], 200),
        ]);

        $this->runJob('create');

        $this->assertSame(SyncOutcome::VIA_LOOKUP, HubspotSyncLog::query()->firstOrFail()->resolved_via);
    }

    public function test_a_failed_sync_records_no_resolution(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['message' => 'Invalid email'], 400),
        ]);

        $this->expectException(HubspotApiException::class);

        try {
            $this->runJob('create');
        } finally {
            $this->assertNull(HubspotSyncLog::query()->firstOrFail()->resolved_via);
        }
    }

    public function test_create_upserts_when_the_model_is_already_linked(): void
    {
        $this->seedLink('hs-001');

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'hs-001'));

        $log = HubspotSyncLog::orderBy('id', 'desc')->firstOrFail();
        $this->assertSame(200, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
    }

    public function test_update_creates_contact_when_the_model_is_unlinked(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-002'], 201),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'POST');

        $this->assertSame('hs-002', HubspotSyncLog::query()->firstOrFail()->hubspot_contact_id);
        $this->assertSame('hs-002', HubspotContact::contactIdFor(1));
    }

    public function test_update_patches_when_the_model_is_linked(): void
    {
        $this->seedLink('hs-001');

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_update_creates_a_new_contact_after_a_successful_delete(): void
    {
        $this->seedLink('hs-001', archivedAt: '2026-01-01 00:00:00');

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'        => Http::response(['id' => 'hs-002'], 201),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'POST');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');

        $this->assertSame('hs-002', HubspotContact::contactIdFor(1));
        $this->assertSame(1, HubspotContact::count());
    }

    public function test_failed_create_leaves_the_model_unlinked(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['message' => 'Invalid email'], 400),
        ]);

        $this->expectException(HubspotApiException::class);

        try {
            $this->runJob('create');
        } finally {
            $this->assertNull(HubspotContact::contactIdFor(1));
            $this->assertSame(0, HubspotContact::count());
        }
    }

    public function test_delete_archives_contact_when_the_model_is_linked(): void
    {
        $this->seedLink('hs-001');

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(null, 204),
        ]);

        $this->runJob('delete');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'hs-001'));

        $log = HubspotSyncLog::orderBy('id', 'desc')->firstOrFail();
        $this->assertSame('delete', $log->action);
        $this->assertSame(204, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);

        $this->assertNull(HubspotContact::contactIdFor(1));
        $this->assertNotNull(HubspotContact::query()->firstOrFail()->archived_at);
    }

    public function test_delete_skips_api_when_the_model_was_never_linked(): void
    {
        $this->fakeHubspot();

        $this->runJob('delete');

        Http::assertNothingSent();
    }

    public function test_delete_skips_api_when_the_contact_is_already_archived(): void
    {
        $this->seedLink('hs-001', archivedAt: '2026-01-01 00:00:00');

        $this->fakeHubspot();

        $this->runJob('delete');

        Http::assertNothingSent();
    }

    public function test_sync_is_skipped_when_disabled(): void
    {
        config(['hubspotmanager.default.enabled' => false]);
        $this->fakeHubspot();

        $this->runJob('create');

        Http::assertNothingSent();
        $this->assertSame(0, HubspotSyncLog::count());
        $this->assertSame(0, HubspotContact::count());
    }

    public function test_fires_hubspot_contact_synced_event(): void
    {
        Event::fake();

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        Event::assertDispatched(HubspotContactSynced::class, function (HubspotContactSynced $e) {
            return $e->action === 'create'
                && $e->statusCode === 201
                && $e->hubspotContactId === 'hs-001';
        });
    }

    public function test_logs_error_and_rethrows_on_api_failure(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response([
                'message' => 'Invalid email',
            ], 400),
        ]);

        $this->expectException(HubspotApiException::class);

        try {
            $this->runJob('create');
        } finally {
            $log = HubspotSyncLog::query()->firstOrFail();
            $this->assertSame(400, $log->status_code);
            $this->assertSame('Invalid email', $log->error);
            $this->assertNull($log->hubspot_contact_id);
        }
    }

    public function test_a_model_that_cannot_be_synced_is_rejected_at_dispatch(): void
    {
        $model = new class extends Model {};

        $this->expectException(HubspotConfigurationException::class);
        $this->expectExceptionMessageMatches('/toHubspotProperties/');

        new SyncContactToHubspot($model, HubspotAction::Create);
    }

    public function test_a_model_using_only_the_trait_is_adopted_by_lookup(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-900'              => Http::response(['id' => 'hs-900'], 200),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'hs-900'));
        $this->assertSame('hs-900', HubspotContact::contactIdFor(1));
    }

    public function test_an_email_is_trimmed_before_being_looked_up(): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-900'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-900'              => Http::response(['id' => 'hs-900'], 200),
        ]);

        $this->runJob('create', $this->fakeModel(properties: ['email' => "  jane@example.com\n"]));

        Http::assertSent(fn ($r) =>
            $r->method() === 'GET' && str_contains($r->url(), '/contacts/jane%40example.com?'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableEmails(): array
    {
        return [
            'null'       => [null],
            'empty'      => [''],
            'whitespace' => ["  \t "],
            'non scalar' => [['jane@example.com']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableEmails')]
    public function test_an_unusable_email_is_never_looked_up(mixed $email): void
    {
        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create', $this->fakeModel(properties: ['email' => $email]));

        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_product_name_is_included_in_properties_when_configured(): void
    {
        config(['hubspotmanager.default.product_name' => 'TestApp']);

        $this->fakeHubspot([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => ($r->data()['properties']['product_name'] ?? null) === 'TestApp');
    }
}
