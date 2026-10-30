<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Small text helpers with graceful fallbacks when mbstring is unavailable.
 */
final class Text
{
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    public static function utf16ToUtf8(string $value): ?string
    {
        if (!function_exists('mb_convert_encoding')) {
            return null;
        }

        $encoding = 'UTF-16';
        if (str_starts_with($value, "\xFF\xFE")) {
            $encoding = 'UTF-16LE';
        } elseif (str_starts_with($value, "\xFE\xFF")) {
            $encoding = 'UTF-16BE';
        } elseif (strlen($value) >= 2 && $value[1] === "\x00") {
            $encoding = 'UTF-16LE';
        } elseif (strlen($value) >= 2 && $value[0] === "\x00") {
            $encoding = 'UTF-16BE';
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

        return is_string($converted) && $converted !== '' ? $converted : null;
    }
}
