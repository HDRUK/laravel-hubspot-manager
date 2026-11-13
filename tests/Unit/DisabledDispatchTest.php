<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Whether creating a model reaches HubSpot when the integration is disabled.
 *
 * A job is built when it is dispatched, and building it asks the model what
 * it owns. A model may own nothing in an environment where the integration
 * is disabled, such as tests or local development, so a disabled integration
 * must not dispatch at all rather than dispatch a job that exits.
 */
class DisabledDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');

        Schema::create('unowned_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncContactToHubspot::class]);
    }

    /**
     * A model that cannot say what it owns, as in an environment it has no
     * HubSpot property for. Asking it fails the test.
     */
    private function unownedUser(): Model
    {
        return new class () extends Model {
            use HasHubspotContact;

            protected $table = 'unowned_users';

            public $timestamps = false;

            public function hubspotOwnership(): HubspotOwnership
            {
                throw new LogicException('Ownership was asked for while the integration is disabled.');
            }
        };
    }

    public function test_creating_a_model_while_disabled_queues_no_sync(): void
    {
        config(['hubspotmanager.default.enabled' => false]);

        $this->unownedUser()->forceFill(['email' => 'jane@example.com'])->save();

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }

    public function test_updating_a_model_while_disabled_queues_no_sync(): void
    {
        config(['hubspotmanager.default.enabled' => false]);

        $user = $this->unownedUser()->forceFill(['email' => 'jane@example.com']);
        $user->save();

        $user->forceFill(['email' => 'janet@example.com'])->save();

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }

    public function test_deleting_a_model_while_disabled_queues_no_sync(): void
    {
        config(['hubspotmanager.default.enabled' => false]);

        $user = $this->unownedUser()->forceFill(['email' => 'jane@example.com']);
        $user->save();

        $user->delete();

        Bus::assertNotDispatched(SyncContactToHubspot::class);
    }

    public function test_creating_a_model_while_enabled_queues_a_create(): void
    {
        config(['hubspotmanager.default.enabled' => true]);

        $user = new class () extends Model {
            use HasHubspotContact;

            protected $table = 'unowned_users';

            public $timestamps = false;

            public function hubspotOwnership(): HubspotOwnership
            {
                return HubspotOwnership::owns('registry_development');
            }
        };

        $user->forceFill(['email' => 'jane@example.com'])->save();

        Bus::assertDispatched(
            SyncContactToHubspot::class,
            fn (SyncContactToHubspot $job) => $job->action === HubspotAction::Create,
        );
    }
}
