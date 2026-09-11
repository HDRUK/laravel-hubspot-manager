<?php

namespace Hdruk\LaravelHubspotManager\Exceptions;

use RuntimeException;

class HubspotConfigurationException extends RuntimeException
{
    public static function notContactable(string $class): self
    {
        return new self(
            "Laravel HubSpot Manager: model [{$class}] cannot be synced because it does not "
            . "define toHubspotProperties(). Add the HasHubspotContact trait to the model."
        );
    }

    public static function missingKey(string $key): self
    {
        return new self(
            "Laravel HubSpot Manager: missing required config value [{$key}]. "
            . "Publish the config with `php artisan vendor:publish` and set the corresponding env variables."
        );
    }
}
