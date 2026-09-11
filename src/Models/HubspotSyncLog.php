<?php

namespace Hdruk\LaravelHubspotManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $action
 * @property int $status_code
 * @property string|null $hubspot_contact_id
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class HubspotSyncLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'action',
        'status_code',
        'hubspot_contact_id',
        'error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status_code' => 'integer',
    ];

    protected $table = 'hubspot_sync_logs';

    /**
     * Query-level counterpart to wasSuccessful(). Grouped so that callers
     * combining this with orWhere() cannot change its meaning.
     *
     * @param  Builder<HubspotSyncLog>  $query
     * @return Builder<HubspotSyncLog>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->where('status_code', '>=', 200)->where('status_code', '<', 300)
        );
    }

    public function wasSuccessful(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }
}