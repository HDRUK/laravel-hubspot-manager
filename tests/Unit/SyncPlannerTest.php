<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Planning\AdoptOrCreate;
use Hdruk\LaravelHubspotManager\Planning\LearnLinkedEmail;
use Hdruk\LaravelHubspotManager\Planning\MoveContact;
use Hdruk\LaravelHubspotManager\Planning\SyncPlan;
use Hdruk\LaravelHubspotManager\Planning\SyncPlanner;
use Hdruk\LaravelHubspotManager\Planning\UpdateLinked;
use Hdruk\LaravelHubspotManager\Support\ContactEmail;
use Hdruk\LaravelHubspotManager\Support\ContactLink;
use Hdruk\LaravelHubspotManager\Support\HubspotOwnership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every combination of the three things a create or update is decided on:
 * whether the model is linked, whether it shares its contact, and how the
 * email it now holds compares with the one its link was made for.
 */
class SyncPlannerTest extends TestCase
{
    /**
     * @return array<string, array{HubspotOwnership, ?ContactLink, SyncPlan}>
     */
    public static function cases(): array
    {
        $owning = HubspotOwnership::owns('registry_development');

        $linkedToSame = new ContactLink('hs-1', ContactEmail::parse('jane@example.com'));
        $linkedToSameInOtherCase = new ContactLink('hs-1', ContactEmail::parse('JANE@Example.com'));
        $linkedToOther = new ContactLink('hs-1', ContactEmail::parse('jane.old@example.com'));
        $linkedToUnknown = new ContactLink('hs-1', null);

        return [
            'unlinked'                   => [$owning, null, new AdoptOrCreate()],
            'same email'                 => [$owning, $linkedToSame, new UpdateLinked('hs-1')],
            'email changed only in case' => [$owning, $linkedToSameInOtherCase, new UpdateLinked('hs-1')],
            'email changed'              => [$owning, $linkedToOther, new MoveContact('hs-1', ['registry_development'])],
            'linked email unknown'       => [$owning, $linkedToUnknown, new LearnLinkedEmail('hs-1')],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_plan_for_a_sync(HubspotOwnership $ownership, ?ContactLink $link, SyncPlan $expected): void
    {
        $this->assertEquals(
            $expected,
            SyncPlanner::plan(ContactEmail::parse('jane@example.com'), $ownership, $link),
        );
    }
}
