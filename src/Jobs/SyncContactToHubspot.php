<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Hdruk\LaravelHubspotManager\Contracts\HubspotContactable;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use InvalidArgumentException;

class SyncContactToHubspot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public const ACTIONS = ['create', 'update', 'delete'];

    public function __construct(
        public readonly Model&HubspotContactable $model,
        public readonly string $action,
    ) {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown HubSpot sync action [{$action}].");
        }
    }

    /**
     * @return list<int>
     */
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
                default  => throw new InvalidArgumentException("Unknown HubSpot sync action [{$this->action}]."),
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

    /**
     * @return array{0: string|null, 1: int}
     */
    private function handleCreate(Hubspot $hubspot): array
    {
        $existingContactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($existingContactId !== null) {
            $hubspot->updateContact($existingContactId, $this->properties());
            return [$existingContactId, 200];
        }

        return $this->adoptOrCreate($hubspot);
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function handleUpdate(Hubspot $hubspot): array
    {
        $contactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($contactId === null) {
            return $this->adoptOrCreate($hubspot);
        }

        $hubspot->updateContact($contactId, $this->properties());

        return [$contactId, 200];
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function handleDelete(Hubspot $hubspot): array
    {
        $contactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($contactId === null) {
            return [null, 204];
        }

        $hubspot->deleteContact($contactId);
        HubspotContact::archive($this->model->getKey());

        return [$contactId, 204];
    }

    /**
     * No stored link, so ask HubSpot whether it already holds this contact
     * before creating a second one. A contact can predate this package, or
     * predate the model, and creating alongside it would leave two records
     * for one person with no way to tell which is current.
     *
     * @return array{0: string|null, 1: int}
     */
    private function adoptOrCreate(Hubspot $hubspot): array
    {
        $contactId = $this->findExistingContactId($hubspot);

        if ($contactId === null) {
            return [$this->createAndLink($hubspot), 201];
        }

        $hubspot->updateContact($contactId, $this->properties());
        HubspotContact::link($this->model->getKey(), $contactId);

        return [$contactId, 200];
    }

    /**
     * A model with no identity value is never looked up. Asking HubSpot to
     * match an empty value would match an arbitrary contact, and this model
     * would adopt a stranger's record.
     */
    private function findExistingContactId(Hubspot $hubspot): ?string
    {
        $identity = $this->model->hubspotIdentityValue();

        if ($identity === null) {
            return null;
        }

        return $hubspot->findContactIdBy($this->model->hubspotIdentityProperty(), $identity);
    }

    /**
     * Linked only after HubSpot has confirmed the id, so a failed create
     * leaves the model unlinked and the next sync retries it.
     */
    private function createAndLink(Hubspot $hubspot): ?string
    {
        $response = $hubspot->createContact($this->properties());
        $contactId = $response['id'] ?? null;

        if ($contactId !== null) {
            HubspotContact::link($this->model->getKey(), (string) $contactId);
        }

        return $contactId;
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $properties = $this->model->toHubspotProperties();

        if ($productName = config('hubspotmanager.default.product_name')) {
            $properties['product_name'] = $productName;
        }

        return $properties;
    }
}
