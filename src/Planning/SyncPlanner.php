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

        if ($link->email === null) {
            return new LearnLinkedEmail($link->contactId);
        }

        // Others may rely on the email the contact holds, so a changed email
        // is never written to it: the model moves instead.
        return $link->email->equals($email)
            ? new UpdateLinked($link->contactId)
            : new MoveContact($link->contactId, $ownership->ownedProperties);
    }
}
