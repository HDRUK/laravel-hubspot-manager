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

    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];
        $message = $body['message'] ?? "HubSpot API error: HTTP {$response->status()}";

        return new self($message, $response->status(), $body);
    }
}
