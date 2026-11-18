<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

interface MetadataNetwork
{
    public function isAvailable(): bool;

    /** @return list<string>|null */
    public function resolve(string $host, float $deadline): ?array;

    /** @return array{status:int,location:?string,contentType:string,contentEncoding:string,body:string}|null */
    public function request(string $url, string $host, int $port, string $address, float $deadline): ?array;
}
