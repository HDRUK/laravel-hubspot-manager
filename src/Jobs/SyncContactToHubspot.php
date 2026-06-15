<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;

class SyncContactToHubspot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Model $model,
        public readonly string $action,
    ) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(Hubspot $hubspot): void
    {
        if (!config('hubspotmanager.default.enabled', true)) {
            return;
        }

        $hubspotContactId = null;
        $statusCode = 200;
        $error = null;

        try {
            [$hubspotContactId, $statusCode] = match ($this->action) {
                'create' => $this->handleCreate($hubspot),
                'update' => $this->handleUpdate($hubspot),
                'delete' => $this->handleDelete($hubspot),
            };
        } catch (HubspotApiException $e) {
            $statusCode = $e->statusCode;
            $error = $e->getMessage();
            throw $e;
        } finally {
            HubspotSyncLog::create([
                'user_id'            => $this->model->getKey(),
                'action'             => $this->action,
                'status_code'        => $statusCode,
                'hubspot_contact_id' => $hubspotContactId,
                'error'              => $error,
            ]);

            event(new HubspotContactSynced($this->model, $this->action, $statusCode, $hubspotContactId));
        }
    }

    public function failed(\Throwable $exception): void
    {
        HubspotSyncLog::create([
            'user_id'            => $this->model->getKey(),
            'action'             => $this->action,
            'status_code'        => $exception instanceof HubspotApiException ? $exception->statusCode : 0,
            'hubspot_contact_id' => null,
            'error'              => 'All retries exhausted: ' . $exception->getMessage(),
        ]);
    }

    private function handleCreate(Hubspot $hubspot): array
    {
        $existingContactId = $this->resolveHubspotContactId();

        if ($existingContactId) {
            $hubspot->updateContact($existingContactId, $this->properties());
            return [$existingContactId, 200];
        }

        $response = $hubspot->createContact($this->properties());

        return [$response['id'] ?? null, 201];
    }

    private function handleUpdate(Hubspot $hubspot): array
    {
        $contactId = $this->resolveHubspotContactId();

        if (!$contactId) {
            $response = $hubspot->createContact($this->properties());
            return [$response['id'] ?? null, 201];
        }

        $hubspot->updateContact($contactId, $this->properties());

        return [$contactId, 200];
    }

    private function handleDelete(Hubspot $hubspot): array
    {
        $contactId = $this->resolveHubspotContactId();

        if ($contactId) {
            $hubspot->deleteContact($contactId);
        }

        return [null, 204];
    }

    private function properties(): array
    {
        $properties = $this->model->toHubspotProperties();

        if ($productName = config('hubspotmanager.default.product_name')) {
            $properties['product_name'] = $productName;
        }

        return $properties;
    }

    private function resolveHubspotContactId(): ?string
    {
        return HubspotSyncLog::where('user_id', $this->model->getKey())
            ->whereNotNull('hubspot_contact_id')
            ->latest()
            ->value('hubspot_contact_id');
    }
}
