<?php

namespace Hdruk\LaravelHubspotManager\Tests\Fixtures;

use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that overrides hubspotSyncEnabled(), as a consuming application
 * does to switch sync with a feature flag rather than the env var.
 *
 * The flag is read from 'testing.hubspot_flag', standing in for an external
 * flag store. Config is reset between tests, so no state leaks.
 *
 * @property int $id
 * @property string $email
 */
class FlaggedUser extends Model
{
    use HasHubspotContact;

    protected $table = 'syncable_users';

    /** @var list<string> */
    protected $fillable = ['email'];

    public $timestamps = false;

    public static function hubspotSyncEnabled(): bool
    {
        return (bool) config('testing.hubspot_flag', false);
    }

    public function hubspotOwnership(): HubspotOwnership
    {
        return HubspotOwnership::owns('registry_development');
    }
}
