<?php

namespace Hdruk\LaravelHubspotManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Hdruk\LaravelHubspotManager\Contracts\HubspotContactable;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
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

        /** @var class-string<\Illuminate\Database\Eloquent\Model&\Hdruk\LaravelHubspotManager\Contracts\HubspotContactable> $modelClass */
        $modelClass = config('hubspotmanager.default.models.users');

        $id = $this->option('user');

        if (is_string($id) && $id !== '') {
            $this->dispatchFor($modelClass::query()->findOrFail($id));
            $this->info("Dispatched sync for {$modelClass} #{$id}.");
            return Command::SUCCESS;
        }

        $count = 0;

        $modelClass::chunk(200, function ($models) use (&$count) {
            foreach ($models as $model) {
                $this->dispatchFor($model);
                $count++;
            }
        });

        $this->info("Dispatched sync for {$count} " . class_basename($modelClass) . ' records.');

        return Command::SUCCESS;
    }

    /**
     * The configured model is only known to be an Eloquent model, so the
     * contract is checked here rather than letting the job constructor fail
     * with a TypeError once the job is already on the queue.
     */
    private function dispatchFor(Model $model): void
    {
        if (!$model instanceof HubspotContactable) {
            throw HubspotConfigurationException::notContactable($model::class);
        }

        SyncContactToHubspot::dispatch($model, 'create');
    }
}
