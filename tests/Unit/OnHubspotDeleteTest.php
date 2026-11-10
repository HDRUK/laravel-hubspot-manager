<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Support\OnHubspotDelete;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Clearing no properties is unrepresentable by the signature, so it is
 * checked by the analyser rather than here. What the signature cannot rule
 * out is a blank name, which HubSpot would reject in the worker.
 */
class OnHubspotDeleteTest extends TestCase
{
    public function test_a_blank_property_name_is_rejected_where_it_is_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OnHubspotDelete::clearProperties('registry_development', ' ');
    }
}
