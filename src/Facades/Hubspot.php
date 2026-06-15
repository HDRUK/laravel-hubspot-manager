<?php

namespace Hdruk\LaravelHubspotManager\Facades;

use Illuminate\Support\Facades\Facade;

class Hubspot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hdruk\LaravelHubspotManager\Services\Hubspot::class;
    }
}
