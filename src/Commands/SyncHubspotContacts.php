<?php

namespace Hdruk\LaravelHubspotManager\Commands;

use Illuminate\Console\Command;
use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Jobs\SyncContactToHubspot;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;

class SyncHubspotContacts extends Command
{
    protected $signature = 'hubspot:sync {--user= : Sync a specific model by primary key}';

    protected $description = 'Dispatch HubSpot contact sync jobs for one or all users';

    public function handle(): int
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = config('hubspotmanager.default.models.users');

        // Checked once, before any job is built, so a misconfigured model
        // fails the command rather than throwing part way through the loop.
        if (!SyncContactToHubspot::canSync($modelClass)) {
            $this->error(HubspotConfigurationException::missingTrait($modelClass)->getMessage());
            return Command::FAILURE;
        }

        // Guaranteed by canSync() above; the analyser cannot see a method
        // that consuming models supply via the trait.
        // @phpstan-ignore staticMethod.notFound
        if (!$modelClass::hubspotSyncEnabled()) {
            $this->warn("HubSpot sync is disabled for {$modelClass}.");
            return Command::SUCCESS;
        }

        $option = $this->option('user');
        $id = is_int($option) || is_string($option) ? (string) $option : '';

        if ($id !== '') {
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
