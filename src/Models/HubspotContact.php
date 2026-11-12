<?php

namespace Hdruk\LaravelHubspotManager\Models;

use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\ContactLink;
use Illuminate\Database\Eloquent\Model;

/**
 * The current link between a local model and a HubSpot contact.
 *
 * One row per model, holding present state only. The history of how that
 * state was reached lives in HubspotSyncLog.
 *
 * contactIdForUser(), linkForUser(), linkToUser() and unlink() are the
 * intended way to read and change a link; each is a single statement, so
 * concurrent queue workers cannot interleave a read and a write and lose one
 * of them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $hubspot_contact_id
 * @property string|null $hubspot_email
 * @property \Illuminate\Support\Carbon|null $unlinked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class HubspotContact extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'hubspot_contact_id',
        'hubspot_email',
        'unlinked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'unlinked_at' => 'datetime',
    ];

    protected $table = 'hubspot_contacts';

    /**
     * The live HubSpot contact for a model, or null when it has never been
     * linked or has since been unlinked.
     */
    public static function contactIdForUser(int|string $userId): ?string
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereNull('unlinked_at')
            ->value('hubspot_contact_id');
    }

    /**
     * The live link for a model, with the email it was made for, or null
     * when it has never been linked or has since been unlinked.
     *
     * One statement, so the contact and email are read together and cannot
     * come from two different links.
     */
    public static function linkForUser(int|string $userId): ?ContactLink
    {
        $row = static::query()
            ->where('user_id', $userId)
            ->whereNull('unlinked_at')
            ->first(['hubspot_contact_id', 'hubspot_email']);

        return $row === null
            ? null
            : new ContactLink($row->hubspot_contact_id, ContactEmail::tryParse($row->hubspot_email));
    }

    /**
     * Point a model at a HubSpot contact for an email, replacing any previous
     * link and clearing an earlier archive. Upsert rather than
     * updateOrCreate: the unique index on user_id makes this atomic, where a
     * read-then-write would race between workers.
     */
    public static function linkToUser(int|string $userId, string $contactId, ContactEmail $email): void
    {
        static::query()->upsert(
            [[
                'user_id'            => $userId,
                'hubspot_contact_id' => $contactId,
                'hubspot_email'      => $email->value,
                'unlinked_at'        => null,
            ]],
            ['user_id'],
            ['hubspot_contact_id', 'hubspot_email', 'unlinked_at'],
        );
    }

    /**
     * Record that a model no longer has a live link to its HubSpot contact,
     * typically because the model was deleted.
     *
     * Says nothing about the contact itself, which may have been archived in
     * HubSpot or may live on with other properties because it is shared
     * with other systems. The row is kept so the id remains visible, and
     * unlinked_at records when the link ended. The row no longer resolves
     * as live.
     */
    public static function unlink(int|string $userId): void
    {
        static::query()
            ->where('user_id', $userId)
            ->whereNull('unlinked_at')
            ->update(['unlinked_at' => now()]);
    }
}
