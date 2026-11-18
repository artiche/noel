<?php

declare(strict_types=1);

namespace Noel\Api\Http;

final class ShareMultipart
{
    /** @return array{fields:array<string,string>,image:?string} */
    public function parse(string $contentType, string $body): array
    {
        if (preg_match('/^multipart\/form-data\s*;\s*boundary=(?:"([A-Za-z0-9\'()+_,.\/:=?-]{1,70})"|([A-Za-z0-9\'()+_,.\/:=?-]{1,70}))\s*$/iD', $contentType, $match) !== 1) {
            throw new ApiException(415, 'invalid_content_type', 'Multipart form data with a valid boundary is required');
        }
        $boundary = $match[1] !== '' ? $match[1] : $match[2];
        $start = '--' . $boundary . "\r\n";
        if (!str_starts_with($body, $start)) {
            throw new ApiException(400, 'invalid_multipart', 'Invalid multipart body');
        }
        $parts = preg_split('~\r\n--' . preg_quote($boundary, '~') . '(?=--(?:\r\n|$)|\r\n)~',
            substr($body, strlen($start)));
        if ($parts === false) {
            throw new ApiException(400, 'invalid_multipart', 'Invalid multipart body');
        }
        $end = array_pop($parts);
        if (($end !== '--' && $end !== "--\r\n") || count($parts) > 4 || $parts === []) {
            throw new ApiException(400, 'invalid_multipart', 'Invalid multipart body');
        }
        $fields = [];
        $image = null;
        $seen = [];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                if (!str_starts_with($part, "\r\n")) {
                    throw new ApiException(400, 'invalid_multipart', 'Invalid multipart delimiter');
                }
                $part = substr($part, 2);
            }
            $separator = strpos($part, "\r\n\r\n");
            if ($separator === false || $separator > 8192) {
                throw new ApiException(400, 'invalid_multipart', 'Invalid multipart headers');
            }
            $headers = [];
            foreach (explode("\r\n", substr($part, 0, $separator)) as $line) {
                if (preg_match('/^([A-Za-z0-9-]+):[ \t]*([^\r\n]*)$/D', $line, $header) !== 1) {
                    throw new ApiException(400, 'invalid_multipart', 'Invalid multipart header');
                }
                $name = strtolower($header[1]);
                if (!in_array($name, ['content-disposition', 'content-type'], true) || isset($headers[$name])) {
                    throw new ApiException(400, 'invalid_multipart', 'Unsupported multipart header');
                }
                $headers[$name] = $header[2];
            }
            $disposition = $headers['content-disposition'] ?? '';
            if (preg_match('/^form-data;[ \t]*name="(title|text|url|image)"(?:;[ \t]*filename="(?:[^"\\\\]|\\\\.)*")?$/iD', $disposition, $matches) !== 1) {
                throw new ApiException(400, 'invalid_multipart', 'Invalid multipart field');
            }
            $name = strtolower($matches[1]);
            if (isset($seen[$name])) {
                throw new ApiException(400, 'invalid_multipart', 'Duplicate multipart field');
            }
            $seen[$name] = true;
            $hasFilename = preg_match('/;[ \t]*filename="/i', $disposition) === 1;
            $value = substr($part, $separator + 4);
            if ($name === 'image') {
                if (!$hasFilename) {
                    throw new ApiException(400, 'invalid_image', 'Image must be a file');
                }
                $image = $value;
            } else {
                if ($hasFilename) {
                    throw new ApiException(400, 'invalid_multipart', 'Text field must not be a file');
                }
                $fields[$name] = $value;
            }
        }
        return ['fields' => $fields, 'image' => $image];
    }
}
