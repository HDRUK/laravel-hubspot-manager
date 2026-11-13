<?php

namespace Hdruk\LaravelHubspotManager\Services;

use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Jobs\SyncOutcome;
use Hdruk\LaravelHubspotManager\Models\HubspotContact;
use Hdruk\LaravelHubspotManager\Planning\AdoptOrCreate;
use Hdruk\LaravelHubspotManager\Planning\LearnLinkedEmail;
use Hdruk\LaravelHubspotManager\Planning\MoveContact;
use Hdruk\LaravelHubspotManager\Planning\SyncPlan;
use Hdruk\LaravelHubspotManager\Planning\SyncPlanner;
use Hdruk\LaravelHubspotManager\Planning\UpdateLinked;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\ContactLink;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Support\NameFallback;
use Illuminate\Http\Response;
use UnexpectedValueException;

/**
 * Carries one model's sync out against HubSpot and the stored link.
 *
 * Where SyncPlanner only decides, this acts: every HubSpot call and every
 * link write made by a sync is made here.
 *
 */
final class ContactSyncer
{
    public function __construct(
        private readonly Hubspot $hubspot,
    ) {}

    /**
     * A create and an update do the same thing once a model may already be
     * linked: plan from local state, then carry the plan out.
     *
     * @param  array<string, mixed>  $properties
     */
    public function upsert(
        int|string $userId,
        ContactEmail $email,
        HubspotOwnership $ownership,
        array $properties,
    ): SyncOutcome {
        $plan = SyncPlanner::plan($email, $ownership, HubspotContact::linkForUser($userId));

        return $this->carryOut($userId, $email, $ownership, $properties, $plan);
    }

    public function delete(int|string $userId, HubspotOwnership $ownership): SyncOutcome
    {
        $contactId = HubspotContact::contactIdForUser($userId);

        if ($contactId === null) {
            return new SyncOutcome(null, Response::HTTP_NO_CONTENT);
        }

        // HubSpot clears a property set to an empty string. Only the owned
        // properties are sent, so nothing else on the contact is rewritten
        // from a model that no longer exists, and the contact is never
        // archived: others may use it, and it may predate this package.
        $blanked = $this->hubspot->tryUpdateContact($contactId, array_fill_keys($ownership->ownedProperties, ''));

        // The contact lives on, but this model no longer has a link to it.
        HubspotContact::unlink($userId);

        // A contact that is gone has nothing left to blank, so the delete has
        // still done what it set out to. Logged as no content, as a delete
        // with no link is: nothing was changed in HubSpot.
        return new SyncOutcome(
            $contactId,
            $blanked === null ? Response::HTTP_NO_CONTENT : Response::HTTP_OK,
            SyncOutcome::VIA_LINK,
        );
    }

    /**
     * SyncPlan is sealed, so the analyser reports a plan with no arm here.
     *
     * @param  array<string, mixed>  $properties
     */
    private function carryOut(
        int|string $userId,
        ContactEmail $email,
        HubspotOwnership $ownership,
        array $properties,
        SyncPlan $plan,
    ): SyncOutcome {
        return match (true) {
            $plan instanceof AdoptOrCreate    => $this->adoptOrCreate($userId, $email, $properties),
            $plan instanceof UpdateLinked     => $this->updateLinked($userId, $plan->contactId, $email, $properties),
            $plan instanceof MoveContact      => $this->move($userId, $plan, $email, $properties),
            $plan instanceof LearnLinkedEmail => $this->learnLinkedEmail($userId, $plan, $email, $ownership, $properties),
        };
    }

    /**
     * Relinked with the email just sent, so the stored email stays current:
     * a case-only change is recorded as well as sent.
     *
     * @param  array<string, mixed>  $properties
     */
    private function updateLinked(
        int|string $userId,
        string $contactId,
        ContactEmail $email,
        array $properties,
    ): SyncOutcome {
        if ($this->hubspot->tryUpdateContact($contactId, $properties) === null) {
            return $this->rematchByEmail($userId, $email, $properties);
        }

        HubspotContact::linkToUser($userId, $contactId, $email);

        return new SyncOutcome($contactId, Response::HTTP_OK, SyncOutcome::VIA_LINK);
    }

    /**
     * The linked contact is gone from HubSpot: deleted, or merged away, since
     * it was linked. Drop the link and match the model by email, as on its
     * first sync, rather than fail every sync from now on.
     *
     * Safe to retry: if matching fails, the model is left unlinked, and the
     * next attempt plans the same match.
     *
     * @param  array<string, mixed>  $properties
     */
    private function rematchByEmail(int|string $userId, ContactEmail $email, array $properties): SyncOutcome
    {
        HubspotContact::unlink($userId);

        return $this->adoptOrCreate($userId, $email, $properties);
    }

