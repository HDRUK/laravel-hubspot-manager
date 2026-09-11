<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\Hubspot;

class SyncContactToHubspot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Model $model,
        public readonly HubspotAction $action,
    ) {
        // Checked here rather than by the type, so that a model which cannot
        // be synced fails where it is dispatched rather than part way through
        // a queued job, without every consuming model having to declare an
        // interface to say so.
        if (!method_exists($model, 'toHubspotProperties')) {
            throw HubspotConfigurationException::notContactable($model::class);
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
                HubspotAction::Create => $this->handleCreate($hubspot),
                HubspotAction::Update => $this->handleUpdate($hubspot),
                HubspotAction::Delete => $this->handleDelete($hubspot),
            };
        } catch (HubspotApiException $e) {
            $outcome = new SyncOutcome(null, $e->statusCode);
            $error = $e->getMessage();
            throw $e;
        } finally {
            HubspotSyncLog::create([
                'user_id'            => $this->model->getKey(),
                'action'             => $this->action->value,
                'status_code'        => $outcome->statusCode,
                'hubspot_contact_id' => $outcome->contactId,
                'resolved_via'       => $outcome->resolvedVia,
                'error'              => $error,
            ]);

            event(new HubspotContactSynced(
                $this->model,
                $this->action->value,
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
            'action'             => $this->action->value,
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
     * Contacts are looked up by email, which is the property HubSpot itself
     * dedupes on and the only one guaranteed to identify a contact.
     *
     * An address that is absent, non-scalar or blank once trimmed yields no
     * lookup at all. Asking HubSpot to match an empty value matches an
     * arbitrary contact, and this model would adopt a stranger's record.
     */
    private function findExistingContactId(Hubspot $hubspot): ?string
    {
        $email = $this->properties()['email'] ?? null;

        if (!is_scalar($email)) {
            return null;
        }

        $email = trim((string) $email);

        return $email === '' ? null : $hubspot->findContactIdBy('email', $email);
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
        // Guaranteed to exist by the constructor; the analyser cannot see a
        // method that consuming models supply via the trait.
        // @phpstan-ignore method.notFound
        $properties = (array) $this->model->toHubspotProperties();

        if ($productName = config('hubspotmanager.default.product_name')) {
            $properties['product_name'] = $productName;
        }

        return $properties;
    }
}
