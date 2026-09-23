<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes secrets from arrays and free text before they are logged, stored in
 * audit/log columns, or sent to the browser.
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    private const SECRET_KEY_PATTERN = '/pass|secret|token|private|identity|credential|api[_-]?key|access[_-]?key/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $data[$key] = $value === null || $value === '' ? $value : self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }

    /**
     * Replace every occurrence of the given secret values in a string. Also
     * masks anything that looks like an age private key.
     *
     * @param  list<string|null>  $secrets
     */
    public static function redactString(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== null && strlen($secret) >= 3) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return (string) preg_replace('/AGE-SECRET-KEY-1[0-9A-Z]+/', self::MASK, $text);
    }

    /**
     * "••••1234" style mask that reveals only the last few characters.
     */
    public static function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strlen($value) <= $visible * 2) {
            return str_repeat('•', 8);
        }

        return str_repeat('•', 8).substr($value, -$visible);
    }
}
