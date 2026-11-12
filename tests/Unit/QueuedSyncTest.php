<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\DetachableUser;
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
        HubspotContact::linkToUser($user->id, 'hs-001', ContactEmail::parse('jane@example.com'));

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

    public function test_a_deleted_model_that_clears_properties_keeps_its_contact(): void
    {
        Bus::fake([SyncContactToHubspot::class]);

        $user = DetachableUser::create(['email' => 'jane@example.com']);
        HubspotContact::linkToUser($user->id, 'hs-001', ContactEmail::parse('jane@example.com'));

        $user->delete();

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runAsWorker($this->lastDispatchedJob());

        // Only the listed properties, blanked: nothing else about the contact
        // is rewritten, and the contact itself is not archived.
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'hs-001')
            && $r->data() === ['properties' => ['registry_development' => '', 'registry_persona' => '']]);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');

        $this->assertNull(HubspotContact::contactIdForUser($user->id));

        $log = HubspotSyncLog::query()->latest('id')->firstOrFail();
        $this->assertSame('delete', $log->action);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('hs-001', $log->hubspot_contact_id);
    }

    public function test_a_deleted_model_that_clears_properties_sends_nothing_when_unlinked(): void
    {
        Bus::fake([SyncContactToHubspot::class]);

        $user = DetachableUser::create(['email' => 'jane@example.com']);

        $user->delete();

        Http::fake();

        $this->runAsWorker($this->lastDispatchedJob());

        Http::assertNothingSent();
    }

    /**
     * Unlinking ends only the deleted model's link. The contact survives in
     * HubSpot, so a new model for the same person finds it by email rather
     * than creating a second contact.
     */
    public function test_a_model_readded_after_clearing_properties_adopts_the_surviving_contact(): void
    {
        Bus::fake([SyncContactToHubspot::class]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/jane%40example.com*' => Http::response(['id' => 'hs-001'], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $deleted = DetachableUser::create(['email' => 'jane@example.com']);
        HubspotContact::linkToUser($deleted->id, 'hs-001', ContactEmail::parse('jane@example.com'));
        $deleted->delete();
        $this->runAsWorker($this->lastDispatchedJob());

        $readded = DetachableUser::create(['email' => 'jane@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame('hs-001', HubspotContact::contactIdForUser($readded->id));
        $this->assertNull(HubspotContact::contactIdForUser($deleted->id));
    }

    public function test_a_queued_job_sends_the_model_as_it_was_when_dispatched(): void
    {
        Bus::fake([SyncContactToHubspot::class]);

        $user = SyncableUser::create(['email' => 'jane@example.com']);
        HubspotContact::linkToUser($user->id, 'hs-001', ContactEmail::parse('jane@example.com'));

        $user->update(['email' => 'jane.new@example.com']);
        $job = $this->lastDispatchedJob();

        // Changed again before the worker runs, without firing model events.
        SyncableUser::query()->whereKey($user->id)->update(['email' => 'jane.later@example.com']);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/hs-001' => Http::response(['id' => 'hs-001'], 200),
        ]);

        $this->runAsWorker($job);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && ($r->data()['properties']['email'] ?? null) === 'jane.new@example.com');
    }
}
