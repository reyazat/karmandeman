<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Product\ParsedProductTable;
use App\Data\Telegram\BotReply;
use App\Models\ProductTable;
use App\Models\Project;

/**
 * The column mapping of a seller's CSV as the bot shows it for one
 * confirmation (ADR-040): which header became which field, the price unit,
 * a few rows and every skipped line. Header and cell text is the seller's
 * own, shown on one line without bidi or format controls.
 */
final class TelegramProductTablePreview
{
    private const SAMPLE_ROWS = 3;

    private const LISTED_LINES = 10;

    private const FIELDS = ['name' => 'نام', 'sku' => 'کد', 'price' => 'قیمت', 'description' => 'توضیحات', 'category' => 'دسته'];

    private const PROBLEMS = [
        'name_missing' => 'بدون نام، ثبت نمی‌شوند',
        'invalid_values' => 'مقدار نامعتبر یا بیش از حد بلند، ثبت نمی‌شوند',
        'sku_repeated' => 'کد تکراری در همین فایل، ثبت نمی‌شوند',
        'cell_count_mismatch' => 'خانه بیش از عنوان‌ها (شاید ویرگول بی‌نقل‌قول در عدد)، ثبت نمی‌شوند',
        'price_unreadable' => 'قیمت خوانده نشد، بدون قیمت ثبت می‌شوند',
    ];

    public function reply(ProductTable $table, ParsedProductTable $parsed, Project $project): BotReply
    {
        $rows = $parsed->importable();
        $unit = match ($parsed->headerUnit) {
            'IRT' => 'تومان',
            'IRR' => 'ریال',
            default => null,
        };
        $lines = ["فایل CSV پروژه «{$this->shown($project->name, 60)}» خوانده شد: ".count($rows).' محصول قابل ثبت از '.count($parsed->rows).' ردیف.'];
        $mapped = [];
        $attributes = [];
        foreach ($parsed->columns as $column) {
            if (isset(self::FIELDS[$column['field']])) {
                $mapped[] = self::FIELDS[$column['field']].' ← «'.$this->shown($column['header'], 40).'»'.($column['field'] === 'price' && $unit !== null ? " ({$unit})" : '');
            } elseif ($column['field'] === 'attribute') {
                $attributes[] = '«'.$this->shown($column['header'], 40).'»';
            }
        }
        $lines[] = 'ستون‌ها: '.implode('، ', $mapped).'.';
        if ($attributes !== []) {
            $lines[] = 'ویژگی‌ها: '.implode('، ', $attributes).'.';
        }
        foreach (array_slice($rows, 0, self::SAMPLE_ROWS) as $row) {
            $price = $row['price'] === null ? 'بدون قیمت' : number_format($row['price']).($unit === null ? '' : " {$unit}");
            $lines[] = "• ردیف {$row['line']}: {$this->shown((string) $row['name'], 60)} — {$price}";
        }
        foreach (self::PROBLEMS as $code => $label) {
            $hit = array_column(array_filter($parsed->rows, fn (array $row): bool => $row['problem'] === $code), 'line');
            if ($hit !== []) {
                $listed = implode('، ', array_slice($hit, 0, self::LISTED_LINES)).(count($hit) > self::LISTED_LINES ? '، …' : '');
                $lines[] = "ردیف‌های {$listed}: {$label}.";
            }
        }

        $drop = [['text' => 'لغو این فایل', 'callback_data' => "table:drop:{$table->public_id}"]];
        if ($rows === []) {
            $lines[] = 'هیچ ردیفی قابل ثبت نیست؛ فایل را اصلاح کن و دوباره بفرست.';

            return new BotReply(implode("\n", $lines), [$drop]);
        }
        $lines[] = 'همه مقدارها همان‌طور که در فایل نوشته‌ای ثبت می‌شوند؛ تأیید نهایی هر محصول جداست.';
        if ($unit === null && $parsed->hasPrices()) {
            $lines[] = 'واحد قیمت‌ها در فایل مشخص نیست؛ واحد را انتخاب کن.';

            return new BotReply(implode("\n", $lines), [
                [['text' => 'ثبت با قیمت به تومان', 'callback_data' => "table:irt:{$table->public_id}"]],
                [['text' => 'ثبت با قیمت به ریال', 'callback_data' => "table:irr:{$table->public_id}"]],
                $drop,
            ]);
        }

        return new BotReply(implode("\n", $lines), [
            [['text' => 'ثبت '.count($rows).' محصول', 'callback_data' => "table:go:{$table->public_id}"]],
            $drop,
        ]);
    }

    /** One line of the seller's text, without bidi or format controls (ZWNJ and ZWJ stay), cut to a length. */
    private function shown(string $value, int $max): string
    {
        return mb_strimwidth((string) preg_replace(['/[^\P{Cf}\x{200C}\x{200D}]/u', '/\s+/u'], ['', ' '], $value), 0, $max, '…');
    }
}
