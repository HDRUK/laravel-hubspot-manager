<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * Update the linked contact in place: the model has it to itself, or shares
 * it and still holds the email it was linked for.
 */
final class UpdateLinked implements SyncPlan
{
    public function __construct(
        public readonly string $contactId,
    ) {}
}
