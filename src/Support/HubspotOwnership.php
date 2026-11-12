<?php

namespace Hdruk\LaravelHubspotManager\Support;

use InvalidArgumentException;

/**
 * The properties a model owns on its HubSpot contact.
 *
 * A HubSpot contact is one per email, and may be shared by several systems
 * (or several environments of one system) that each hold their own model
 * for that person, and may predate all of them. A model never knows which
 * others share its contact, so it acts only on what it owns:
 *
 * - deleting the model blanks the properties it owns, and leaves the
 *   contact, and everything else on it, in place.
 * - changing its email blanks them on the contact it leaves, and takes them
 *   to the contact for the new email.
 *
 * Declared once, so that every behaviour that depends on it reads the same
 * list of owned properties. Owning no properties cannot be expressed: the
 * signature requires at least one name.
 */
final class HubspotOwnership
{
    /**
     * @param  list<non-empty-string>  $ownedProperties
     */
    private function __construct(
        public readonly array $ownedProperties,
    ) {}

    /**
     * Checked here, in the request that declared them, so that a blank name
     * fails at the call site rather than as a rejected request in the worker.
     */
    public static function owns(string $property, string ...$more): self
    {
        $names = [$property, ...array_values($more)];

        foreach ($names as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException(
                    'Laravel HubSpot Manager: a HubSpot property a model owns must have a name.'
                );
            }
        }

        /** @var list<non-empty-string> $names */
        return new self($names);
    }
}
