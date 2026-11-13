# Sync flow

How one model change reaches HubSpot, and which part of the code owns each step.

```
model event
  └─ SyncContactToHubspot (Jobs)        queue concerns: snapshot, retries, rate limit, sync log
       └─ ContactSyncer (Services)      side effects: HubSpot calls, link writes
            ├─ SyncPlanner (Planning)   pure decision: which SyncPlan to carry out
            └─ NameFallback (Support)   pure: names a moving model takes with it
```

## Layers

### `Jobs\SyncContactToHubspot`: queue concerns

- Takes a snapshot of the model when the job is dispatched (key, properties, ownership, email). The queued payload is plain data, so the worker never reloads the model.
- Owns `retryUntil`, `backoff`, `maxExceptions` and the `RateLimited` middleware.
- Checks the `enabled` flag, picks the matching `ContactSyncer` method for the action, and writes the `HubspotSyncLog` row and the `HubspotContactSynced` event for every attempt.
- Makes no HubSpot calls and writes no links itself.

### `Services\ContactSyncer`: side effects

- Every HubSpot call and every `HubspotContact` link write made by a sync happens here.
- Two entry points: `upsert(userId, email, ownership, properties)` and `delete(userId, ownership)`. Both return a `SyncOutcome`.
- **Stateless by contract.** Its only field is the `Hubspot` client. Everything about the model being synced arrives as arguments. The container may share one instance across jobs in a long-lived worker, so adding per-sync state as a field would leak it from one job into the next.
- Injected into `handle()`, never into the job's constructor, so it is never serialised into the queue payload.

### `Planning`: pure decision

- `SyncPlanner::plan()` decides from local state alone which `SyncPlan` to carry out: `AdoptOrCreate`, `UpdateLinked`, `MoveContact` or `LearnLinkedEmail`.
- `SyncPlan` is `@phpstan-sealed`. `ContactSyncer::carryOut()` matches over it, so the analyser reports a plan with no matching arm at build time.
- Nothing in `Planning` performs I/O. It is covered by a table of inputs in `SyncPlannerTest`, without the container or HTTP fakes.

### `Support\NameFallback`: pure helper

- Works out which names a model moving to a new contact takes from the contact it is leaving.
- Used while a `MoveContact` is carried out, not while planning, so it lives in `Support` rather than `Planning`.

## Where to test what

| Layer | Test with |
|---|---|
| `Planning`, `Support` | Plain PHPUnit with a table of inputs and expected outputs. |
| `ContactSyncer` | Behaviour through the job (`SyncContactJobTest`, `EmailChangeTest`), using `Http::fake()` and the database. |
| Queue behaviour | Real workers (`QueuedSyncTest`, `RateLimitTest`). |

## Known wrinkle

`SyncOutcome` still lives in `Jobs` even though `ContactSyncer` produces it, so `Services` now depends on `Jobs`. Moving it is a separate change, because tests and possibly consuming apps import it from its current namespace.
