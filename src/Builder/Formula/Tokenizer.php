<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Formula;

use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * Splits a formula into tokens: numbers, names (functions, `a0`,
 * `RECORD_COUNT`, `g0`, `GRAND_SUMMARY`, or a field key like `l1.quantity` —
 * upper-cased, so case doesn't matter), operators, parentheses and commas.
 * Anything else is refused.
 */
final class Tokenizer
{
    public const int MAX_TOKENS = 1000;

    /**
     * @return list<array{string, string}> [type, text], type ∈ number|name|op|(|)|,
     */
    public function tokenize(string $formula, int $index): array
    {
        // A name may be dotted (`l1.quantity`, row formulas); a summary formula refuses one.
        if (preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)|(<=|>=|<>|!=|[-+*\/<>=])|([(),])|(\S))/A', $formula, $matches, PREG_SET_ORDER) === false) {
            throw InvalidReportDefinition::badFormula($index, 'it can\'t be read.');
        }

        $tokens = [];
        $consumed = 0;
        foreach ($matches as $match) {
            $consumed += strlen($match[0]);

            if (($match[5] ?? '') !== '') {
                throw InvalidReportDefinition::badFormula($index, "\"{$match[5]}\" isn't allowed.");
            }

            $tokens[] = match (true) {
                ($match[1] ?? '') !== '' => ['number', $match[1]],
                ($match[2] ?? '') !== '' => ['name', strtoupper($match[2])],
                ($match[3] ?? '') !== '' => ['op', $match[3] === '!=' ? '<>' : $match[3]],
                default => [$match[4] ?? '', $match[4] ?? ''],
            };

            if (count($tokens) > self::MAX_TOKENS) {
                throw InvalidReportDefinition::badFormula($index, 'it is too long.');
            }
        }

        if (trim(substr($formula, $consumed)) !== '') {
            throw InvalidReportDefinition::badFormula($index, 'it can\'t be read.');
        }

        return $tokens;
    }
}
