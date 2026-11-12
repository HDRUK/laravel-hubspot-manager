<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * The model's email has changed. Leave the contact it is linked to, blanking
 * the properties it owns there, and move to the contact for the new email.
 *
 * Every model owns at least one property, so the list carried here is never
 * empty.
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
