<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\SyncableUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

class SyncCommandTest extends TestCase
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
            'hubspotmanager.default.enabled' => true,
            'hubspotmanager.default.models.users' => SyncableUser::class,
        ]);

        // Inserted without model events, so the only jobs are the command's.
        SyncableUser::query()->insert([
            ['email' => 'jane@example.com'],
            ['email' => 'john@example.com'],
        ]);

        Bus::fake([SyncContactToHubspot::class]);
    }

    /**
     * @return array<int, int|string>
     */
    private function syncedUserIds(): array
    {
        return Bus::dispatched(SyncContactToHubspot::class)
            ->map(fn (SyncContactToHubspot $job) => $job->userId)
            ->values()
            ->all();
    }

    /**
     * Artisan::call() passes an option as given, so a caller in code can
     * pass the id as an int where the shell always passes a string.
     */
    public function test_a_user_id_given_as_an_int_syncs_only_that_user(): void
    {
        $this->assertSame(0, Artisan::call('hubspot:sync', ['--user' => 1]));

        $this->assertSame([1], $this->syncedUserIds());
    }

    public function test_no_user_syncs_every_user(): void
    {
        $this->assertSame(0, Artisan::call('hubspot:sync'));

        $this->assertSame([1, 2], $this->syncedUserIds());
    }
}
