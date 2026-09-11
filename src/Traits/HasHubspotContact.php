<?php

namespace Hdruk\LaravelHubspotManager\Traits;

use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasHubspotContact
{
    public static function bootHasHubspotContact(): void
    {
        static::created(fn ($model) => SyncContactToHubspot::dispatch($model, 'create'));
        static::updated(fn ($model) => SyncContactToHubspot::dispatch($model, 'update'));
        static::deleted(fn ($model) => SyncContactToHubspot::dispatch($model, 'delete'));
    }

    /**
     * Map model attributes to HubSpot contact properties.
     * Override this method in your model to customise the mapping.
     *
     * @return array<string, mixed>
     */
    public function toHubspotProperties(): array
    {
        return array_filter([
            'email'     => $this->email ?? null,
            'firstname' => $this->first_name ?? $this->firstname ?? null,
            'lastname'  => $this->last_name ?? $this->lastname ?? null,
        ]);
    }

    /**
     * The HubSpot property that uniquely identifies this contact. Override
     * on the model if it is identified by something other than the
     * configured default.
     */
    public function hubspotIdentityProperty(): string
    {
        return config('hubspotmanager.default.identity_property', 'email');
    }

    /**
     * Read from the mapped properties rather than the model's attributes,
     * because the identity is named by its HubSpot property, which need not
     * match the local column.
     *
     * Anything that is absent, non-scalar or blank once trimmed resolves to
     * null, so that a model with no usable identity is never looked up with
     * an empty value.
     */
    public function hubspotIdentityValue(): ?string
    {
        $value = $this->toHubspotProperties()[$this->hubspotIdentityProperty()] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return HasMany<HubspotSyncLog, $this>
     */
    public function hubspotSyncLogs(): HasMany
    {
        return $this->hasMany(HubspotSyncLog::class, 'user_id');
    }
}
