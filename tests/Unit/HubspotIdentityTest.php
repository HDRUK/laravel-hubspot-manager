<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Contracts\HubspotContactable;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;

/**
 * The identity is what a later lookup will use to find a contact this
 * package has not linked before. An unusable identity must resolve to null
 * rather than to an empty value, so these cases pin the boundary.
 */
class HubspotIdentityTest extends TestCase
{
    private function model(array $properties): object
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model implements HubspotContactable {
            use HasHubspotContact;

            public array $fakeProperties = [];

            public function toHubspotProperties(): array
            {
                return $this->fakeProperties;
            }
        };

        $model->fakeProperties = $properties;

        return $model;
    }

    public function test_contacts_are_identified_by_email_by_default(): void
    {
        $this->assertSame('email', $this->model([])->hubspotIdentityProperty());
    }

    public function test_the_identity_property_is_configurable(): void
    {
        config(['hubspotmanager.default.identity_property' => 'hs_object_id']);

        $model = $this->model(['hs_object_id' => '12345', 'email' => 'jane@example.com']);

        $this->assertSame('hs_object_id', $model->hubspotIdentityProperty());
        $this->assertSame('12345', $model->hubspotIdentityValue());
    }

    public function test_the_value_is_read_from_the_mapped_properties(): void
    {
        $this->assertSame(
            'jane@example.com',
            $this->model(['email' => 'jane@example.com'])->hubspotIdentityValue()
        );
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame(
            'jane@example.com',
            $this->model(['email' => "  jane@example.com\n"])->hubspotIdentityValue()
        );
    }

    public function test_a_missing_property_has_no_identity(): void
    {
        $this->assertNull($this->model(['firstname' => 'Jane'])->hubspotIdentityValue());
    }

    public function test_a_null_property_has_no_identity(): void
    {
        $this->assertNull($this->model(['email' => null])->hubspotIdentityValue());
    }

    public function test_an_empty_property_has_no_identity(): void
    {
        $this->assertNull($this->model(['email' => ''])->hubspotIdentityValue());
    }

    public function test_a_whitespace_only_property_has_no_identity(): void
    {
        $this->assertNull($this->model(['email' => "   \t "])->hubspotIdentityValue());
    }

    public function test_a_non_scalar_property_has_no_identity(): void
    {
        $this->assertNull($this->model(['email' => ['jane@example.com']])->hubspotIdentityValue());
    }

    public function test_a_numeric_identity_is_returned_as_a_string(): void
    {
        config(['hubspotmanager.default.identity_property' => 'hs_object_id']);

        $this->assertSame('12345', $this->model(['hs_object_id' => 12345])->hubspotIdentityValue());
    }

    public function test_a_model_using_the_trait_satisfies_the_contract(): void
    {
        $this->assertInstanceOf(HubspotContactable::class, $this->model([]));
    }
}
