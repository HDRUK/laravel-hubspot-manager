<?php

namespace Hdruk\LaravelHubspotManager\Tests\Fixtures;

use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use Hdruk\LaravelHubspotManager\Traits\HasHubspotContact;
use Illuminate\Database\Eloquent\Model;

/**
 * One consumer of a HubSpot contact shared with others, such as the
 * development registry alongside the production one.
 *
 * It sends the person's email and names, which every consumer shares, and
 * registry_development, which only it owns.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $registry_development
 */
class RegistryUser extends Model
{
    use HasHubspotContact;

    protected $table = 'registry_users';

    /** @var list<string> */
    protected $fillable = ['email', 'first_name', 'last_name', 'registry_development'];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public function toHubspotProperties(): array
    {
        return array_filter([
            'email'                => $this->email,
            'firstname'            => $this->first_name,
            'lastname'             => $this->last_name,
            'registry_development' => $this->registry_development,
        ]);
    }

    public function hubspotOwnership(): HubspotOwnership
    {
        return HubspotOwnership::owns('registry_development');
    }
}
