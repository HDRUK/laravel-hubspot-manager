<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
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
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));

        $this->assertSame('hs-001', HubspotContact::contactIdForUser(1));
    }

    public function test_models_are_linked_independently(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::linkToUser(2, 'hs-002', ContactEmail::parse('jane@example.com'));

        $this->assertSame('hs-001', HubspotContact::contactIdForUser(1));
        $this->assertSame('hs-002', HubspotContact::contactIdForUser(2));
    }

    public function test_relinking_replaces_the_contact_without_adding_a_row(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::linkToUser(1, 'hs-002', ContactEmail::parse('jane@example.com'));

        $this->assertSame('hs-002', HubspotContact::contactIdForUser(1));
        $this->assertSame(1, HubspotContact::where('user_id', 1)->count());
    }

    public function test_an_unlinked_model_no_longer_resolves(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::unlink(1);

        $this->assertNull(HubspotContact::contactIdForUser(1));
    }

    public function test_unlinking_keeps_the_contact_id_visible(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::unlink(1);

        $row = HubspotContact::where('user_id', 1)->firstOrFail();
        $this->assertSame('hs-001', $row->hubspot_contact_id);
        $this->assertNotNull($row->unlinked_at);
    }

    public function test_relinking_after_an_unlink_makes_the_model_live_again(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::unlink(1);
        HubspotContact::linkToUser(1, 'hs-002', ContactEmail::parse('jane@example.com'));

        $this->assertSame('hs-002', HubspotContact::contactIdForUser(1));
        $this->assertNull(HubspotContact::where('user_id', 1)->firstOrFail()->unlinked_at);
        $this->assertSame(1, HubspotContact::where('user_id', 1)->count());
    }

    public function test_unlinking_an_unknown_model_is_a_no_op(): void
    {
        HubspotContact::unlink(1);

        $this->assertSame(0, HubspotContact::count());
    }

    public function test_a_link_records_the_email_it_was_made_for(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('Jane@Example.com'));

        $link = HubspotContact::linkForUser(1);

        $this->assertNotNull($link);
        $this->assertSame('hs-001', $link->contactId);
        $this->assertNotNull($link->email);
        $this->assertTrue($link->email->equals(ContactEmail::parse('jane@example.com')));
    }

    public function test_relinking_replaces_the_email(): void
    {
        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::linkToUser(1, 'hs-002', ContactEmail::parse('jane.new@example.com'));

        $link = HubspotContact::linkForUser(1);

        $this->assertNotNull($link);
        $this->assertSame('hs-002', $link->contactId);
        $this->assertSame('jane.new@example.com', $link->email?->value);
    }

    public function test_an_unknown_or_unlinked_model_has_no_link(): void
    {
        $this->assertNull(HubspotContact::linkForUser(1));

        HubspotContact::linkToUser(1, 'hs-001', ContactEmail::parse('jane@example.com'));
        HubspotContact::unlink(1);

        $this->assertNull(HubspotContact::linkForUser(1));
    }

    /**
     * Links made before emails were recorded hold none. The link is still
     * live, but which email it was made for is unknown.
     */
    public function test_a_link_made_before_emails_were_recorded_has_no_email(): void
    {
        HubspotContact::create(['user_id' => 1, 'hubspot_contact_id' => 'hs-001']);

        $link = HubspotContact::linkForUser(1);

        $this->assertNotNull($link);
        $this->assertSame('hs-001', $link->contactId);
        $this->assertNull($link->email);
    }
}
