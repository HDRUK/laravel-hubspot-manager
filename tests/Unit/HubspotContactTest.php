<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HubspotContactTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/database/migrations');
    }

    public function test_an_unknown_model_resolves_to_null(): void
    {
        $this->assertNull(HubspotContact::contactIdForUser(1));
    }

    public function test_a_linked_model_resolves_to_its_contact(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');

        $this->assertSame('hs-001', HubspotContact::contactIdForUser(1));
    }

    public function test_models_are_linked_independently(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');
        HubspotContact::linkToUser(2, 'hs-002');

        $this->assertSame('hs-001', HubspotContact::contactIdForUser(1));
        $this->assertSame('hs-002', HubspotContact::contactIdForUser(2));
    }

    public function test_relinking_replaces_the_contact_without_adding_a_row(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');
        HubspotContact::linkToUser(1, 'hs-002');

        $this->assertSame('hs-002', HubspotContact::contactIdForUser(1));
        $this->assertSame(1, HubspotContact::where('user_id', 1)->count());
    }

    public function test_an_archived_model_no_longer_resolves(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');
        HubspotContact::archive(1);

        $this->assertNull(HubspotContact::contactIdForUser(1));
    }

    public function test_archiving_keeps_the_contact_id_visible(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');
        HubspotContact::archive(1);

        $row = HubspotContact::where('user_id', 1)->firstOrFail();
        $this->assertSame('hs-001', $row->hubspot_contact_id);
        $this->assertNotNull($row->archived_at);
    }

    public function test_relinking_after_an_archive_makes_the_model_live_again(): void
    {
        HubspotContact::linkToUser(1, 'hs-001');
        HubspotContact::archive(1);
        HubspotContact::linkToUser(1, 'hs-002');

        $this->assertSame('hs-002', HubspotContact::contactIdForUser(1));
        $this->assertNull(HubspotContact::where('user_id', 1)->firstOrFail()->archived_at);
        $this->assertSame(1, HubspotContact::where('user_id', 1)->count());
    }

    public function test_archiving_an_unknown_model_is_a_no_op(): void
    {
        HubspotContact::archive(1);

        $this->assertSame(0, HubspotContact::count());
    }
}
