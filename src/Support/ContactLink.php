<?php

namespace Hdruk\LaravelHubspotManager\Support;

/**
 * A model's live link to its HubSpot contact, as stored.
 *
 * The email is the one the link was made for. It is null for a link made
 * before emails were recorded, where it is unknown, not absent: the
 * contact still has an email, but nothing local says which.
 */
final class ContactLink
{
    public function __construct(
        public readonly string $contactId,
        public readonly ?ContactEmail $email,
    ) {}
}
