<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AiWorkflowRun;
use InvalidArgumentException;

/**
 * Prices outputs from their real usage (ADR-042): tokens and model requests
 * at the tariff's rates, plus Karmandeman's markup percent (ADR-048), rounded
 * up, never below the output class minimum.
 * The seller sees one number, not input and output apart. A quote keeps the
 * tariff it was made with, so a later change of rates does not reach it.
 */
final class CreditTariff
{
    public const CLASS_TEXT = 'text';

    public const CLASS_FILE_TO_TEXT = 'file_to_text';

    public const CLASS_FILE_OUTPUT = 'file_output';

    public const CLASSES = [self::CLASS_TEXT, self::CLASS_FILE_TO_TEXT, self::CLASS_FILE_OUTPUT];

    /**
     * The tariff in force now, as a quote stores it.
     *
     * @return array{version: string, cap_percent: int, markup_percent: int, minimum: array<string, int>, rates: array{input_tokens_per_million: int, output_tokens_per_million: int, model_request: int}}
     */
    public function current(): array
    {
        /** @var array{input_tokens_per_million: int, output_tokens_per_million: int, model_request: int} $rates */
        $rates = config('karmandeman.credits.rates');
        /** @var array<string, int> $minimum */
        $minimum = config('karmandeman.credits.minimum');

        return [
            'version' => (string) config('karmandeman.credits.tariff_version'),
            'cap_percent' => (int) config('karmandeman.credits.quote_cap_percent'),
            'markup_percent' => (int) config('karmandeman.credits.markup_percent'),
            'minimum' => $minimum,
            'rates' => $rates,
        ];
    }

    /** The output class a workflow's result belongs to. */
    public function classFor(AiWorkflowRun $run): string
    {
        return match ($run->workflow) {
            AiWorkflowRun::WORKFLOW_PRODUCT_EXTRACTION => self::CLASS_FILE_TO_TEXT,
            default => throw new InvalidArgumentException("No output class for workflow {$run->workflow}."),
        };
    }

    /**
     * The most a quote lets the final charge reach: the estimate plus the
     * configured headroom, and at least the class minimum.
     *
     * @param  array{cap_percent: int, minimum: array<string, int>}  $tariff
     */
    public function cap(array $tariff, string $class, int $estimate): int
    {
        return max($this->minimum($tariff, $class), intdiv($estimate * (100 + max(0, $tariff['cap_percent'])) + 99, 100));
    }

    /**
     * The credits an output costs: its usage at the tariff's rates plus the
     * markup percent, rounded up once, and at least the class minimum.
     * Unreported usage counts as zero. A tariff stored before the markup
     * existed has none, so an older quote keeps the terms it was made with.
     *
     * @param  array{markup_percent?: int, minimum: array<string, int>, rates: array{input_tokens_per_million: int, output_tokens_per_million: int, model_request: int}}  $tariff
     * @param  array<string, int|string>|null  $usage
     */
    public function charge(array $tariff, string $class, ?array $usage): int
    {
        $count = fn (string $key): int => max(0, (int) ($usage[$key] ?? 0));
        $rates = $tariff['rates'];
        $millionths = $count('input_tokens') * $rates['input_tokens_per_million']
            + $count('output_tokens') * $rates['output_tokens_per_million']
            + $count('model_requests') * $rates['model_request'] * 1_000_000;

        $percent = 100 + max(0, (int) ($tariff['markup_percent'] ?? 0));

        return max($this->minimum($tariff, $class), intdiv($millionths * $percent + 99_999_999, 100_000_000));
    }

    /** @param  array{minimum: array<string, int>}  $tariff */
    private function minimum(array $tariff, string $class): int
    {
        if (! in_array($class, self::CLASSES, true)) {
            throw new InvalidArgumentException("Unknown output class {$class}.");
        }

        return max(1, (int) ($tariff['minimum'][$class] ?? 1));
    }
}
