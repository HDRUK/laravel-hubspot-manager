<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Planning\AdoptOrCreate;
use Hdruk\LaravelHubspotManager\Planning\LearnLinkedEmail;
use Hdruk\LaravelHubspotManager\Planning\MoveContact;
use Hdruk\LaravelHubspotManager\Planning\NameFallback;
use Hdruk\LaravelHubspotManager\Planning\SyncPlan;
use Hdruk\LaravelHubspotManager\Planning\SyncPlanner;
use Hdruk\LaravelHubspotManager\Planning\UpdateLinked;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\ContactLink;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use LogicException;
use UnexpectedValueException;

/**
 * Syncs one model to HubSpot from a snapshot taken when it was dispatched.
 *
 * The job holds no model. A queued model is stored as a class and key and
 * reloaded by the worker. Taking the key and properties up front fixes what 
 * is sent at the moment of the change, and the payload is plain data that 
 * survives the queue unchanged.
 */
class SyncContactToHubspot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public readonly int|string $userId;

    /** @var array<string, mixed> */
    public readonly array $properties;

    public readonly HubspotOwnership $ownership;

    /** Null only for a delete, which does not need one. */
    public readonly ?ContactEmail $email;

    public function __construct(
        Model $model,
        public readonly HubspotAction $action,
    ) {
        // Checked here rather than by the type, so that a model which cannot
        // be synced fails where it is dispatched rather than part way through
        // a queued job, without every consuming model having to declare an
        // interface to say so.
        if (!method_exists($model, 'toHubspotProperties')) {
            throw HubspotConfigurationException::notContactable($model::class);
        }

        // The trait declares this abstract, so a model using it cannot omit
        // it. One that defines toHubspotProperties() without the trait can,
        // and there is no default: only the model knows what it owns.
        if (!method_exists($model, 'hubspotOwnership')) {
            throw HubspotConfigurationException::undeclaredOwnership($model::class);
        }

        $key = $model->getKey();

        if (!is_int($key) && !is_string($key)) {
            throw HubspotConfigurationException::unkeyed($model::class);
        }

        $this->userId = $key;
        $this->properties = self::snapshotProperties($model);
        $this->ownership = self::snapshotOwnership($model);
        $this->email = ContactEmail::tryParseProperties($this->properties);

        // A delete acts on the stored link alone. Every other action creates,
        // finds or updates a contact by its email, and a consumer never
        // removes one, so its absence is a fault in the model.
        if ($action !== HubspotAction::Delete && $this->email === null) {
            throw HubspotConfigurationException::missingEmail($model::class);
        }
    }

    /**
     * The email for a create or update. The constructor rejects either
     * without one, so this cannot be null; it exists to hand that guarantee
     * on to the analyser, which cannot see it.
     */
    private function requiredEmail(): ContactEmail
    {
        return $this->email
            ?? throw new LogicException('A create or update without an email is rejected at dispatch.');
    }

    private static function snapshotOwnership(Model $model): HubspotOwnership
    {
        // Guaranteed to exist by the constructor; the analyser cannot see a
        // method that consuming models supply via the trait.
        // @phpstan-ignore method.notFound
        return $model->hubspotOwnership();
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotProperties(Model $model): array
    {
        // Guaranteed to exist by the constructor; the analyser cannot see a
        // method that consuming models supply via the trait.
        // @phpstan-ignore method.notFound
        $properties = (array) $model->toHubspotProperties();

        if ($productName = config('hubspotmanager.default.product_name')) {
            $properties['product_name'] = $productName;
        }

        return $properties;
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

        $outcome = new SyncOutcome(null, SyncOutcome::NO_HTTP_STATUS);
        $error = null;

        try {
            $outcome = match ($this->action) {
                HubspotAction::Create, HubspotAction::Update => $this->handleUpsert($hubspot),
                HubspotAction::Delete => $this->handleDelete($hubspot),
            };
        } catch (\Throwable $e) {
            $outcome = new SyncOutcome(
                null,
                $e instanceof HubspotApiException ? $e->statusCode : SyncOutcome::NO_HTTP_STATUS,
            );
            $error = $e->getMessage();
            throw $e;
        } finally {
            HubspotSyncLog::create([
                'user_id'            => $this->userId,
                'action'             => $this->action->value,
                'status_code'        => $outcome->statusCode,
                'hubspot_contact_id' => $outcome->contactId,
                'resolved_via'       => $outcome->resolvedVia,
                'error'              => $error,
            ]);

            event(new HubspotContactSynced(
                $this->userId,
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
            'user_id'            => $this->userId,
            'action'             => $this->action->value,
            'status_code'        => $exception instanceof HubspotApiException
                ? $exception->statusCode
                : SyncOutcome::NO_HTTP_STATUS,
            'hubspot_contact_id' => null,
            'error'              => 'All retries exhausted: ' . $exception->getMessage(),
        ]);
    }

    /**
     * A create and an update do the same thing once a model may already be
     * linked: plan from local state, then carry the plan out.
     */
    private function handleUpsert(Hubspot $hubspot): SyncOutcome
    {
        $email = $this->requiredEmail();
        $plan = SyncPlanner::plan($email, $this->ownership, HubspotContact::linkForUser($this->userId));

        return $this->carryOut($hubspot, $email, $plan);
    }

    /**
     * SyncPlan is sealed, so the analyser reports a plan with no arm here.
     */
    private function carryOut(Hubspot $hubspot, ContactEmail $email, SyncPlan $plan): SyncOutcome
    {
        return match (true) {
            $plan instanceof AdoptOrCreate    => $this->adoptOrCreate($hubspot, $email),
            $plan instanceof UpdateLinked     => $this->updateLinked($hubspot, $plan->contactId, $email),
            $plan instanceof MoveContact      => $this->move($hubspot, $plan, $email),
            $plan instanceof LearnLinkedEmail => $this->learnLinkedEmail($hubspot, $plan, $email),
        };
    }

    /**
     * Relinked with the email just sent, so the stored email stays current:
     * a case-only change is recorded as well as sent.
     */
    private function updateLinked(Hubspot $hubspot, string $contactId, ContactEmail $email): SyncOutcome
    {
        $hubspot->updateContact($contactId, $this->properties);
        HubspotContact::linkToUser($this->userId, $contactId, $email);

        return new SyncOutcome($contactId, Response::HTTP_OK, SyncOutcome::VIA_LINK);
    }

    /**
     * The link predates emails being recorded, so ask HubSpot which email the
     * linked contact holds, record it, and plan again.
     *
     * Reads HubSpot's current state: an email edited there since the link was
     * made is what is learnt. Planning again cannot return here, because the
     * link it plans from now has an email.
     */
    private function learnLinkedEmail(Hubspot $hubspot, LearnLinkedEmail $plan, ContactEmail $email): SyncOutcome
    {
        $contact = $hubspot->getContact($plan->contactId, ['email']);
        $properties = $contact['properties'] ?? null;
        $linkedEmail = ContactEmail::tryParse(is_array($properties) ? ($properties['email'] ?? null) : null);

        if ($linkedEmail === null) {
            throw new UnexpectedValueException(
                "HubSpot contact [{$plan->contactId}] holds no email, so whether this model's email has changed cannot be told."
            );
        }

        HubspotContact::linkToUser($this->userId, $plan->contactId, $linkedEmail);

        return $this->carryOut(
            $hubspot,
            $email,
            SyncPlanner::plan($email, $this->ownership, new ContactLink($plan->contactId, $linkedEmail)),
        );
    }

    /**
     * Leave the contact this model shares for the one holding its new email.
     *
     * Ordered so that a retry, which runs with the same snapshot, finishes a
     * move that failed part way: every step before the last is safe to repeat,
     * and the link, written last, keeps pointing at the old contact until the
     * move is complete. A retry therefore plans the same move again.
     */
    private function move(Hubspot $hubspot, MoveContact $plan, ContactEmail $email): SyncOutcome
    {
        $foundContactId = $hubspot->findContactIdBy('email', $email->value);

        // HubSpot holds the new email on the contact being left, such as a
        // secondary email. There is nowhere to move to.
        if ($foundContactId === $plan->fromContactId) {
            return $this->updateLinked($hubspot, $foundContactId, $email);
        }

        // Read before anything is written. Names are not owned properties, so
        // blanking below never touches them, and a retry reads the same.
        $properties = $this->withNamesFrom($hubspot, $plan->fromContactId);

        // HubSpot clears a property set to an empty string. Only the owned
        // properties are sent, so other consumers' properties stay in place.
        $hubspot->updateContact($plan->fromContactId, array_fill_keys($plan->ownedProperties, ''));

        $outcome = $foundContactId === null
            ? $this->createAndLink($hubspot, $email, $properties)
            : $this->adopt($hubspot, $foundContactId, $email, $properties, SyncOutcome::VIA_MOVED);

        return new SyncOutcome($outcome->contactId, $outcome->statusCode, SyncOutcome::VIA_MOVED);
    }

    /**
     * This model's properties, with any name it does not hold taken from the
     * contact it is leaving. HubSpot is asked only when a name is missing,
     * and only for those names.
     *
     * @return array<string, mixed>
     */
    private function withNamesFrom(Hubspot $hubspot, string $contactId): array
    {
        $missing = NameFallback::missingFrom($this->properties);

        if ($missing === []) {
            return $this->properties;
        }

        $contact = $hubspot->getContact($contactId, $missing);
        $oldProperties = $contact['properties'] ?? [];

        return NameFallback::fill($this->properties, is_array($oldProperties) ? $oldProperties : []);
    }

    private function handleDelete(Hubspot $hubspot): SyncOutcome
    {
        $contactId = HubspotContact::contactIdForUser($this->userId);

        if ($contactId === null) {
            return new SyncOutcome(null, Response::HTTP_NO_CONTENT);
        }

        // HubSpot clears a property set to an empty string. Only the owned
        // properties are sent, so nothing else on the contact is rewritten
        // from a model that no longer exists, and the contact is never
        // archived: others may use it, and it may predate this package.
        $hubspot->updateContact($contactId, array_fill_keys($this->ownership->ownedProperties, ''));

        // The contact lives on, but this model no longer has a link to it.
        HubspotContact::unlink($this->userId);

        return new SyncOutcome($contactId, Response::HTTP_OK, SyncOutcome::VIA_LINK);
    }

    /**
     * No stored link, so ask HubSpot whether it already holds this contact
     * before creating a second one. A contact can predate this package, or
     * predate the model, and creating alongside it would leave two records
     * for one person with no way to tell which is current.
     */
    private function adoptOrCreate(Hubspot $hubspot, ContactEmail $email): SyncOutcome
    {
        $contactId = $hubspot->findContactIdBy('email', $email->value);

        return $contactId === null
            ? $this->createAndLink($hubspot, $email, $this->properties)
            : $this->adopt($hubspot, $contactId, $email, $this->properties, SyncOutcome::VIA_LOOKUP);
    }

    /**
     * Take over a contact HubSpot already holds for this email.
     *
     * @param  array<string, mixed>  $properties
     */
    private function adopt(
        Hubspot $hubspot,
        string $contactId,
        ContactEmail $email,
        array $properties,
        string $resolvedVia,
    ): SyncOutcome {
        $hubspot->updateContact($contactId, $properties);
        HubspotContact::linkToUser($this->userId, $contactId, $email);

        return new SyncOutcome($contactId, Response::HTTP_OK, $resolvedVia);
    }

    /**
     * Linked only after HubSpot has confirmed the id, so a failed create
     * leaves the model unlinked and the next sync retries it.
     *
     * @param  array<string, mixed>  $properties
     */
    private function createAndLink(Hubspot $hubspot, ContactEmail $email, array $properties): SyncOutcome
    {
        try {
            $response = $hubspot->createContact($properties);
        } catch (HubspotApiException $e) {
            return $this->adoptConflictingContact($hubspot, $e, $email, $properties);
        }

        $contactId = $response['id'] ?? null;

        if ($contactId !== null) {
            HubspotContact::linkToUser($this->userId, (string) $contactId, $email);
        }

        return new SyncOutcome(is_scalar($contactId) ? (string) $contactId : null, Response::HTTP_CREATED, SyncOutcome::VIA_CREATED);
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
     *
     * @param  array<string, mixed>  $properties
     */
    private function adoptConflictingContact(
        Hubspot $hubspot,
        HubspotApiException $e,
        ContactEmail $email,
        array $properties,
    ): SyncOutcome {
        $contactId = $e->existingContactId();

        if ($contactId === null) {
            throw $e;
        }

        return $this->adopt($hubspot, $contactId, $email, $properties, SyncOutcome::VIA_CONFLICT);
    }
}
