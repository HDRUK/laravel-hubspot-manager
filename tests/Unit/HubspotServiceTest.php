<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HubspotServiceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return [
            'hubspotmanager.default.access.hubspot_base_url' => 'https://api.hubapi.com',
            'hubspotmanager.default.access.hubspot_api_key' => 'test-key',
        ];
    }

    private function hubspot(): Hubspot
    {
        config($this->validConfig());
        return new Hubspot();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // An unfaked URL in an Http::fake() array is passed through to the
        // real network, so a test that misses one would call HubSpot.
        Http::preventStrayRequests();
    }

    public function test_surrounding_whitespace_is_stripped_from_the_base_url(): void
    {
        config($this->validConfig());
        config(['hubspotmanager.default.access.hubspot_base_url' => " https://api.hubapi.com\n"]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => '12345'], 201),
        ]);

        (new Hubspot())->createContact(['email' => 'jane@example.com']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.hubapi.com/crm/v3/objects/contacts');
    }

    public function test_create_contact_posts_properties_and_returns_array(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response([
                'id' => '12345',
                'properties' => ['email' => 'jane@example.com'],
            ], 201),
        ]);

        $result = $this->hubspot()->createContact(['email' => 'jane@example.com']);

        $this->assertSame('12345', $result['id']);
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://api.hubapi.com/crm/v3/objects/contacts'
            && $request->data()['properties']['email'] === 'jane@example.com'
        );
    }

    public function test_get_contact_fetches_by_id(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/12345' => Http::response([
                'id' => '12345',
                'properties' => ['email' => 'jane@example.com'],
            ], 200),
        ]);

        $result = $this->hubspot()->getContact('12345');

        $this->assertSame('12345', $result['id']);
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && str_contains($request->url(), '/crm/v3/objects/contacts/12345')
        );
    }

    public function test_get_contact_appends_properties_as_query_string(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/12345*' => Http::response(['id' => '12345'], 200),
        ]);

        $this->hubspot()->getContact('12345', ['email', 'firstname']);

        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'properties=email%2Cfirstname')
            || str_contains($request->url(), 'properties=email,firstname')
        );
    }

    public function test_update_contact_patches_by_id(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/12345' => Http::response([
                'id' => '12345',
                'properties' => ['firstname' => 'Jane'],
            ], 200),
        ]);

        $result = $this->hubspot()->updateContact('12345', ['firstname' => 'Jane']);

        $this->assertSame('12345', $result['id']);
        Http::assertSent(fn ($request) =>
            $request->method() === 'PATCH'
            && str_contains($request->url(), '/crm/v3/objects/contacts/12345')
            && $request->data()['properties']['firstname'] === 'Jane'
        );
    }

    public function test_find_contact_id_by_returns_the_id_of_a_matching_contact(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response([
                'id' => '12345',
                'properties' => ['email' => 'jane@example.com'],
            ], 200),
        ]);

        $result = $this->hubspot()->findContactIdBy('email', 'jane@example.com');

        $this->assertSame('12345', $result);
    }

    public function test_find_contact_id_by_looks_up_the_unique_property_excluding_archived(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response(['id' => '12345'], 200),
        ]);

        $this->hubspot()->findContactIdBy('email', 'jane@example.com');

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && str_contains($request->url(), '/contacts/jane%40example.com')
            && str_contains($request->url(), 'idProperty=email')
            && str_contains($request->url(), 'archived=false')
        );
    }

    public function test_find_contact_id_by_returns_null_when_no_contact_holds_the_value(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response([
                'message' => 'resource not found',
            ], 404),
        ]);

        $this->assertNull($this->hubspot()->findContactIdBy('email', 'nobody@example.com'));
    }

    public function test_find_contact_id_by_throws_on_other_failures(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/*' => Http::response([
                'message' => 'internal error',
            ], 500),
        ]);

        $this->expectException(HubspotApiException::class);

        $this->hubspot()->findContactIdBy('email', 'jane@example.com');
    }

    /**
     * Verbatim from a real duplicate create against a HubSpot portal (with 
     * modified values to avoid exposure of real information), which
     * answered HTTP 409. Anything derived from HubSpot's wording is
     * tested here so a change to it fails as a specific test rather than as
     * silently duplicated contacts.
     *
     * @return array<string, string>
     */
    private function realConflictBody(): array
    {
        return [
            'status'        => 'error',
            'message'       => 'Contact already exists. Existing ID: 247895748263',
            'correlationId' => '01a10086-5ee9-70aa-8e3a-5d3edd17fe3c',
            'category'      => 'CONFLICT',
        ];
    }

    public function test_a_real_duplicate_conflict_exposes_the_existing_contact(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response($this->realConflictBody(), 409),
        ]);

        try {
            $this->hubspot()->createContact(['email' => 'jane@example.com']);
            $this->fail('Expected a conflict.');
        } catch (HubspotApiException $e) {
            $this->assertTrue($e->isConflict());
            $this->assertSame('247895748263', $e->existingContactId());
            $this->assertSame('CONFLICT', $e->response['category']);
        }
    }

    public function test_a_conflict_is_recognised_by_category_alone(): void
    {
        $exception = new HubspotApiException(
            'Contact already exists. Existing ID: 247895748263',
            400,
            $this->realConflictBody(),
        );

        $this->assertSame('247895748263', $exception->existingContactId());
    }

    public function test_a_conflict_without_an_id_exposes_nothing(): void
    {
        $exception = new HubspotApiException('Contact already exists.', 409);

        $this->assertNull($exception->existingContactId());
    }

    public function test_a_non_conflict_failure_exposes_nothing(): void
    {
        $exception = new HubspotApiException('Contact already exists. Existing ID: 247895748263', 400);

        $this->assertFalse($exception->isConflict());
        $this->assertNull($exception->existingContactId());
    }

    public function test_delete_contact_returns_true_on_success(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/12345' => Http::response(null, 204),
        ]);

        $result = $this->hubspot()->deleteContact('12345');

        $this->assertTrue($result);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_search_contacts_posts_filter_groups(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response([
                'results' => [['id' => '12345']],
                'total' => 1,
            ], 200),
        ]);

        $filters = [['propertyName' => 'email', 'operator' => 'EQ', 'value' => 'jane@example.com']];
        $result = $this->hubspot()->searchContacts($filters);

        $this->assertSame(1, $result['total']);
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && str_contains($request->url(), '/search')
            && isset($request->data()['filterGroups'])
        );
    }

    public function test_throws_hubspot_api_exception_on_4xx(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts' => Http::response([
                'message' => 'Property "bad_prop" does not exist',
                'status' => 'error',
            ], 400),
        ]);

        $this->expectException(HubspotApiException::class);
        $this->expectExceptionMessage('Property "bad_prop" does not exist');

        $this->hubspot()->createContact(['bad_prop' => 'value']);
    }

    public function test_api_exception_carries_status_code(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/99999' => Http::response([], 404),
        ]);

        try {
            $this->hubspot()->getContact('99999');
            $this->fail('Expected HubspotApiException was not thrown');
        } catch (HubspotApiException $e) {
            $this->assertSame(404, $e->statusCode);
        }
    }

    public function test_create_contacts_batch_posts_inputs(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/batch/create' => Http::response([
                'status' => 'COMPLETE',
                'results' => [['id' => 'hs-001'], ['id' => 'hs-002']],
            ], 201),
        ]);

        $result = $this->hubspot()->createContacts([
            ['email' => 'jane@example.com'],
            ['email' => 'john@example.com'],
        ]);

        $this->assertCount(2, $result['results']);
        Http::assertSent(fn ($r) =>
            $r->method() === 'POST'
            && str_contains($r->url(), '/batch/create')
            && count($r->data()['inputs']) === 2
            && isset($r->data()['inputs'][0]['properties'])
        );
    }

    public function test_update_contacts_batch_patches_by_id(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/batch/update' => Http::response([
                'status' => 'COMPLETE',
                'results' => [['id' => 'hs-001']],
            ], 200),
        ]);

        $result = $this->hubspot()->updateContacts([
            ['id' => 'hs-001', 'properties' => ['firstname' => 'Jane']],
        ]);

        $this->assertSame('COMPLETE', $result['status']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/batch/update'));
    }

    public function test_delete_contacts_batch_archives_by_id(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/batch/archive' => Http::response(null, 204),
        ]);

        $result = $this->hubspot()->deleteContacts(['hs-001', 'hs-002']);

        $this->assertTrue($result);
        Http::assertSent(fn ($r) =>
            str_contains($r->url(), '/batch/archive')
            && count($r->data()['inputs']) === 2
            && $r->data()['inputs'][0] === ['id' => 'hs-001']
        );
    }
}
