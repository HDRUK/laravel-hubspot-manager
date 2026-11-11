<?php

namespace Hdruk\LaravelHubspotManager\Support;

use InvalidArgumentException;

/**
 * What deleting a model does to its HubSpot contact.
 *
 * A HubSpot contact is one per email, and can be shared by several systems
 * (or several environments of one system) that each hold their own model
 * for that person. Deleting one model therefore does not mean the person
 * is gone, and a model declares here whether its delete should archive the
 * whole contact or only clear the properties it owns.
 *
 * Built only through the named constructors, so every value is one of the
 * two choices and the destructive one is always spelled out where it is
 * made. Clearing no properties cannot be expressed at all: the signature
 * requires at least one name.
 */
final class OnHubspotDelete
{
    /**
     * @param  list<non-empty-string>|null  $propertiesToClear  null archives the contact
     */
    private function __construct(
        public readonly ?array $propertiesToClear,
    ) {}

    /**
     * Archive the HubSpot contact, moving it to the recycling bin for every
     * system that shares it.
     */
    public static function archiveContact(): self
    {
        return new self(null);
    }

    /**
     * Blank these properties on the HubSpot contact and leave the contact,
     * and every other property on it, in place.
     *
     * Checked here, in the request that declared them, so that a blank name
     * fails at the call site rather than as a rejected request in the worker.
     */
    public static function clearProperties(string $property, string ...$more): self
    {
        $names = [$property, ...array_values($more)];

        foreach ($names as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException(
                    'Laravel HubSpot Manager: a HubSpot property to clear on delete must have a name.'
                );
            }
        }

        /** @var list<non-empty-string> $names */
        return new self($names);
    }
}
