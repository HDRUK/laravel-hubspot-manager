<?php

namespace Hdruk\LaravelHubspotManager\Exceptions;

use RuntimeException;

class HubspotConfigurationException extends RuntimeException
{
    public static function missingKey(string $key): self
    {
        return new self(
            "Laravel HubSpot Manager: missing required config value [{$key}]. "
            . "Publish the config with `php artisan vendor:publish` and set the corresponding env variables."
        );
    }
}
