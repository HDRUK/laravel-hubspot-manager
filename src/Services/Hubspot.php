<?php

namespace Hdruk\LaravelHubspotManager\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpStatus;
use Illuminate\Support\Facades\Http;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotApiException;
use Hdruk\LaravelHubspotManager\Exceptions\HubspotConfigurationException;

class Hubspot
{
    protected string $baseUrl;
    protected string $contactsEndpoint;
    /** @var array<string, string> */
    protected array $headers;

    public function __construct()
    {
        $this->validateConfiguration();

        $this->baseUrl = rtrim(trim((string) config('hubspotmanager.default.access.hubspot_base_url')), '/');
        $this->contactsEndpoint = config('hubspotmanager.default.endpoints.contacts');
        $this->headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . config('hubspotmanager.default.access.hubspot_api_key'),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function createContact(array $properties): array
    {
        $response = Http::withHeaders($this->headers)
            ->post("{$this->baseUrl}/{$this->contactsEndpoint}", [
                'properties' => $properties,
            ]);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * @param  list<string>  $properties
     * @return array<string, mixed>
     */
    public function getContact(string $contactId, array $properties = []): array
    {
        $query = $properties ? ['properties' => implode(',', $properties)] : [];

        $response = Http::withHeaders($this->headers)
            ->get("{$this->baseUrl}/{$this->contactsEndpoint}/{$contactId}", $query);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function updateContact(string $contactId, array $properties): array
    {
        $response = Http::withHeaders($this->headers)
            ->patch("{$this->baseUrl}/{$this->contactsEndpoint}/{$contactId}", [
                'properties' => $properties,
            ]);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * The id of the contact holding this value for a unique property, or null
     * when HubSpot holds no such contact.
     *
     * Uses retrieve-by-unique-property rather than the search API: search is
     * eventually consistent, so a contact created moments ago may not be
     * found, and it is rate limited an order of magnitude lower. This reads
     * the record directly and answers 404 when there is none.
     *
     * Archived contacts are excluded. A contact in the recycling bin cannot
     * be patched, and re-linking to one would hide the fact that HubSpot
     * will treat the next create as a new record.
     */
    public function findContactIdBy(string $property, string $value): ?string
    {
        $response = Http::withHeaders($this->headers)
            ->get("{$this->baseUrl}/{$this->contactsEndpoint}/" . rawurlencode($value), [
                'idProperty' => $property,
                'archived'   => 'false',
            ]);

        if ($response->status() === HttpStatus::HTTP_NOT_FOUND) {
            return null;
        }

        $this->throwIfFailed($response);

        $id = $response->json('id');

        return is_scalar($id) ? (string) $id : null;
    }

    public function deleteContact(string $contactId): bool
    {
        $response = Http::withHeaders($this->headers)
            ->delete("{$this->baseUrl}/{$this->contactsEndpoint}/{$contactId}");

        $this->throwIfFailed($response);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    public function createContacts(array $contacts): array
    {
        $inputs = array_map(fn (array $props) => ['properties' => $props], $contacts);

        $response = Http::withHeaders($this->headers)
            ->post("{$this->baseUrl}/{$this->contactsEndpoint}/batch/create", [
                'inputs' => $inputs,
            ]);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * @param  list<array{id: string, properties: array<string, mixed>}>  $contacts
     * @return array<string, mixed>
     */
    public function updateContacts(array $contacts): array
    {
        $response = Http::withHeaders($this->headers)
            ->post("{$this->baseUrl}/{$this->contactsEndpoint}/batch/update", [
                'inputs' => $contacts,
            ]);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * @param  list<string>  $contactIds
     */
    public function deleteContacts(array $contactIds): bool
    {
        $inputs = array_map(fn (string $id) => ['id' => $id], $contactIds);

        $response = Http::withHeaders($this->headers)
            ->post("{$this->baseUrl}/{$this->contactsEndpoint}/batch/archive", [
                'inputs' => $inputs,
            ]);

        $this->throwIfFailed($response);

        return true;
    }

    /**
     * @param  list<array{propertyName: string, operator: string, value?: mixed}>  $filters
     * @param  list<string>  $properties
     * @return array<string, mixed>
     */
    public function searchContacts(array $filters, array $properties = []): array
    {
        $payload = ['filterGroups' => [['filters' => $filters]]];

        if ($properties) {
            $payload['properties'] = $properties;
        }

        $response = Http::withHeaders($this->headers)
            ->post("{$this->baseUrl}/{$this->contactsEndpoint}/search", $payload);

        $this->throwIfFailed($response);

        return $response->json();
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->failed()) {
            throw HubspotApiException::fromResponse($response);
        }
    }

    private function validateConfiguration(): void
    {
        $required = [
            'hubspotmanager.default.access.hubspot_base_url',
            'hubspotmanager.default.access.hubspot_api_key',
        ];

        foreach ($required as $key) {
            if (empty(config($key))) {
                throw HubspotConfigurationException::missingKey($key);
            }
        }
    }
}