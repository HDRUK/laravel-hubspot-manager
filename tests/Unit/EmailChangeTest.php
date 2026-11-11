<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\Fixtures\RegistryUser;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A consumer that shares its HubSpot contact changes the email it holds for
 * a person.
 *
 * HubSpot keys a contact on its email, and other consumers still hold the
 * old one. So the change must not rewrite the shared contact. This consumer
 * moves to the contact for the new email instead: an existing one if
 * HubSpot holds it, otherwise a new one. It takes its own properties with
 * it and blanks them on the contact it left.
 *
 * Every user here is created through the queue first, so it is linked to
 * hs-old exactly as a real consumer would be.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CONTACTS = 'https://api.hubapi.com/crm/v3/objects/contacts';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');

        Schema::create('registry_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('registry_development')->nullable();
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

        Bus::fake([SyncContactToHubspot::class]);
        Http::preventStrayRequests();
    }

    /**
     * HubSpot as it stands before the change: no contact holds either email,
     * so the user's create makes hs-old, and the next create makes hs-new.
     * A test's own responses take precedence over these.
     *
     * @param  array<string, mixed>  $responses
     */
    private function fakeHubspot(array $responses = []): void
    {
        Http::fake($responses + [
            self::CONTACTS . '/jane%40example.com*'     => Http::response(['message' => 'resource not found'], 404),
            self::CONTACTS . '/jane.new%40example.com*' => Http::response(['message' => 'resource not found'], 404),
            self::CONTACTS . '/hs-old'                  => Http::response(['id' => 'hs-old'], 200),
            self::CONTACTS                              => Http::sequence()
                ->push(['id' => 'hs-old'], 201)
                ->push(['id' => 'hs-new'], 201),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedUser(array $attributes): RegistryUser
    {
        $user = RegistryUser::create($attributes);
        $this->runAsWorker($this->lastDispatchedJob());

        $this->assertSame('hs-old', HubspotContact::contactIdForUser($user->id));

        return $user;
    }

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

    /**
     * The properties of every request sent with this method to exactly this
     * URL, in the order they were sent.
     *
     * @return list<array<string, mixed>>
     */
    private function sentProperties(string $method, string $url): array
    {
        $sent = [];

        foreach (Http::recorded(fn (Request $r) => $r->method() === $method && $r->url() === $url) as [$request]) {
            $properties = $request->data()['properties'] ?? [];
            $this->assertIsArray($properties);
            $sent[] = $properties;
        }

        return $sent;
    }

    public function test_an_email_change_leaves_the_email_on_the_shared_contact_alone(): void
    {
        $this->fakeHubspot();
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'jane.new@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        foreach ($this->sentProperties('PATCH', self::CONTACTS . '/hs-old') as $properties) {
            $this->assertArrayNotHasKey('email', $properties);
        }
    }

    public function test_an_email_change_moves_this_consumer_to_a_new_contact_with_only_its_own_properties(): void
    {
        $this->fakeHubspot();
        $user = $this->linkedUser([
            'email'                => 'jane@example.com',
            'first_name'           => 'Jane',
            'last_name'            => 'Doe',
            'registry_development' => 'approved',
        ]);

        $user->update(['email' => 'jane.new@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        $created = $this->sentProperties('POST', self::CONTACTS);
        $this->assertCount(2, $created);
        $this->assertEquals([
            'email'                => 'jane.new@example.com',
            'firstname'            => 'Jane',
            'lastname'             => 'Doe',
            'registry_development' => 'approved',
        ], $created[1]);

        $this->assertSame('hs-new', HubspotContact::contactIdForUser($user->id));
    }

    public function test_an_email_change_blanks_this_consumers_properties_on_the_contact_it_left(): void
    {
        $this->fakeHubspot();
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'jane.new@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        $this->assertEquals(
            [['registry_development' => '']],
            $this->sentProperties('PATCH', self::CONTACTS . '/hs-old'),
        );
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_an_email_change_to_an_address_hubspot_already_holds_moves_to_that_contact(): void
    {
        $this->fakeHubspot([
            self::CONTACTS . '/jane.new%40example.com*' => Http::response(['id' => 'hs-other'], 200),
            self::CONTACTS . '/hs-other'                => Http::response(['id' => 'hs-other'], 200),
        ]);
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'jane.new@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        $this->assertCount(1, $this->sentProperties('POST', self::CONTACTS), 'Only the original create');
        $this->assertEquals(
            [['email' => 'jane.new@example.com', 'registry_development' => 'approved']],
            $this->sentProperties('PATCH', self::CONTACTS . '/hs-other'),
        );
        $this->assertEquals(
            [['registry_development' => '']],
            $this->sentProperties('PATCH', self::CONTACTS . '/hs-old'),
        );

        $this->assertSame('hs-other', HubspotContact::contactIdForUser($user->id));
    }

    public function test_a_name_changed_with_the_email_is_sent_to_the_new_contact(): void
    {
        $this->fakeHubspot();
        $user = $this->linkedUser(['email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe']);

        $user->update(['email' => 'jane.new@example.com', 'first_name' => 'Janet']);
        $this->runAsWorker($this->lastDispatchedJob());

        $created = $this->sentProperties('POST', self::CONTACTS);
        $this->assertSame('Janet', $created[1]['firstname'] ?? null);
        $this->assertSame('Doe', $created[1]['lastname'] ?? null);
    }

    public function test_a_name_this_consumer_does_not_hold_is_carried_over_from_the_contact_it_left(): void
    {
        $this->fakeHubspot([
            self::CONTACTS . '/hs-old?*' => Http::response([
                'id'         => 'hs-old',
                'properties' => ['firstname' => 'Jane', 'lastname' => 'Doe'],
            ], 200),
        ]);
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'jane.new@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        $created = $this->sentProperties('POST', self::CONTACTS);
        $this->assertSame('Jane', $created[1]['firstname'] ?? null);
        $this->assertSame('Doe', $created[1]['lastname'] ?? null);
    }

    /**
     * A job is retried with the same snapshot, so a move that fails part way
     * must be finished by the retry, not lost because the first attempt had
     * already moved the link.
     */
    public function test_an_email_change_that_fails_part_way_is_completed_on_retry(): void
    {
        $this->fakeHubspot([
            self::CONTACTS => Http::sequence()
                ->push(['id' => 'hs-old'], 201)
                ->push(['message' => 'internal error'], 500)
                ->push(['id' => 'hs-new'], 201),
        ]);
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'jane.new@example.com']);
        $job = $this->lastDispatchedJob();

        try {
            $this->runAsWorker($job);
            $this->fail('The first attempt should have failed creating the new contact.');
        } catch (HubspotApiException) {
        }

        $this->runAsWorker($job);

        $this->assertSame('hs-new', HubspotContact::contactIdForUser($user->id));
        $this->assertContains(['registry_development' => ''], $this->sentProperties('PATCH', self::CONTACTS . '/hs-old'));
    }

    public function test_a_shared_model_whose_email_is_unchanged_updates_its_contact_in_place(): void
    {
        $this->fakeHubspot();
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'pending']);

        $user->update(['registry_development' => 'approved']);
        $this->runAsWorker($this->lastDispatchedJob());

        $this->assertCount(1, $this->sentProperties('POST', self::CONTACTS), 'Only the original create');
        $this->assertEquals(
            [['email' => 'jane@example.com', 'registry_development' => 'approved']],
            $this->sentProperties('PATCH', self::CONTACTS . '/hs-old'),
        );
        $this->assertSame('hs-old', HubspotContact::contactIdForUser($user->id));
    }

    /**
     * HubSpot compares emails without regard to case, so this is the same
     * contact and there is nowhere to move to.
     */
    public function test_a_change_only_to_the_case_of_the_email_updates_its_contact_in_place(): void
    {
        $this->fakeHubspot([
            self::CONTACTS . '/JANE%40example.com*' => Http::response(['id' => 'hs-old'], 200),
        ]);
        $user = $this->linkedUser(['email' => 'jane@example.com', 'registry_development' => 'approved']);

        $user->update(['email' => 'JANE@example.com']);
        $this->runAsWorker($this->lastDispatchedJob());

        $this->assertCount(1, $this->sentProperties('POST', self::CONTACTS), 'Only the original create');
        $this->assertNotContains(['registry_development' => ''], $this->sentProperties('PATCH', self::CONTACTS . '/hs-old'));
        $this->assertSame('hs-old', HubspotContact::contactIdForUser($user->id));
    }
}
