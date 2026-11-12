<?php

namespace Hdruk\LaravelHubspotManager\Planning;

use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\ContactLink;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;

/**
 * Decides what a create or update does, from local state alone.
 *
 * Pure: it reads nothing and sends nothing, so every decision is covered by
 * a table of inputs without faking HubSpot.
 */
final class SyncPlanner
{
    public static function plan(ContactEmail $email, HubspotOwnership $ownership, ?ContactLink $link): SyncPlan
    {
        if ($link === null) {
            return new AdoptOrCreate();
        }

        $ownedProperties = $ownership->ownedProperties;

        // An exclusive model's contact is its own, so even a changed email is
        // written to it in place: no other consumer relies on the old one.
        if ($ownedProperties === null) {
            return new UpdateLinked($link->contactId);
        }

        if ($link->email === null) {
            return new LearnLinkedEmail($link->contactId);
        }

        return $link->email->equals($email)
            ? new UpdateLinked($link->contactId)
            : new MoveContact($link->contactId, $ownedProperties);
    }
}
