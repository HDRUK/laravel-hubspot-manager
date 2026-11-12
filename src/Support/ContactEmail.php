<?php

namespace Hdruk\LaravelHubspotManager\Support;

use InvalidArgumentException;

/**
 * An email as HubSpot compares it: trimmed and lower case.
 *
 * HubSpot keys a contact on its email and treats addresses differing only in
 * case as the same contact. Holding the normalised form in a type, rather
 * than comparing strings, means two emails can only be compared once both
 * have been normalised. A blank email cannot be constructed.
 *
 * A factory prefixed "try" returns null for a value that is not an email; one
 * without it throws. The prefix alone says which can return null.
 */
final class ContactEmail
{
    /**
     * @param  non-empty-string  $value
     */
    private function __construct(
        public readonly string $value,
    ) {}

    public static function parse(string $email): self
    {
        return self::tryParse($email)
            ?? throw new InvalidArgumentException('Laravel HubSpot Manager: an email cannot be blank.');
    }

    /**
     * The email, or null when the value is absent, not scalar, or blank once
     * trimmed.
     */
    public static function tryParse(mixed $email): ?self
    {
        if (!is_scalar($email)) {
            return null;
        }

        $normalised = mb_strtolower(trim((string) $email));

        return $normalised === '' ? null : new self($normalised);
    }

    /**
     * The email in a model's HubSpot properties.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function tryParseProperties(array $properties): ?self
    {
        return self::tryParse($properties['email'] ?? null);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
