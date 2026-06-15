<?php

namespace Hdruk\LaravelHubspotManager\Tests\Unit;

use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Services\Hubspot;
use Hdruk\LaravelHubspotManager\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HubspotServiceTest extends TestCase
{
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
