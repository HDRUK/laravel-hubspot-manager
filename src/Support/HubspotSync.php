<?php

namespace Hdruk\LaravelHubspotManager\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether HubSpot sync is on, for a model class.
 *
 * A model may override hubspotSyncEnabled(), such as to switch sync with a
 * feature flag. A model that uses the trait without overriding it, or that
 * does not use the trait at all, follows the env var.
 */
final class HubspotSync
{
    /**
     * HUBSPOT_INTEGRATION_ENABLED. Off when it is missing.
     */
    public static function enabledByDefault(): bool
    {
        return (bool) config('hubspotmanager.default.enabled', false);
    }

    /**
     * Asked again each time, never remembered: a flag may change while a
     * long-lived queue worker is running.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function enabledFor(string $modelClass): bool
    {
        return method_exists($modelClass, 'hubspotSyncEnabled')
            ? (bool) $modelClass::hubspotSyncEnabled()
            : self::enabledByDefault();
    }
}
