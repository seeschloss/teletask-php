<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Small text helpers. Requires the mbstring extension.
 */
final class Text
{
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    public static function utf16ToUtf8(string $value): string
    {
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

        $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
        if (!is_string($converted) || $converted === '') {
            throw new TeletaskException('Could not convert the .gui XML from UTF-16 to UTF-8');
        }

        return $converted;
    }
}
