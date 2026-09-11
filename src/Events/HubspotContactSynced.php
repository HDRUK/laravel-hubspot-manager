<?php

namespace Hdruk\LaravelHubspotManager\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

class HubspotContactSynced
{
    use SerializesModels;

    public function __construct(
        public readonly Model $model,
        public readonly string $action,
        public readonly int $statusCode,
        public readonly ?string $hubspotContactId,
        public readonly ?string $resolvedVia = null,
    ) {}
}
