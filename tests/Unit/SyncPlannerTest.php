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
        $exclusive = HubspotOwnership::exclusive();
        $shared = HubspotOwnership::shared('registry_development');

        $linkedToSame = new ContactLink('hs-1', ContactEmail::parse('jane@example.com'));
        $linkedToSameInOtherCase = new ContactLink('hs-1', ContactEmail::parse('JANE@Example.com'));
        $linkedToOther = new ContactLink('hs-1', ContactEmail::parse('jane.old@example.com'));
        $linkedToUnknown = new ContactLink('hs-1', null);

        return [
            'exclusive, unlinked'                => [$exclusive, null, new AdoptOrCreate()],
            'shared, unlinked'                   => [$shared, null, new AdoptOrCreate()],

            'exclusive, same email'              => [$exclusive, $linkedToSame, new UpdateLinked('hs-1')],
            'exclusive, email changed'           => [$exclusive, $linkedToOther, new UpdateLinked('hs-1')],
            'exclusive, linked email unknown'    => [$exclusive, $linkedToUnknown, new UpdateLinked('hs-1')],

            'shared, same email'                 => [$shared, $linkedToSame, new UpdateLinked('hs-1')],
            'shared, email changed only in case' => [$shared, $linkedToSameInOtherCase, new UpdateLinked('hs-1')],
            'shared, email changed'              => [$shared, $linkedToOther, new MoveContact('hs-1', ['registry_development'])],
            'shared, linked email unknown'       => [$shared, $linkedToUnknown, new LearnLinkedEmail('hs-1')],
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
