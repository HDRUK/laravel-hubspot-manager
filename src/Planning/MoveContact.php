<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * The model shares its contact and its email has changed. Leave the contact
 * it is linked to, blanking the properties it owns there, and move to the
 * contact for the new email.
 *
 * Only a shared model can move, so the owned properties are carried here
 * and are never empty.
 */
final class MoveContact implements SyncPlan
{
    /**
     * @param  list<non-empty-string>  $ownedProperties
     */
    public function __construct(
        public readonly string $fromContactId,
        public readonly array $ownedProperties,
    ) {}
}
