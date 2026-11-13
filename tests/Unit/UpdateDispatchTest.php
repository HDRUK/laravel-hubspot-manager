<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\RegistryUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

/**
 * Which saves of a model reach HubSpot.
 *
 * A model changes for many reasons HubSpot never sees, such as a login
 * timestamp. Only a change to what the model sends HubSpot should queue a
 * sync; anything else would spend a job and an API call rewriting what the
 * contact already holds.
 */
class UpdateDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');

        Schema::create('registry_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('registry_development')->nullable();
            $table->string('last_seen_at')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncContactToHubspot::class]);
    }

    private function registryUser(): RegistryUser
    {
        return RegistryUser::create([
            'email'                => 'jane@example.com',
            'first_name'           => 'Jane',
            'last_name'            => 'Doe',
            'registry_development' => 'pending',
        ]);
    }

    private function assertNoUpdateDispatched(): void
    {
        Bus::assertNotDispatched(
            SyncContactToHubspot::class,
            fn (SyncContactToHubspot $job) => $job->action === HubspotAction::Update,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function assertUpdateDispatchedWith(array $properties): void
    {
        Bus::assertDispatched(
            SyncContactToHubspot::class,
            fn (SyncContactToHubspot $job) => $job->action === HubspotAction::Update
                && $job->properties === $properties,
        );
    }

    public function test_a_change_hubspot_never_sees_queues_no_sync(): void
    {
        $user = $this->registryUser();

        $user->forceFill(['last_seen_at' => '2026-01-01 09:00:00'])->save();

        $this->assertNoUpdateDispatched();
    }

    public function test_a_change_to_a_shared_property_queues_a_sync_carrying_it(): void
    {
        $user = $this->registryUser();

        $user->update(['first_name' => 'Janet']);

        $this->assertUpdateDispatchedWith([
            'email'                => 'jane@example.com',
            'firstname'            => 'Janet',
            'lastname'             => 'Doe',
            'registry_development' => 'pending',
        ]);
    }

    public function test_a_change_to_an_owned_property_queues_a_sync_carrying_it(): void
    {
        $user = $this->registryUser();

        $user->update(['registry_development' => 'approved']);

        $this->assertUpdateDispatchedWith([
            'email'                => 'jane@example.com',
            'firstname'            => 'Jane',
            'lastname'             => 'Doe',
            'registry_development' => 'approved',
        ]);
    }

    /**
     * Clearing a property drops it from what the model sends, which is still
     * a change HubSpot must hear about.
     */
    public function test_clearing_a_property_queues_a_sync_without_it(): void
    {
        $user = $this->registryUser();

        $user->update(['last_name' => null]);

        $this->assertUpdateDispatchedWith([
            'email'                => 'jane@example.com',
            'firstname'            => 'Jane',
            'registry_development' => 'pending',
        ]);
    }

    public function test_a_change_alongside_one_hubspot_never_sees_still_queues_a_sync(): void
    {
        $user = $this->registryUser();

        $user->forceFill(['last_seen_at' => '2026-01-01 09:00:00', 'first_name' => 'Janet'])->save();

        $this->assertUpdateDispatchedWith([
            'email'                => 'jane@example.com',
            'firstname'            => 'Janet',
            'lastname'             => 'Doe',
            'registry_development' => 'pending',
        ]);
    }

    /**
     * Each save is compared with the one before it, not with the model as
     * first loaded, so a change and its reversal each reach HubSpot.
     */
    public function test_a_change_reverted_in_a_later_save_queues_both(): void
    {
        $user = $this->registryUser();

        $user->update(['registry_development' => 'approved']);
        $user->update(['registry_development' => 'pending']);

        Bus::assertDispatchedTimes(SyncContactToHubspot::class, 3);
        $this->assertUpdateDispatchedWith([
            'email'                => 'jane@example.com',
            'firstname'            => 'Jane',
            'lastname'             => 'Doe',
            'registry_development' => 'pending',
        ]);
    }
}