    /**
     * The link predates emails being recorded, so ask HubSpot which email the
     * linked contact holds, record it, and plan again.
     *
     * Reads HubSpot's current state: an email edited there since the link was
     * made is what is learnt. Planning again cannot return here, because the
     * link it plans from now has an email.
     *
     * @param  array<string, mixed>  $properties
     */
    private function learnLinkedEmail(
        int|string $userId,
        LearnLinkedEmail $plan,
        ContactEmail $email,
        HubspotOwnership $ownership,
        array $properties,
    ): SyncOutcome {
        $contact = $this->hubspot->tryGetContact($plan->contactId, ['email']);

        if ($contact === null) {
            return $this->rematchByEmail($userId, $email, $properties);
        }

        $contactProperties = $contact['properties'] ?? null;
        $linkedEmail = ContactEmail::tryParse(
            is_array($contactProperties) ? ($contactProperties['email'] ?? null) : null,
        );

        if ($linkedEmail === null) {
            throw new UnexpectedValueException(
                "HubSpot contact [{$plan->contactId}] holds no email, so whether this model's email has changed cannot be told."
            );
        }

        HubspotContact::linkToUser($userId, $plan->contactId, $linkedEmail);

        return $this->carryOut(
            $userId,
            $email,
            $ownership,
            $properties,
            SyncPlanner::plan($email, $ownership, new ContactLink($plan->contactId, $linkedEmail)),
        );
    }

    /**
     * Leave the contact this model shares for the one holding its new email.
     *
     * Ordered so that a retry, which runs with the same snapshot, finishes a
     * move that failed part way: every step before the last is safe to repeat,
     * and the link, written last, keeps pointing at the old contact until the
     * move is complete. A retry therefore plans the same move again.
     *
     * @param  array<string, mixed>  $properties
     */
    private function move(int|string $userId, MoveContact $plan, ContactEmail $email, array $properties): SyncOutcome
    {
        $foundContactId = $this->hubspot->findContactIdBy('email', $email->value);

        // HubSpot holds the new email on the contact being left, such as a
        // secondary email. There is nowhere to move to.
        if ($foundContactId === $plan->fromContactId) {
            return $this->updateLinked($userId, $foundContactId, $email, $properties);
        }

        // Read before anything is written. Names are not owned properties, so
        // blanking below never touches them, and a retry reads the same.
        $movingProperties = $this->withNamesFrom($plan->fromContactId, $properties);

        // HubSpot clears a property set to an empty string. Only the owned
        // properties are sent, so other consumers' properties stay in place.
        // A contact that is gone has nothing left to blank.
        $this->hubspot->tryUpdateContact($plan->fromContactId, array_fill_keys($plan->ownedProperties, ''));

        $outcome = $foundContactId === null
            ? $this->createAndLink($userId, $email, $movingProperties)
            : $this->adopt($userId, $foundContactId, $email, $movingProperties, SyncOutcome::VIA_MOVED);

        return new SyncOutcome($outcome->contactId, $outcome->statusCode, SyncOutcome::VIA_MOVED);
    }

    /**
     * These properties, with any name they do not hold taken from the
     * contact being left. HubSpot is asked only when a name is missing, and
     * only for those names. A contact that is gone supplies none.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function withNamesFrom(string $contactId, array $properties): array
    {
        $missing = NameFallback::missingFrom($properties);

        if ($missing === []) {
            return $properties;
        }

        $contact = $this->hubspot->tryGetContact($contactId, $missing);
        $oldProperties = $contact['properties'] ?? [];

        return NameFallback::fill($properties, is_array($oldProperties) ? $oldProperties : []);
    }

    /**
     * No stored link, so ask HubSpot whether it already holds this contact
     * before creating a second one. A contact can predate this package, or
     * predate the model, and creating alongside it would leave two records
     * for one person with no way to tell which is current.
     *
     * @param  array<string, mixed>  $properties
     */
    private function adoptOrCreate(int|string $userId, ContactEmail $email, array $properties): SyncOutcome
    {
        $contactId = $this->hubspot->findContactIdBy('email', $email->value);

        return $contactId === null
            ? $this->createAndLink($userId, $email, $properties)
            : $this->adopt($userId, $contactId, $email, $properties, SyncOutcome::VIA_LOOKUP);
    }

    /**
     * Take over a contact HubSpot already holds for this email.
     *
     * @param  array<string, mixed>  $properties
     */
    private function adopt(
        int|string $userId,
        string $contactId,
        ContactEmail $email,
        array $properties,
        string $resolvedVia,
    ): SyncOutcome {
        $this->hubspot->updateContact($contactId, $properties);
        HubspotContact::linkToUser($userId, $contactId, $email);

        return new SyncOutcome($contactId, Response::HTTP_OK, $resolvedVia);
    }

    /**
     * Linked only after HubSpot has confirmed the id, so a failed create
     * leaves the model unlinked and the next sync retries it.
     *
     * @param  array<string, mixed>  $properties
     */
    private function createAndLink(int|string $userId, ContactEmail $email, array $properties): SyncOutcome
    {
        try {
            $response = $this->hubspot->createContact($properties);
        } catch (HubspotApiException $e) {
            return $this->adoptConflictingContact($userId, $e, $email, $properties);
        }

        $contactId = $response['id'] ?? null;

        if ($contactId !== null) {
            HubspotContact::linkToUser($userId, (string) $contactId, $email);
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
        int|string $userId,
        HubspotApiException $e,
        ContactEmail $email,
        array $properties,
    ): SyncOutcome {
        $contactId = $e->existingContactId();

        if ($contactId === null) {
            throw $e;
        }

        return $this->adopt($userId, $contactId, $email, $properties, SyncOutcome::VIA_CONFLICT);
    }
}
