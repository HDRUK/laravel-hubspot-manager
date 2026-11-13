<?php

namespace Hdruk\LaravelHubspotManager\Traits;

use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasHubspotContact
{
    public static function bootHasHubspotContact(): void
    {
        static::created(fn ($model) => SyncContactToHubspot::dispatch($model, HubspotAction::Create));
        static::updated(function ($model) {
            if ($model->hubspotPropertiesChanged()) {
                SyncContactToHubspot::dispatch($model, HubspotAction::Update);
            }
        });
        static::deleted(fn ($model) => SyncContactToHubspot::dispatch($model, HubspotAction::Delete));
    }

    /**
     * Whether the save that fired 'updated' changed what this model sends
     * HubSpot, so a change HubSpot never sees queues no sync.
     *
     * Eloquent fires 'updated' before it syncs the model's original
     * attributes, so the original still holds the row as it was before this
     * save. The previous properties come from a clone holding those
     * attributes; this model is not touched.
     *
     * Properties are compared strictly. A property that is an object, such as
     * a date, is a new instance each call and so always counts as changed.
     */
    private function hubspotPropertiesChanged(): bool
    {
        $before = (clone $this)->setRawAttributes($this->getRawOriginal());

        return $before->toHubspotProperties() !== $this->toHubspotProperties();
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
     * The properties this model owns on its HubSpot contact, which may be
     * shared with other systems. Called whenever a sync is dispatched, and
     * carried with the queued job.
     *
     * Every model must declare it: only the model knows what it owns.
     * Abstract, so a model that omits it fails when its class is loaded, and
     * in static analysis, rather than when a contact is deleted.
     *
     *     return HubspotOwnership::owns('my_system_flag', 'my_system_role');
     */
    abstract public function hubspotOwnership(): HubspotOwnership;

    /**
     * @return HasMany<HubspotSyncLog, $this>
     */
    public function hubspotSyncLogs(): HasMany
    {
        return $this->hasMany(HubspotSyncLog::class, 'user_id');
    }
}
