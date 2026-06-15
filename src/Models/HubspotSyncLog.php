<?php

namespace Hdruk\LaravelHubspotManager\Models;

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

    public function wasSuccessful(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }
}