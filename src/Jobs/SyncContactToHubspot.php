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

        $outcome = new SyncOutcome(null, 200);
        $error = null;

        try {
            $outcome = match ($this->action) {
                'create' => $this->handleCreate($hubspot),
                'update' => $this->handleUpdate($hubspot),
                'delete' => $this->handleDelete($hubspot),
                default  => throw new InvalidArgumentException("Unknown HubSpot sync action [{$this->action}]."),
            };
        } catch (HubspotApiException $e) {
            $outcome = new SyncOutcome(null, $e->statusCode);
            $error = $e->getMessage();
            throw $e;
        } finally {
            HubspotSyncLog::create([
                'user_id'            => $this->model->getKey(),
                'action'             => $this->action,
                'status_code'        => $outcome->statusCode,
                'hubspot_contact_id' => $outcome->contactId,
                'resolved_via'       => $outcome->resolvedVia,
                'error'              => $error,
            ]);

            event(new HubspotContactSynced(
                $this->model,
                $this->action,
                $outcome->statusCode,
                $outcome->contactId,
                $outcome->resolvedVia,
            ));
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

    private function handleCreate(Hubspot $hubspot): SyncOutcome
    {
        $existingContactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($existingContactId !== null) {
            $hubspot->updateContact($existingContactId, $this->properties());
            return new SyncOutcome($existingContactId, 200, SyncOutcome::VIA_LINK);
        }

        return $this->adoptOrCreate($hubspot);
    }

    private function handleUpdate(Hubspot $hubspot): SyncOutcome
    {
        $contactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($contactId === null) {
            return $this->adoptOrCreate($hubspot);
        }

        $hubspot->updateContact($contactId, $this->properties());

        return new SyncOutcome($contactId, 200, SyncOutcome::VIA_LINK);
    }

    private function handleDelete(Hubspot $hubspot): SyncOutcome
    {
        $contactId = HubspotContact::contactIdFor($this->model->getKey());

        if ($contactId === null) {
            return new SyncOutcome(null, 204);
        }

        $hubspot->deleteContact($contactId);
        HubspotContact::archive($this->model->getKey());

        return new SyncOutcome($contactId, 204, SyncOutcome::VIA_LINK);
    }

    /**
     * No stored link, so ask HubSpot whether it already holds this contact
     * before creating a second one. A contact can predate this package, or
     * predate the model, and creating alongside it would leave two records
     * for one person with no way to tell which is current.
     */
    private function adoptOrCreate(Hubspot $hubspot): SyncOutcome
    {
        $contactId = $this->findExistingContactId($hubspot);

        if ($contactId === null) {
            return $this->createAndLink($hubspot);
        }

        $hubspot->updateContact($contactId, $this->properties());
        HubspotContact::link($this->model->getKey(), $contactId);

        return new SyncOutcome($contactId, 200, SyncOutcome::VIA_LOOKUP);
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
    private function createAndLink(Hubspot $hubspot): SyncOutcome
    {
        try {
            $response = $hubspot->createContact($this->properties());
        } catch (HubspotApiException $e) {
            return $this->adoptConflictingContact($hubspot, $e);
        }

        $contactId = $response['id'] ?? null;

        if ($contactId !== null) {
            HubspotContact::link($this->model->getKey(), (string) $contactId);
        }

        return new SyncOutcome(is_scalar($contactId) ? (string) $contactId : null, 201, SyncOutcome::VIA_CREATED);
    }

    /**
     * The last line against duplicates. The lookup can miss — two workers
     * racing on the same model, or a contact created between the lookup and
     * the create — and HubSpot then rejects the create as a duplicate and
     * names the contact holding that email. Adopting it is the only outcome
     * that does not leave two records for one person.
     *
     * Any other failure, including a conflict whose message carries no id,
     * is rethrown rather than guessed at.
     */
    private function adoptConflictingContact(Hubspot $hubspot, HubspotApiException $e): SyncOutcome
    {
        $contactId = $e->existingContactId();

        if ($contactId === null) {
            throw $e;
        }

        $hubspot->updateContact($contactId, $this->properties());
        HubspotContact::link($this->model->getKey(), $contactId);

        return new SyncOutcome($contactId, 200, SyncOutcome::VIA_CONFLICT);
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
