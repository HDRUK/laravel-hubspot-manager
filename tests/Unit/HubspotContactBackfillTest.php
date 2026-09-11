<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * The migration backfills the mapping from existing sync history, so installs
 * that predate the mapping keep their contacts instead of recreating them.
 * Each case drops the table and re-runs the migration against seeded log rows.
 */
class HubspotContactBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');
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

    private function backfill(): void
    {
        Schema::dropIfExists('hubspot_contacts');

        $migration = require __DIR__ . '/../../src/database/migrations/2026_09_11_000000_create_hubspot_contacts_table.php';
        $migration->up();
    }

    public function test_a_previously_synced_model_is_linked(): void
    {
        $this->seedLog(['hubspot_contact_id' => 'hs-001']);

        $this->backfill();

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
    }

    public function test_the_most_recent_successful_sync_wins(): void
    {
        $this->seedLog(['action' => 'create', 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog(['action' => 'update', 'status_code' => 200, 'hubspot_contact_id' => 'hs-002']);

        $this->backfill();

        $this->assertSame('hs-002', HubspotContact::contactIdFor(1));
        $this->assertSame(1, HubspotContact::count());
    }

    public function test_rows_are_ordered_by_id_when_timestamps_tie(): void
    {
        $this->seedLog(['hubspot_contact_id' => 'hs-older']);
        $this->seedLog(['hubspot_contact_id' => 'hs-newer']);

        $this->backfill();

        $this->assertSame('hs-newer', HubspotContact::contactIdFor(1));
    }

    public function test_failed_syncs_are_ignored(): void
    {
        $this->seedLog(['action' => 'create', 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog(['action' => 'update', 'status_code' => 500, 'hubspot_contact_id' => 'hs-failed']);

        $this->backfill();

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
    }

    public function test_a_model_with_no_successful_sync_is_not_linked(): void
    {
        $this->seedLog(['status_code' => 400, 'hubspot_contact_id' => null]);

        $this->backfill();

        $this->assertSame(0, HubspotContact::count());
    }

    public function test_a_trailing_delete_is_carried_over_as_archived(): void
    {
        $this->seedLog(['action' => 'create', 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog([
            'action'             => 'delete',
            'status_code'        => 204,
            'hubspot_contact_id' => 'hs-001',
            'created_at'         => '2026-02-01 00:00:00',
        ]);

        $this->backfill();

        $this->assertNull(HubspotContact::contactIdFor(1));

        $row = HubspotContact::where('user_id', 1)->first();
        $this->assertSame('hs-001', $row->hubspot_contact_id);
        $this->assertSame('2026-02-01', $row->archived_at->toDateString());
    }

    public function test_each_model_is_backfilled_independently(): void
    {
        $this->seedLog(['user_id' => 1, 'hubspot_contact_id' => 'hs-001']);
        $this->seedLog(['user_id' => 2, 'hubspot_contact_id' => 'hs-002']);
        $this->seedLog(['user_id' => 3, 'status_code' => 500, 'hubspot_contact_id' => 'hs-003']);

        $this->backfill();

        $this->assertSame('hs-001', HubspotContact::contactIdFor(1));
        $this->assertSame('hs-002', HubspotContact::contactIdFor(2));
        $this->assertNull(HubspotContact::contactIdFor(3));
        $this->assertSame(2, HubspotContact::count());
    }

    public function test_an_empty_log_backfills_nothing(): void
    {
        $this->backfill();

        $this->assertSame(0, HubspotContact::count());
    }
}
