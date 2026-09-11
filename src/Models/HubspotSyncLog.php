<?php

namespace Hdruk\LaravelHubspotManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HubspotSyncLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'status_code',
        'hubspot_contact_id',
        'error',
    ];

    protected $casts = [
        'status_code' => 'integer',
    ];

    protected $table = 'hubspot_sync_logs';

    /**
     * Query-level counterpart to wasSuccessful(). Grouped so that callers
     * combining this with orWhere() cannot change its meaning.
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