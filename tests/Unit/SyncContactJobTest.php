<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
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

    private function fakeModel(int $id = 1, array $properties = ['email' => 'jane@example.com']): object
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            public int $fakeId = 1;
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

    private function seedLog(array $attributes = []): void
    {
        HubspotSyncLog::insert(array_merge([
            'user_id'            => 1,
            'action'             => 'create',
            'status_code'        => 201,
            'hubspot_contact_id' => 'hs-001',
            'error'              => null,
            'created_at'         => '2026-01-01 00:00:00',
            'updated_at'         => '2026-01-01 00:00:00',
        ], $attributes));
    }

    private function hubspot(): Hubspot
    {
        return new Hubspot();
    }

    public function test_create_posts_new_contact_and_writes_sync_log(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'create');
        $job->handle($this->hubspot());

        $log = HubspotSyncLog::first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->user_id);
        $this->assertSame('create', $log->action);
        $this->assertSame(201, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
        $this->assertNull($log->error);
    }

    public function test_create_upserts_when_contact_id_already_in_log(): void
    {
        HubspotSyncLog::create([
            'user_id'            => 1,
            'action'             => 'create',
            'status_code'        => 201,
            'hubspot_contact_id' => 'hs-001',
        ]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'create');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'hs-001'));

        $log = HubspotSyncLog::orderBy('id', 'desc')->first();
        $this->assertSame(200, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
    }

    public function test_update_creates_contact_when_no_existing_log(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-002'], 201),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'update');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'POST');

        $log = HubspotSyncLog::first();
        $this->assertSame('hs-002', $log->hubspot_contact_id);
    }

    public function test_update_patches_when_contact_id_exists(): void
    {
        HubspotSyncLog::create([
            'user_id'            => 1,
            'action'             => 'create',
            'status_code'        => 201,
            'hubspot_contact_id' => 'hs-001',
        ]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'update');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_delete_archives_contact_when_id_exists(): void
    {
        HubspotSyncLog::create([
            'user_id'            => 1,
            'action'             => 'create',
            'status_code'        => 201,
            'hubspot_contact_id' => 'hs-001',
        ]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(null, 204),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'delete');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'hs-001'));

        $log = HubspotSyncLog::orderBy('id', 'desc')->first();
        $this->assertSame('delete', $log->action);
        $this->assertSame(204, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
    }

    public function test_delete_skips_api_when_no_contact_id(): void
    {
        Http::fake();

        $job = new SyncContactToHubspot($this->fakeModel(), 'delete');
        $job->handle($this->hubspot());

        Http::assertNothingSent();
    }

    public function test_sync_is_skipped_when_disabled(): void
    {
        config(['hubspotmanager.default.enabled' => false]);
        Http::fake();

        $job = new SyncContactToHubspot($this->fakeModel(), 'create');
        $job->handle($this->hubspot());

        Http::assertNothingSent();
        $this->assertSame(0, HubspotSyncLog::count());
    }

    public function test_fires_hubspot_contact_synced_event(): void
    {
        Event::fake();

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $model = $this->fakeModel();
        $job = new SyncContactToHubspot($model, 'create');
        $job->handle($this->hubspot());

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

        $job = new SyncContactToHubspot($this->fakeModel(), 'create');

        $this->expectException(HubspotApiException::class);

        try {
            $job->handle($this->hubspot());
        } finally {
            $log = HubspotSyncLog::first();
            $this->assertNotNull($log);
            $this->assertSame(400, $log->status_code);
            $this->assertSame('Invalid email', $log->error);
            $this->assertNull($log->hubspot_contact_id);
        }
    }

    public function test_product_name_is_included_in_properties_when_configured(): void
    {
        config(['hubspotmanager.default.product_name' => 'TestApp']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => 'hs-001'], 201),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'create');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => ($r->data()['properties']['product_name'] ?? null) === 'TestApp');
    }

    public function test_update_creates_a_new_contact_after_a_successful_delete(): void
    {
        $this->seedLog(['action' => 'create', 'status_code' => 201, 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog(['action' => 'delete', 'status_code' => 204, 'hubspot_contact_id' => 'hs-001']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response(['id' => 'hs-001'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'   => Http::response(['id' => 'hs-002'], 201),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'update');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'POST');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');

        $this->assertSame('hs-002', HubspotSyncLog::orderBy('id', 'desc')->first()->hubspot_contact_id);
    }

    public function test_delete_skips_api_when_the_contact_is_already_archived(): void
    {
        $this->seedLog(['action' => 'create', 'status_code' => 201, 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog(['action' => 'delete', 'status_code' => 204, 'hubspot_contact_id' => 'hs-001']);

        Http::fake();

        $job = new SyncContactToHubspot($this->fakeModel(), 'delete');
        $job->handle($this->hubspot());

        Http::assertNothingSent();
    }

    public function test_resolution_ignores_contact_ids_recorded_by_failed_syncs(): void
    {
        $this->seedLog(['action' => 'update', 'status_code' => 500, 'hubspot_contact_id' => 'hs-failed']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response(['id' => 'hs-failed'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts'   => Http::response(['id' => 'hs-003'], 201),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'update');
        $job->handle($this->hubspot());

        Http::assertSent(fn ($r) => $r->method() === 'POST');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_resolution_is_deterministic_when_log_timestamps_tie(): void
    {
        $this->seedLog(['hubspot_contact_id' => 'hs-older']);
        $this->seedLog(['hubspot_contact_id' => 'hs-newer']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response(['id' => 'hs-newer'], 200),
        ]);

        $job = new SyncContactToHubspot($this->fakeModel(), 'update');
        $job->handle($this->hubspot());

        Http::assertSent(
            fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/contacts/hs-newer')
        );
    }
}
