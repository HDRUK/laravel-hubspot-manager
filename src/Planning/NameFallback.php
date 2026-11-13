<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * The names a moving model takes to its new contact.
 *
 * Its own names win, so a name changed together with the email arrives as
 * changed. The contact it left supplies only a name the model does not hold
 * itself. Nothing but names is ever taken from that contact: it also holds
 * other consumers' properties, which must not follow this one.
 *
 * Pure, so which names are missing, and so whether the old contact needs
 * reading at all, is known from the job's own snapshot.
 */
final class NameFallback
{
    private const NAMES = ['firstname', 'lastname'];

    /**
     * The names these properties do not hold: absent, null, or blank.
     *
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    public static function missingFrom(array $properties): array
    {
        return array_values(array_filter(
            self::NAMES,
            fn (string $name) => !self::isPresent($properties[$name] ?? null),
        ));
    }

    /**
     * The properties, with any name they do not hold taken from the old
     * contact's properties where it holds one.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $oldContactProperties
     * @return array<string, mixed>
     */
    public static function fill(array $properties, array $oldContactProperties): array
    {
        foreach (self::missingFrom($properties) as $name) {
            $fallback = $oldContactProperties[$name] ?? null;

            if (self::isPresent($fallback)) {
                $properties[$name] = $fallback;
            } else {
                unset($properties[$name]);
            }
        }

        return $properties;
    }

    private static function isPresent(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
