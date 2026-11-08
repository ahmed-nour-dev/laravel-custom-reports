<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

/**
 * One CSV cell, safe to open in a spreadsheet. Text that starts with `=`, `+`,
 * `-`, `@`, a tab or a carriage return would run as a formula in Excel or
 * Sheets (CSV injection), so it gets a leading apostrophe. Numbers, including
 * negative ones, are left as they are.
 */
final class CsvCell
{
    private const array TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function safe(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        if (is_int($value) || is_float($value) || is_numeric($text)) {
            return $text;
        }

        return $text !== '' && in_array($text[0], self::TRIGGERS, true) ? "'".$text : $text;
    }
}
