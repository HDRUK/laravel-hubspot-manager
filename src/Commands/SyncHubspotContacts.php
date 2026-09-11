<?php

namespace Hdruk\LaravelHubspotManager\Commands;

use Illuminate\Console\Command;
use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;

class SyncHubspotContacts extends Command
{
    protected $signature = 'hubspot:sync {--user= : Sync a specific model by primary key}';

    protected $description = 'Dispatch HubSpot contact sync jobs for one or all users';

    public function handle(): int
    {
        if (!config('hubspotmanager.default.enabled', true)) {
            $this->warn('HubSpot sync is disabled (HUBSPOT_INTEGRATION_ENABLED=false).');
            return Command::SUCCESS;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = config('hubspotmanager.default.models.users');

        $id = $this->option('user');

        if (is_string($id) && $id !== '') {
            SyncContactToHubspot::dispatch($modelClass::query()->findOrFail($id), HubspotAction::Create);
            $this->info("Dispatched sync for {$modelClass} #{$id}.");
            return Command::SUCCESS;
        }

        $count = 0;

        $modelClass::chunk(200, function ($models) use (&$count) {
            foreach ($models as $model) {
                SyncContactToHubspot::dispatch($model, HubspotAction::Create);
                $count++;
            }
        });

        $this->info("Dispatched sync for {$count} " . class_basename($modelClass) . ' records.');

        return Command::SUCCESS;
    }
}
