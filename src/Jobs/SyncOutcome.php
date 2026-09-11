<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

/**
 * The result of one sync: which contact it touched, what HubSpot answered,
 * and how the contact was arrived at.
 *
 * The last of those is what makes the sync log answer questions it could
 * not before — how often a contact is adopted rather than created, and
 * whether conflicts are being hit — which is how you tell whether the
 * lookup is doing its job in production.
 */
final class SyncOutcome
{
    /**
     * No HTTP call completed, so there is no status to record. Distinct from
     * any real status: it means the sync failed before or outside a request.
     */
    public const NO_HTTP_STATUS = 0;

    /** The model already had a stored link. */
    public const VIA_LINK = 'link';

    /** HubSpot already held a contact with this identity. */
    public const VIA_LOOKUP = 'lookup';

    /** HubSpot rejected the create as a duplicate and named the contact. */
    public const VIA_CONFLICT = 'conflict';

    /** No existing contact, so one was created. */
    public const VIA_CREATED = 'created';

    public function __construct(
        public readonly ?string $contactId,
        public readonly int $statusCode,
        public readonly ?string $resolvedVia = null,
    ) {}
}
