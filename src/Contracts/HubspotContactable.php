<?php

namespace Hdruk\LaravelHubspotManager\Contracts;

/**
 * A model that can be synced to HubSpot as a contact.
 *
 * Everything the sync job needs from a model is declared here, so a model
 * that cannot be synced is rejected where it is dispatched rather than
 * failing partway through a queued job.
 *
 * HasHubspotContact implements all three, so declaring the interface is
 * normally the only change a model needs.
 */
interface HubspotContactable
{
    /**
     * Model attributes mapped to HubSpot contact properties, keyed by HubSpot
     * property name.
     *
     * @return array<string, mixed>
     */
    public function toHubspotProperties(): array;

    /**
     * The HubSpot property that uniquely identifies this contact, used to
     * find an existing contact that this package has not linked before.
     */
    public function hubspotIdentityProperty(): string;

    /**
     * This model's value for that property, or null when it has none.
     *
     * Null means the contact cannot be looked up by identity, and callers
     * must not fall back to a lookup with an empty value: an empty search
     * term matches arbitrary contacts, which would link this model to
     * someone else's record.
     */
    public function hubspotIdentityValue(): ?string;
}
