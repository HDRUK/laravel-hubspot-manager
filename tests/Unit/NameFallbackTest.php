<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Planning\NameFallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When a model moves to another contact, its own names go with it. The
 * names on the contact it left are used only for a name it does not hold.
 */
class NameFallbackTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function missingNames(): array
    {
        return [
            'both held'       => [['firstname' => 'Jane', 'lastname' => 'Doe'], []],
            'neither held'    => [['email' => 'jane@example.com'], ['firstname', 'lastname']],
            'lastname absent' => [['firstname' => 'Jane'], ['lastname']],
            'null'            => [['firstname' => null, 'lastname' => 'Doe'], ['firstname']],
            'blank'           => [['firstname' => ' ', 'lastname' => 'Doe'], ['firstname']],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $expected
     */
    #[DataProvider('missingNames')]
    public function test_the_names_a_model_does_not_hold(array $properties, array $expected): void
    {
        $this->assertSame($expected, NameFallback::missingFrom($properties));
    }

    public function test_a_name_the_model_holds_is_kept(): void
    {
        $this->assertSame(
            ['email' => 'jane.new@example.com', 'firstname' => 'Janet', 'lastname' => 'Doe'],
            NameFallback::fill(
                ['email' => 'jane.new@example.com', 'firstname' => 'Janet', 'lastname' => 'Doe'],
                ['firstname' => 'Jane', 'lastname' => 'Smith'],
            ),
        );
    }

    public function test_a_name_the_model_does_not_hold_is_taken_from_the_old_contact(): void
    {
        $this->assertSame(
            ['email' => 'jane.new@example.com', 'firstname' => 'Jane', 'lastname' => 'Doe'],
            NameFallback::fill(
                ['email' => 'jane.new@example.com', 'firstname' => null],
                ['firstname' => 'Jane', 'lastname' => 'Doe'],
            ),
        );
    }

    public function test_a_blank_name_on_the_old_contact_is_not_carried_over(): void
    {
        $this->assertSame(
            ['email' => 'jane.new@example.com'],
            NameFallback::fill(['email' => 'jane.new@example.com'], ['firstname' => '', 'lastname' => null]),
        );
    }

    /**
     * The old contact holds other consumers' properties too. Only names are
     * ever carried over.
     */
    public function test_nothing_but_names_is_carried_over(): void
    {
        $this->assertSame(
            ['email' => 'jane.new@example.com', 'firstname' => 'Jane'],
            NameFallback::fill(
                ['email' => 'jane.new@example.com'],
                ['firstname' => 'Jane', 'registry_production' => 'approved', 'email' => 'jane@example.com'],
            ),
        );
    }
}
