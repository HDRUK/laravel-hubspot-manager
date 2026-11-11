<?php

namespace Hdruk\LaravelHubspotManager\Events;

/**
 * Fired after every sync attempt, successful or not.
 *
 * Carries the synced model's key rather than the model, so that deleted
 * models can be handled.
 */
class HubspotContactSynced
{
    public function __construct(
        public readonly int|string $userId,
        public readonly string $action,
        public readonly int $statusCode,
        public readonly ?string $hubspotContactId,
        public readonly ?string $resolvedVia = null,
    ) {}
}
