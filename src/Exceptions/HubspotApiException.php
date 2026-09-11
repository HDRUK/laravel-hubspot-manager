<?php

namespace Hdruk\LaravelHubspotManager\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class HubspotApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The contact that already holds this email, when HubSpot rejected a
     * create as a duplicate.
     *
     * HubSpot reports it in the message rather than as a field:
     * {"status":"error","message":"Contact already exists. Existing ID:
     * 12345","category":"CONFLICT"}. Parsing prose is fragile, so a message
     * that does not carry an id resolves to null and the caller rethrows
     * rather than guessing.
     */
    public function existingContactId(): ?string
    {
        if ($this->statusCode !== 409) {
            return null;
        }

        if (preg_match('/Existing ID:\s*(\d+)/i', $this->getMessage(), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];
        $message = $body['message'] ?? "HubSpot API error: HTTP {$response->status()}";

        return new self($message, $response->status(), $body);
    }
}
