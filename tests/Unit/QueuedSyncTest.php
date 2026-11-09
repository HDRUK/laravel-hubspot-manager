<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\SyncableUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Sync jobs as a real queue runs them: dispatched by the model's own
 * lifecycle, serialised onto the queue, and unserialised by a worker that
 * may run long after the model changed.
 *
 * The other job tests call handle() on a job built in memory, which never
 * crosses that boundary and so cannot see what is lost on it.
 */
class QueuedSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');

        Schema::create('syncable_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
        });
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

        Http::preventStrayRequests();
    }

    /**
     * What a worker does with a job: rebuild it from its queued payload and
     * run it.
     */
    private function runAsWorker(SyncContactToHubspot $job): void
    {
        /** @var SyncContactToHubspot $restored */
        $restored = unserialize(serialize($job));

        $restored->handle(new Hubspot());
    }

    private function lastDispatchedJob(): SyncContactToHubspot
    {
        $job = Bus::dispatched(SyncContactToHubspot::class)->last();

        $this->assertInstanceOf(SyncContactToHubspot::class, $job);

        return $job;
    }

    public function test_a_deleted_model_still_archives_its_contact_when_run_from_the_queue(): void
    {
        Bus::fake([SyncContactToHubspot::class]);

        $user = SyncableUser::create(['email' => 'jane@example.com']);
        HubspotContact::linkToUser($user->id, 'hs-001');

        $user->delete();

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(null, 204),
        ]);

        $this->runAsWorker($this->lastDispatchedJob());

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'hs-001'));

        $this->assertNull(HubspotContact::contactIdForUser($user->id));

        $log = HubspotSyncLog::query()->latest('id')->firstOrFail();
        $this->assertSame('delete', $log->action);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
    }
}
