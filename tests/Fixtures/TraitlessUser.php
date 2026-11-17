<?php

namespace Hdruk\LaravelHubspotManager\Tests\Fixtures;

use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that defines everything the trait would, but does not use it.
 * The trait is required, so this model must never be synced.
 *
 * @property int $id
 * @property string $email
 */
class TraitlessUser extends Model
{
    protected $table = 'syncable_users';

    /** @var list<string> */
    protected $fillable = ['email'];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public function toHubspotProperties(): array
    {
        return ['email' => $this->email];
    }

    public function hubspotOwnership(): HubspotOwnership
    {
        return HubspotOwnership::owns('registry_development');
    }

    public static function hubspotSyncEnabled(): bool
    {
        return true;
    }
}
