<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;
use Normalizer;

final class Identity
{
    public static function normalize(string $name): string
    {
        $normalized = Normalizer::normalize(mb_convert_case($name, MB_CASE_FOLD, 'UTF-8'), Normalizer::FORM_D);
        if ($normalized === false) {
            throw new ApiException(422, 'invalid_identity', 'Invalid Unicode identity');
        }
        $result = preg_replace('/\p{M}+/u', '', $normalized);
        if ($result === null || $result === '') {
            throw new ApiException(422, 'invalid_identity', 'Invalid Unicode identity');
        }
        return $result;
    }
}
