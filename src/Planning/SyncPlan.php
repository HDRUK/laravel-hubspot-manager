<?php

namespace Hdruk\LaravelHubspotManager\Planning;

/**
 * What a create or update will do to HubSpot, decided before anything is
 * sent.
 *
 * Sealed: these are the only plans, so a match over them that misses one is
 * reported by the analyser rather than failing in a worker.
 *
 * @phpstan-sealed AdoptOrCreate|UpdateLinked|MoveContact|LearnLinkedEmail
 */
interface SyncPlan
{
}
