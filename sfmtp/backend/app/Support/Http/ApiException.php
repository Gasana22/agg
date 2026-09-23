<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * An expected, client-facing error rendered as RFC 9457 problem+json.
 * The stable `code` is what clients branch on; `title` is for humans.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $title,
        public readonly array $extra = [],
    ) {
        parent::__construct($title);
    }

    public static function forbidden(string $code = 'forbidden', string $title = 'This action is not allowed.'): self
    {
        return new self(403, $code, $title);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'The requested resource was not found.');
    }

    public static function unauthenticated(string $code = 'unauthenticated', string $title = 'Authentication is required.'): self
    {
        return new self(401, $code, $title);
    }

    public static function conflict(string $code, string $title): self
    {
        return new self(409, $code, $title);
    }

    public static function unprocessable(string $code, string $title, array $errors = []): self
    {
        return new self(422, $code, $title, $errors ? ['errors' => $errors] : []);
    }
}
