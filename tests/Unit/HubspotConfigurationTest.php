<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\TestCase;

class HubspotConfigurationTest extends TestCase
{
    public function test_throws_when_base_url_is_missing(): void
    {
        config([
            'hubspotmanager.default.access.hubspot_base_url' => null,
            'hubspotmanager.default.access.hubspot_api_key' => 'test-key',
        ]);

        $this->expectException(HubspotConfigurationException::class);
        $this->expectExceptionMessageMatches('/hubspotmanager\.default\.access\.hubspot_base_url/');

        new Hubspot();
    }

    public function test_throws_when_api_key_is_missing(): void
    {
        config([
            'hubspotmanager.default.access.hubspot_base_url' => 'https://api.hubapi.com',
            'hubspotmanager.default.access.hubspot_api_key' => null,
        ]);

        $this->expectException(HubspotConfigurationException::class);
        $this->expectExceptionMessageMatches('/hubspotmanager\.default\.access\.hubspot_api_key/');

        new Hubspot();
    }

    public function test_instantiates_successfully_with_valid_configuration(): void
    {
        config([
            'hubspotmanager.default.product_name' => 'Test Product',
            'hubspotmanager.default.access.hubspot_base_url' => 'https://api.hubapi.com',
            'hubspotmanager.default.access.hubspot_api_key' => 'test-key',
        ]);

        $hubspot = new Hubspot();

        $this->assertInstanceOf(Hubspot::class, $hubspot);
    }
}
