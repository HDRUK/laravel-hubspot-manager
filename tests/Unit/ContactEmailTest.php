<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactEmailTest extends TestCase
{
    /**
     * HubSpot compares emails without regard to case or surrounding space,
     * so two addresses that differ only in those are one contact.
     */
    public function test_addresses_differing_only_in_case_and_whitespace_are_equal(): void
    {
        $this->assertTrue(
            ContactEmail::parse("  Jane@Example.COM\n")->equals(ContactEmail::parse('jane@example.com'))
        );
    }

    public function test_different_addresses_are_not_equal(): void
    {
        $this->assertFalse(
            ContactEmail::parse('jane@example.com')->equals(ContactEmail::parse('jane.new@example.com'))
        );
    }

    public function test_the_value_is_normalised(): void
    {
        $this->assertSame('jane@example.com', ContactEmail::parse('  Jane@Example.COM ')->value);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableValues(): array
    {
        return [
            'null'       => [null],
            'empty'      => [''],
            'whitespace' => ["  \t "],
            'non scalar' => [['jane@example.com']],
        ];
    }

    #[DataProvider('unusableValues')]
    public function test_an_unusable_value_is_no_email(mixed $value): void
    {
        $this->assertNull(ContactEmail::tryParse($value));
    }

    public function test_a_blank_email_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContactEmail::parse(' ');
    }

    public function test_the_email_is_read_from_a_models_properties(): void
    {
        $email = ContactEmail::tryParseProperties(['firstname' => 'Jane', 'email' => ' jane@example.com']);

        $this->assertNotNull($email);
        $this->assertSame('jane@example.com', $email->value);
        $this->assertNull(ContactEmail::tryParseProperties(['firstname' => 'Jane']));
    }
}
