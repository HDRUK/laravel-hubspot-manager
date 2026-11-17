<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\ContactSyncer;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\FlaggedUser;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\SyncableUser;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\TraitlessUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A consuming model may override hubspotSyncEnabled(), such as to switch sync
 * with a feature flag. Its answer then decides sync in place of the env var,
 * everywhere sync is checked. A model that does not override it follows the
 * env var. A model without the trait is never synced.
 */
class SyncEnabledOverrideTest extends TestCase
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
        ]);
    }

    public function test_an_override_that_is_off_wins_over_the_env_var_for_model_changes(): void
    {
        config(['hubspotmanager.default.enabled' => true, 'testing.hubspot_flag' => false]);
        Bus::fake([SyncContactToHubspot::class]);

        $user = FlaggedUser::query()->create(['email' => 'jane@example.com']);
        $user->update(['email' => 'janet@example.com']);
        $user->delete();

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }

    public function test_an_override_that_is_on_wins_over_the_env_var_for_model_changes(): void
    {
        config(['hubspotmanager.default.enabled' => false, 'testing.hubspot_flag' => true]);
        Bus::fake([SyncContactToHubspot::class]);

        FlaggedUser::query()->create(['email' => 'jane@example.com']);

        Bus::assertDispatched(
            SyncContactToHubspot::class,
            fn (SyncContactToHubspot $job) => $job->action === HubspotAction::Create,
        );
    }

    /**
     * The override may live on a subclass of the model that uses the trait,
     * so the trait must ask the model's own class, not the trait's.
     */
    public function test_an_override_on_a_subclass_of_a_trait_model_is_used(): void
    {
        config(['hubspotmanager.default.enabled' => true]);
        Bus::fake([SyncContactToHubspot::class]);

        $user = new class () extends SyncableUser {
            public static function hubspotSyncEnabled(): bool
            {
                return false;
            }
        };
        $user->forceFill(['email' => 'jane@example.com'])->save();

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }

    public function test_the_command_follows_the_configured_models_override(): void
    {
        config([
            'hubspotmanager.default.enabled' => true,
            'hubspotmanager.default.models.users' => FlaggedUser::class,
            'testing.hubspot_flag' => false,
        ]);
        FlaggedUser::query()->insert([['email' => 'jane@example.com']]);
        Bus::fake([SyncContactToHubspot::class]);

        $this->assertSame(0, Artisan::call('hubspot:sync'));
        Bus::assertNotDispatched(SyncContactToHubspot::class);

        config(['testing.hubspot_flag' => true]);

        $this->assertSame(0, Artisan::call('hubspot:sync'));
        Bus::assertDispatchedTimes(SyncContactToHubspot::class, 1);
    }

    /**
     * The flag may be switched off between a job being queued and a worker
     * running it. The job is then dropped, not sent.
     */
    public function test_a_queued_job_sends_nothing_when_the_override_is_off_by_the_time_it_runs(): void
    {
        Http::fake();
        config(['hubspotmanager.default.enabled' => true, 'testing.hubspot_flag' => true]);
        $user = FlaggedUser::query()->make(['email' => 'jane@example.com']);
        $user->id = 1;
        $job = new SyncContactToHubspot($user, HubspotAction::Create);

        config(['testing.hubspot_flag' => false]);
        $job->handle(new ContactSyncer(new Hubspot()));

        Http::assertNothingSent();
        $this->assertSame(0, HubspotSyncLog::count());
    }

    /**
     * The trait is required. A model without it is refused where the job is
     * built, even with sync on, rather than queued and dropped later.
     */
    public function test_a_job_cannot_be_built_for_a_model_without_the_trait(): void
    {
        config(['hubspotmanager.default.enabled' => true]);
        $user = TraitlessUser::query()->make(['email' => 'jane@example.com']);
        $user->id = 1;

        $this->expectException(HubspotConfigurationException::class);
        $this->expectExceptionMessage(TraitlessUser::class);

        new SyncContactToHubspot($user, HubspotAction::Create);
    }

    public function test_the_command_fails_for_a_model_without_the_trait(): void
    {
        config([
            'hubspotmanager.default.enabled' => true,
            'hubspotmanager.default.models.users' => TraitlessUser::class,
        ]);
        TraitlessUser::query()->insert([['email' => 'jane@example.com']]);
        Bus::fake([SyncContactToHubspot::class]);

        $this->assertSame(1, Artisan::call('hubspot:sync'));

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }
}
