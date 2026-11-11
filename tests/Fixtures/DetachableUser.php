<?php

namespace Hdruk\LaravelHubspotManager\Tests\Fixtures;

use Hdruk\LaravelHubspotManager\Support\OnHubspotDelete;

/**
 * A model that shares its HubSpot contact with other systems, so deleting it
 * clears only the properties it owns and leaves the contact in place.
 */
class DetachableUser extends SyncableUser
{
    public function onHubspotDelete(): OnHubspotDelete
    {
        return OnHubspotDelete::clearProperties('registry_development', 'registry_persona');
    }
}
