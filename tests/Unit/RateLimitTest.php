<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Tests\Fixtures\SyncableUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Sync jobs run by a real queue worker, which is where the rate limit and
 * the retry policy take effect. HubSpot rejects bursts above its own limit,
 * so syncs past this package's limit wait for the next window rather than
 * failing, and waiting does not count as a failure.
 *
 * Time is frozen and only moved forward to the moment the next job becomes
 * available, so no test sleeps.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const CONTACTS = 'https://api.hubapi.com/crm/v3/objects/contacts';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\Orchestra\Testbench\default_migration_path());
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
            'queue.default' => 'database',
            'queue.failed.database' => 'testing',
        ]);

        $this->freezeTime();
        Http::preventStrayRequests();
    }

    /**
     * HubSpot holding no contact for any email, so every sync creates one.
     */
    private function fakeHubspot(): void
    {
        Http::fake([
            self::CONTACTS . '/*' => Http::response(['message' => 'resource not found'], 404),
            self::CONTACTS        => Http::response(['id' => 'hs-new'], 201),
        ]);
    }

    private function createUsers(int $count): void
    {
        foreach (range(1, $count) as $n) {
            SyncableUser::create(['email' => "user{$n}@example.com"]);
        }
    }

    /**
     * Run the worker once per job, without letting time pass.
     */
    private function work(int $jobs): void
    {
        foreach (range(1, $jobs) as $_) {
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]));
        }
    }

    /**
     * Run the worker until the queue is empty, moving time forward to each
     * job released for later.
     */
    private function drainQueue(): void
    {
        foreach (range(1, 100) as $_) {
            $next = DB::table('jobs')->min('available_at');

            if ($next === null) {
                return;
            }

            if ((int) $next > now()->getTimestamp()) {
                $this->travelTo(Carbon::createFromTimestamp((int) $next));
            }

            $this->work(1);
        }

        $this->fail('The queue did not drain.');
    }

    private function contactsCreated(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST')->count();
    }

    private function failedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    public function test_at_most_25_syncs_run_in_10_seconds_by_default(): void
    {
        $this->fakeHubspot();
        $this->createUsers(26);

        $this->work(26);

        $this->assertSame(25, $this->contactsCreated());
    }

    public function test_a_sync_over_the_limit_runs_in_the_next_window(): void
    {
        config(['hubspotmanager.default.syncs_per_10_seconds' => 1]);
        $this->fakeHubspot();
        $this->createUsers(2);
        $start = now();

        $this->work(2);
        $this->assertSame(1, $this->contactsCreated());

        $this->drainQueue();
        $this->assertSame(2, $this->contactsCreated());
        $this->assertGreaterThanOrEqual(10, $start->diffInSeconds(now()));
    }

    /**
     * A sync can wait out several windows behind a long queue, as it would
     * behind a bulk sync, and still run.
     */
    public function test_waiting_for_the_limit_does_not_use_up_retries(): void
    {
        config(['hubspotmanager.default.syncs_per_10_seconds' => 1]);
        $this->fakeHubspot();
        $this->createUsers(5);

        $this->drainQueue();

        $this->assertSame(5, $this->contactsCreated());
        $this->assertSame(0, $this->failedJobs());
    }

    public function test_a_sync_that_keeps_failing_is_attempted_three_times(): void
    {
        Http::fake([self::CONTACTS . '/*' => Http::response(['message' => 'internal error'], 500)]);
        $this->createUsers(1);

        $this->drainQueue();

        Http::assertSentCount(3);
        $this->assertSame(1, $this->failedJobs());
    }

    /**
     * A backstop for a limit that never frees up, such as one set to 0: a
     * sync gives up a day after it was dispatched rather than waiting
     * forever.
     */
    public function test_a_sync_still_waiting_a_day_after_dispatch_fails(): void
    {
        config(['hubspotmanager.default.syncs_per_10_seconds' => 1]);
        $this->fakeHubspot();
        $this->createUsers(2);
        $this->work(2);

        $this->travel(1)->days();
        $this->travel(1)->seconds();
        $this->work(1);

        $this->assertSame(1, $this->contactsCreated());
        $this->assertSame(1, $this->failedJobs());
    }
}
