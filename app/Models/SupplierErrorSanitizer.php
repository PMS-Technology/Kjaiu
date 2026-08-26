<?php

namespace App\Models;

use DomainException;
use Stringable;

final class SupplierErrorSanitizer
{
    public static function sanitize(mixed $error, array $sensitivePayloads = []): ?string
    {
        if ($error === null) {
            return null;
        }
        if (! is_scalar($error) && ! $error instanceof Stringable) {
            throw new DomainException('Supplier errors must be strings.');
        }

        $error = trim((string) $error);
        if ($error === '') {
            return null;
        }

        foreach (self::sensitiveValues($sensitivePayloads) as $value) {
            $error = str_replace($value, '[REDACTED]', $error);
        }

        $error = preg_replace(
            '/\b(Bearer|Basic)\s+[^\s,;]+/i',
            '$1 [REDACTED]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '#(https?://[^:/\s]+:)[^@\s/]+@#i',
            '$1[REDACTED]@',
            $error,
        ) ?? $error;

        $key = '(?:password|passwd|access[_-]?token|refresh[_-]?token|token|jwt|authorization|api[_-]?key|client[_-]?secret|private[_-]?key|secret|cookie)';
        $error = preg_replace_callback(
            '/(["\']?\b'.$key.'\b["\']?\s*[:=]\s*)(["\'])(.*?)\2/i',
            fn (array $match): string => $match[1].$match[2].'[REDACTED]'.$match[2],
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/(["\']?\b'.$key.'\b["\']?\s*[:=]\s*)(?!["\'])[^\s,;&}]+/i',
            '$1[REDACTED]',
            $error,
        ) ?? $error;

        return $error;
    }

    private static function sensitiveValues(array $payloads): array
    {
        $values = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            foreach ($payload as $key => $value) {
                if (is_string($key)
                    && preg_match('/password|passwd|token|jwt|authorization|api[_-]?key|secret|cookie/i', $key)) {
                    self::collectValues($value, $values);
                } elseif (is_array($value)) {
                    $values = array_merge($values, self::sensitiveValues([$value]));
                }
            }
        }

        usort($values, fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return array_values(array_unique($values));
    }

    private static function collectValues(mixed $value, array &$values): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectValues($item, $values);
            }

            return;
        }
        if ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
            $values[] = (string) $value;
        }
    }
}
