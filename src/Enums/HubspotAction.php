<?php

namespace Hdruk\LaravelHubspotManager\Enums;

/**
 * What a sync is doing to a HubSpot contact.
 *
 * A backed enum rather than a string so the match in SyncContactToHubspot is
 * provably exhaustive, and an action that is not one of these cannot be
 * dispatched at all — where before it reached a queue worker and threw
 * there.
 *
 * The backing values are what the sync log stores and what the
 * HubspotContactSynced event reports, so they are part of the package's
 * public surface and must not change.
 */
enum HubspotAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
