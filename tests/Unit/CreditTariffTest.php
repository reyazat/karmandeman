<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiWorkflowRun;
use App\Services\Billing\CreditTariff;
use InvalidArgumentException;
use Tests\TestCase;

/** One number from real usage, never below the class minimum (ADR-042). */
class CreditTariffTest extends TestCase
{
    /** @return array{version: string, cap_percent: int, minimum: array<string, int>, rates: array{input_tokens_per_million: int, output_tokens_per_million: int, model_request: int}} */
    private function tariff(int $input = 100, int $output = 400, int $request = 0, int $minimum = 1, int $capPercent = 50): array
    {
        return [
            'version' => 'usage-v1',
            'cap_percent' => $capPercent,
            'minimum' => ['text' => $minimum, 'file_to_text' => $minimum, 'file_output' => $minimum],
            'rates' => ['input_tokens_per_million' => $input, 'output_tokens_per_million' => $output, 'model_request' => $request],
        ];
    }

    public function test_usage_is_priced_as_one_number_and_rounded_up(): void
    {
        $tariff = new CreditTariff;
        $usage = ['model_requests' => 2, 'input_tokens' => 1_000_000, 'output_tokens' => 500_001, 'provider' => 'google', 'model' => 'm'];

        // 100 + 200.0004 + 2 × 3, rounded up.
        $this->assertSame(307, $tariff->charge($this->tariff(request: 3), CreditTariff::CLASS_FILE_TO_TEXT, $usage));
        // An exact million is not rounded past.
        $this->assertSame(100, $tariff->charge($this->tariff(output: 0), CreditTariff::CLASS_TEXT, ['input_tokens' => 1_000_000]));
    }

    public function test_the_class_minimum_holds_for_small_missing_or_negative_usage(): void
    {
        $tariff = new CreditTariff;

        $this->assertSame(1, $tariff->charge($this->tariff(), CreditTariff::CLASS_TEXT, ['input_tokens' => 10, 'output_tokens' => 10]));
        $this->assertSame(5, $tariff->charge($this->tariff(minimum: 5), CreditTariff::CLASS_FILE_OUTPUT, null));
        $this->assertSame(5, $tariff->charge($this->tariff(minimum: 5), CreditTariff::CLASS_FILE_OUTPUT, ['input_tokens' => -9_000_000]));
        // A stored minimum below one never makes an output free.
        $this->assertSame(1, $tariff->charge($this->tariff(minimum: 0), CreditTariff::CLASS_TEXT, []));
    }

    public function test_the_cap_adds_the_headroom_rounded_up_and_never_falls_below_the_minimum(): void
    {
        $tariff = new CreditTariff;

        $this->assertSame(5, $tariff->cap($this->tariff(), CreditTariff::CLASS_FILE_TO_TEXT, 3));
        $this->assertSame(9, $tariff->cap($this->tariff(), CreditTariff::CLASS_FILE_TO_TEXT, 6));
        // The headroom is the quote's own, whatever the configuration says now.
        config(['karmandeman.credits.quote_cap_percent' => 300]);
        $this->assertSame(3, $tariff->cap($this->tariff(capPercent: 0), CreditTariff::CLASS_FILE_TO_TEXT, 3));
        $this->assertSame(8, $tariff->cap($this->tariff(minimum: 8, capPercent: 0), CreditTariff::CLASS_FILE_TO_TEXT, 3));
    }

    public function test_an_unknown_class_is_refused(): void
    {
        $tariff = new CreditTariff;
        $this->assertSame(CreditTariff::CLASS_FILE_TO_TEXT, $tariff->classFor(new AiWorkflowRun(['workflow' => AiWorkflowRun::WORKFLOW_PRODUCT_EXTRACTION])));

        $this->expectException(InvalidArgumentException::class);
        $tariff->charge($this->tariff(), 'voice', []);
    }

    public function test_a_workflow_without_an_output_class_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CreditTariff)->classFor(new AiWorkflowRun(['workflow' => 'catalogue']));
    }

    public function test_an_empty_environment_value_keeps_the_default_instead_of_zero(): void
    {
        $keys = ['KM_CREDIT_QUOTE_CAP_PERCENT' => '', 'KM_CREDITS_PER_MILLION_INPUT_TOKENS' => '', 'KM_CREDITS_PER_MILLION_OUTPUT_TOKENS' => '0', 'KM_CREDITS_PER_MODEL_REQUEST' => '7'];
        foreach ($keys as $key => $value) {
            putenv("{$key}={$value}");
        }
        try {
            $credits = (require base_path('config/karmandeman.php'))['credits'];
        } finally {
            foreach (array_keys($keys) as $key) {
                putenv($key);
            }
        }

        $this->assertSame(50, $credits['quote_cap_percent']);
        $this->assertSame(100, $credits['rates']['input_tokens_per_million']);
        // A set value is read, and an explicit zero is still zero.
        $this->assertSame(7, $credits['rates']['model_request']);
        $this->assertSame(0, $credits['rates']['output_tokens_per_million']);
    }

    public function test_the_current_tariff_comes_from_configuration(): void
    {
        config(['karmandeman.credits.rates' => ['input_tokens_per_million' => 7, 'output_tokens_per_million' => 8, 'model_request' => 9]]);
        $current = (new CreditTariff)->current();

        $this->assertSame('usage-v1', $current['version']);
        $this->assertSame(50, $current['cap_percent']);
        $this->assertSame(['input_tokens_per_million' => 7, 'output_tokens_per_million' => 8, 'model_request' => 9], $current['rates']);
        $this->assertSame(['text', 'file_to_text', 'file_output'], array_keys($current['minimum']));
    }
}
