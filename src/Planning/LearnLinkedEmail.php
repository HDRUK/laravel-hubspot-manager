<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * The model shares its contact, but its link predates emails being recorded,
 * so whether the email has changed cannot be told locally. Ask HubSpot for
 * the linked contact's email, record it, and plan again.
 */
final class LearnLinkedEmail implements SyncPlan
{
    public function __construct(
        public readonly string $contactId,
    ) {}
}
