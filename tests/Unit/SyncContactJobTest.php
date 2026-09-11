<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Contracts\HubspotContactable;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
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
    private function fakeModel(int $id = 1, array $properties = ['email' => 'jane@example.com']): Model&HubspotContactable
    {
        $model = new class extends Model implements HubspotContactable {
            use HasHubspotContact;

            public int $fakeId = 1;
            /** @var array<string, mixed> */
            public array $fakeProperties = [];

            public function getKey(): mixed
            {
                return $this->fakeId;
            }

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

    private function runJob(string $action, (Model&HubspotContactable)|null $model = null): void
    {
        $job = new SyncContactToHubspot($model ?? $this->fakeModel(), $action);
        $job->handle($this->hubspot());
    }

    public function test_create_posts_new_contact_and_writes_sync_log(): void
    {
        Http::fake([
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
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
        $this->assertSame(1, HubspotContact::count());
    }

    public function test_create_upserts_when_the_model_is_already_linked(): void
    {
        $this->seedLink('hs-001');

        Http::fake([
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
        Http::fake([
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

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_update_creates_a_new_contact_after_a_successful_delete(): void
    {
        $this->seedLink('hs-001', archivedAt: '2026-01-01 00:00:00');

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response(['id' => 'hs-001'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'   => Http::response(['id' => 'hs-002'], 201),
        ]);

        $this->runJob('update');

        Http::assertSent(fn ($r) => $r->method() === 'POST');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');

        $this->assertSame('hs-002', HubspotContact::contactIdFor(1));
        $this->assertSame(1, HubspotContact::count());
    }

    public function test_failed_create_leaves_the_model_unlinked(): void
    {
        Http::fake([
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

        Http::fake([
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
        Http::fake();

        $this->runJob('delete');

        Http::assertNothingSent();
    }

    public function test_delete_skips_api_when_the_contact_is_already_archived(): void
    {
        $this->seedLink('hs-001', archivedAt: '2026-01-01 00:00:00');

        Http::fake();

        $this->runJob('delete');

        Http::assertNothingSent();
    }

    public function test_sync_is_skipped_when_disabled(): void
    {
        config(['hubspotmanager.default.enabled' => false]);
        Http::fake();

        $this->runJob('create');

        Http::assertNothingSent();
        $this->assertSame(0, HubspotSyncLog::count());
        $this->assertSame(0, HubspotContact::count());
    }

    public function test_fires_hubspot_contact_synced_event(): void
    {
        Event::fake();

        Http::fake([
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
        Http::fake([
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
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->expectException(\TypeError::class);

        // PHPStan reports this call too, which is the point: the contract is
        // enforced statically, and this test pins the runtime backstop for
        // consumers who do not run static analysis.
        // @phpstan-ignore argument.type
        new SyncContactToHubspot($model, 'create');
    }

    public function test_product_name_is_included_in_properties_when_configured(): void
    {
        config(['hubspotmanager.default.product_name' => 'TestApp']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $this->runJob('create');

        Http::assertSent(fn ($r) => ($r->data()['properties']['product_name'] ?? null) === 'TestApp');
    }
}
