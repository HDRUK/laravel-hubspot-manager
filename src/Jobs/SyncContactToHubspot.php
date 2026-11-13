<?php

namespace Hdruk\LaravelHubspotManager\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Hdruk\LaravelHubspotManager\Enums\HubspotAction;
use Hdruk\LaravelHubspotManager\Events\HubspotContactSynced;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;
use Hdruk\LaravelHubspotManager\Models\HubspotSyncLog;
use Hdruk\LaravelHubspotManager\Services\ContactSyncer;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use LogicException;

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

    public const RATE_LIMITER = 'hubspot-sync';

    /**
     * Failures allowed, rather than $tries: Laravel counts each rate-limit
     * release as a try.
     */
    public int $maxExceptions = 3;

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
     * A deadline replaces any limit on attempts, including the worker's
     * --tries, so rate-limit releases are never counted. It is the backstop
     * for a job that never gets to run, such as one behind a limit set to 0.
     * Fixed when the job is dispatched, so it counts from the change, not
     * from the latest attempt.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(self::RATE_LIMITER)];
    }

    public function handle(ContactSyncer $syncer): void
    {
        if (!config('hubspotmanager.default.enabled', true)) {
            return;
        }

        $outcome = new SyncOutcome(null, SyncOutcome::NO_HTTP_STATUS);
        $error = null;

        try {
            $outcome = match ($this->action) {
                HubspotAction::Create, HubspotAction::Update => $syncer->upsert(
                    $this->userId,
                    $this->requiredEmail(),
                    $this->ownership,
                    $this->properties,
                ),
                HubspotAction::Delete => $syncer->delete($this->userId, $this->ownership),
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
}
