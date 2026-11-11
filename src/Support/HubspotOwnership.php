<?php

namespace Hdruk\LaravelHubspotManager\Support;

use InvalidArgumentException;

/**
 * Whether a model has its HubSpot contact to itself, or shares it with other
 * consumers and owns only some of its properties.
 *
 * A HubSpot contact is one per email, and can be shared by several systems
 * (or several environments of one system) that each hold their own model
 * for that person. What a model may do to its contact follows from which of
 * the two it is:
 *
 * - exclusive: deleting the model archives the contact.
 * - shared: deleting the model blanks only the properties it owns, and
 *   leaves the contact, and everything other consumers wrote, in place.
 *
 * Declared once, so that every behaviour that depends on it reads the same
 * list of owned properties.
 *
 * Built only through the named constructors, so every value is one of the
 * two choices. A shared model owning no properties cannot be expressed at
 * all: the signature requires at least one name.
 */
final class HubspotOwnership
{
    /**
     * @param  list<non-empty-string>|null  $ownedProperties  null when the contact is exclusive
     */
    private function __construct(
        public readonly ?array $ownedProperties,
    ) {}

    /**
     * The model is the only consumer of its contact.
     */
    public static function exclusive(): self
    {
        return new self(null);
    }

    /**
     * The model shares its contact with other consumers, and owns only these
     * properties on it.
     *
     * Checked here, in the request that declared them, so that a blank name
     * fails at the call site rather than as a rejected request in the worker.
     */
    public static function shared(string $property, string ...$more): self
    {
        $names = [$property, ...array_values($more)];

        foreach ($names as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException(
                    'Laravel HubSpot Manager: a HubSpot property owned by a shared model must have a name.'
                );
            }
        }

        /** @var list<non-empty-string> $names */
        return new self($names);
    }
}
