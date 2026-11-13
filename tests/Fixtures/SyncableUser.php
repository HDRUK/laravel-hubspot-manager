<?php

namespace Hdruk\LaravelHubspotManager\Tests\Fixtures;

use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted, named model for tests that need the job to survive the queue.
 *
 * The anonymous models used elsewhere cannot: a queued job stores a model as
 * its class name and key, and an anonymous class has no name to restore from.
 *
 * @property int $id
 * @property string $email
 */
class SyncableUser extends Model
{
    use HasHubspotContact;

    protected $table = 'syncable_users';

    /** @var list<string> */
    protected $fillable = ['email'];

    public $timestamps = false;

    public function hubspotOwnership(): HubspotOwnership
    {
        return HubspotOwnership::owns('registry_development', 'registry_persona');
    }
}
