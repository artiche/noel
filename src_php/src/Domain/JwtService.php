<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;

final class JwtService
{
    public function __construct(private readonly string $secret)
    {
        $this->assertSecretConfigured();
    }

    /** @param array<string,mixed> $claims */
    public function createToken(array $claims): string
    {
        $this->assertSecretConfigured();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $encodedPayload = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;
    }

    /** @return array<string,mixed> */
    public function verifyToken(string $token, int $now): array
    {
        $this->assertSecretConfigured();
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException(401, 'invalid_token', 'Invalid JWT format');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $headerRaw = $this->base64UrlDecode($encodedHeader);
        $payloadRaw = $this->base64UrlDecode($encodedPayload);
        $signatureRaw = $this->base64UrlDecode($encodedSignature);

        $header = json_decode($headerRaw, true);
        $payload = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($payload)) {
            throw new ApiException(401, 'invalid_token', 'Invalid JWT payload');
        }
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new ApiException(401, 'invalid_token', 'Unsupported JWT algorithm');
        }

        $expectedSignature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret, true);
        if (!hash_equals($expectedSignature, $signatureRaw)) {
            throw new ApiException(401, 'invalid_token', 'Invalid JWT signature');
        }

        $exp = $payload['exp'] ?? null;
        if (!is_int($exp) && !(is_string($exp) && preg_match('/^\d+$/', $exp) === 1)) {
            throw new ApiException(401, 'invalid_token', 'Missing or invalid exp claim');
        }
        $exp = (int) $exp;
        if ($exp <= $now) {
            throw new ApiException(401, 'token_expired', 'JWT token has expired');
        }

        return $payload;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ApiException(401, 'invalid_token', 'Invalid base64url JWT segment');
        }

        return $decoded;
    }

    private function assertSecretConfigured(): void
    {
        if (strlen(trim($this->secret)) < 32 || str_starts_with(strtoupper(trim($this->secret)), 'CHANGE_ME')
            || str_starts_with(strtolower(trim($this->secret)), 'change-me')) {
            throw new ApiException(500, 'jwt_secret_invalid', 'JWT secret must be a non-placeholder value of at least 32 bytes');
        }
    }
}
