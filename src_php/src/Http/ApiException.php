<?php

declare(strict_types=1);

namespace Noel\Api\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
