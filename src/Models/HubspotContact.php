<?php

namespace Hdruk\LaravelHubspotManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The current link between a local model and a HubSpot contact.
 *
 * One row per model, holding present state only. The history of how that
 * state was reached lives in HubspotSyncLog.
 *
 * contactIdForUser(), linkToUser() and archive() are the intended way to read and
 * change a link; each is a single statement, so concurrent queue workers
 * cannot interleave a read and a write and lose one of them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $hubspot_contact_id
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class HubspotContact extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'hubspot_contact_id',
        'archived_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'archived_at' => 'datetime',
    ];

    protected $table = 'hubspot_contacts';

    /**
     * The live HubSpot contact for a model, or null when it has never been
     * linked or its contact has since been archived.
     */
    public static function contactIdForUser(int|string $userId): ?string
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->value('hubspot_contact_id');
    }

    /**
     * Point a model at a HubSpot contact, replacing any previous link and
     * clearing an earlier archive. Upsert rather than updateOrCreate: the
     * unique index on user_id makes this atomic, where a read-then-write
     * would race between workers.
     */
    public static function linkToUser(int|string $userId, string $contactId): void
    {
        static::query()->upsert(
            [[
                'user_id'            => $userId,
                'hubspot_contact_id' => $contactId,
                'archived_at'        => null,
            ]],
            ['user_id'],
            ['hubspot_contact_id', 'archived_at'],
        );
    }

    /**
     * Record that a model's HubSpot contact has been archived. The row is
     * kept so the id remains visible, but it no longer resolves as live.
     */
    public static function archive(int|string $userId): void
    {
        static::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }
}
