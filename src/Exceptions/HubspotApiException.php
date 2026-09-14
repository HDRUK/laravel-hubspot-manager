<?php

namespace Hdruk\LaravelHubspotManager\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpStatus;
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
     * Whether HubSpot rejected this request because the record already
     * exists. Either signal is enough: the documented error body carries a
     * category, and the transport carries a status.
     */
    public function isConflict(): bool
    {
        return $this->statusCode === HttpStatus::HTTP_CONFLICT
            || ($this->response['category'] ?? null) === 'CONFLICT';
    }

    /**
     * The contact that already holds this email, when HubSpot rejected a
     * create as a duplicate.
     *
     * HubSpot puts the id in the message rather than in a field. Observed
     * response, HTTP 409:
     *
     *   {"status":"error",
     *    "message":"Contact already exists. Existing ID: 247895748263",
     *    "correlationId":"...",
     *    "category":"CONFLICT"}
     *
     * That wording is not documented, and HubSpot's error handling reference
     * says every field of an error body should be treated as optional, so it
     * can change without notice. A message that carries no id resolves to
     * null and the caller rethrows rather than guessing.
     */
    public function existingContactId(): ?string
    {
        if (!$this->isConflict()) {
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
