<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Data\Product\ParsedProductTable;
use DomainException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;

/**
 * Reads a seller's CSV of simple products into drafts with fixed code, no
 * model (PRD E03/E04, ADR-040). Columns are recognised only by known header
 * names; a header the table does not name clearly is never guessed. Every
 * cell is untrusted text: rows go through the same draft rules as the API
 * form, and a refused row is skipped and counted, never half stored.
 */
final readonly class ProductTableParser
{
    /** Header keys (after PersianTextNormalizer::nameKey) for each field. */
    private const HEADERS = [
        'name' => ['نام', 'ناممحصول', 'نامکالا', 'عنوان', 'عنوانمحصول', 'محصول', 'کالا', 'name', 'productname', 'title', 'product'],
        'sku' => ['کد', 'کدمحصول', 'کدکالا', 'شناسه', 'شناسهمحصول', 'sku', 'productcode', 'code'],
        'price' => ['قیمت', 'قیمتبه', 'قیمتفروش', 'قیمتفروشبه', 'قیمتمحصول', 'مبلغ', 'مبلغبه', 'price', 'pricein', 'regularprice'],
        'description' => ['توضیحات', 'توضیح', 'شرح', 'معرفی', 'description', 'desc'],
        'category' => ['دسته', 'دستهبندی', 'گروه', 'گروهکالا', 'category', 'categories'],
    ];

    private const UNIT_WORDS = ['تومان' => 'IRT', 'toman' => 'IRT', 'irt' => 'IRT', 'ریال' => 'IRR', 'rial' => 'IRR', 'irr' => 'IRR'];

    private const DELIMITERS = [',', ';', "\t"];

    /** Trailing empty cells a spreadsheet adds past the header are tolerated, up to this many. */
    private const SPARE_CELLS = 30;

    private const MAX_DELIMITERS_PER_LINE = 1000;

    public function __construct(
        private PersianTextNormalizer $text,
        private ProductDraftRules $draftRules,
        private ValidationFactory $validation,
    ) {}

    /**
     * @throws DomainException TABLE_UNREADABLE, TABLE_EMPTY, TABLE_TOO_MANY_ROWS, TABLE_TOO_MANY_COLUMNS, TABLE_NO_NAME_COLUMN, TABLE_AMBIGUOUS_COLUMNS or TABLE_MIXED_PRICE_UNITS
     */
    public function parse(#[\SensitiveParameter] string $bytes): ParsedProductTable
    {
        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, 3);
        }
        // UTF-8 text only: a spreadsheet, a UTF-16 export or a binary file is refused, never decoded by guess.
        if ($bytes === '' || str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new DomainException('TABLE_UNREADABLE');
        }

        $lines = $this->records($bytes);
        // Not array_shift: it would renumber the file lines every value cites.
        $first = array_key_first($lines);
        $header = $first === null ? null : $lines[$first];
        unset($lines[$first]);
        if ($header === null || $lines === []) {
            throw new DomainException('TABLE_EMPTY');
        }
        if (count($header) > (int) config('karmandeman.tables.max_columns')) {
            throw new DomainException('TABLE_TOO_MANY_COLUMNS');
        }
        if (count($lines) > (int) config('karmandeman.tables.max_rows')) {
            throw new DomainException('TABLE_TOO_MANY_ROWS');
        }
        [$columns, $headerUnit] = $this->columns($header);

        $rows = [];
        $skus = [];
        $units = $headerUnit === null ? [] : [$headerUnit => true];
        foreach ($lines as $line => $cells) {
            $row = $this->row($columns, $cells, $line);
            if ($row['unit'] !== null) {
                $units[$row['unit']] = true;
            }
            $sku = $row['draft']['sku']['value'] ?? null;
            if (is_string($sku)) {
                $key = $this->text->skuKey($sku);
                if (isset($skus[$key])) {
                    $row = ['line' => $line, 'draft' => null, 'price' => null, 'unit' => null, 'problem' => 'sku_repeated'];
                }
                $skus[$key] = true;
            }
            unset($row['unit']);
            // The name and SKU as they will be stored, for the preview the seller confirms.
            $shownSku = $row['draft']['sku']['value'] ?? null;
            $rows[] = $row + [
                'name' => $row['draft'] === null ? null : $this->text->line((string) $row['draft']['name']['value']),
                'sku' => is_string($shownSku) ? $this->text->sku($shownSku) : null,
            ];
        }
        // Toman and rial in one file is refused: one wrong guess is a price off by ten (QA-02).
        if (count($units) > 1) {
            throw new DomainException('TABLE_MIXED_PRICE_UNITS');
        }

        return new ParsedProductTable($columns, array_key_first($units), $rows);
    }

    /**
     * Non-empty records keyed by their 1-based line in the file.
     *
     * @return array<int, list<string>>
     */
    private function records(string $bytes): array
    {
        $first = strtok($bytes, "\r\n");
        $delimiter = ',';
        $most = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = count(str_getcsv((string) $first, $candidate, '"', ''));
            if ($count > $most) {
                [$delimiter, $most] = [$candidate, $count];
            }
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new DomainException('TABLE_UNREADABLE');
        }
        // A line far wider than any table we take is refused before it is split into cells.
        foreach (explode("\n", $bytes) as $physical) {
            if (substr_count($physical, $delimiter) > self::MAX_DELIMITERS_PER_LINE) {
                throw new DomainException('TABLE_TOO_MANY_COLUMNS');
            }
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $records = [];
        // The physical line a record starts on: a quoted cell may span several lines.
        $line = 1;
        $offset = 0;
        // One record past the cap is enough to refuse the file.
        $limit = (int) config('karmandeman.tables.max_rows') + 2;
        while (count($records) < $limit && ($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $start = $line;
            $next = (int) ftell($stream);
            $line += substr_count($bytes, "\n", $offset, $next - $offset);
            $offset = $next;
            if (count($cells) > (int) config('karmandeman.tables.max_columns') + self::SPARE_CELLS) {
                throw new DomainException('TABLE_TOO_MANY_COLUMNS');
            }
            $cells = array_map(fn (?string $cell): string => trim((string) $cell), $cells);
            if (implode('', $cells) !== '') {
                $records[$start] = $cells;
            }
        }
        fclose($stream);

        return $records;
    }

    /**
     * @param  list<string>  $header
     * @return array{list<array{index: int, header: string, field: string}>, 'IRT'|'IRR'|null}
     */
    private function columns(array $header): array
    {
        $columns = [];
        $seen = [];
        $unit = null;
        foreach ($header as $index => $cell) {
            $title = $this->text->line($cell);
            $key = $this->text->nameKey($title);
            $cellUnit = null;
            $cellUnits = [];
            foreach (self::UNIT_WORDS as $word => $code) {
                if (str_contains($key, $word)) {
                    $cellUnits[$code] = true;
                    $cellUnit = $code;
                    $key = str_replace($word, '', $key);
                }
            }
            if (count($cellUnits) > 1) {
                throw new DomainException('TABLE_MIXED_PRICE_UNITS');
            }
            $field = $title === '' ? 'ignored' : 'attribute';
            foreach (self::HEADERS as $name => $aliases) {
                if (in_array($key, $aliases, true)) {
                    $field = $name;
                }
            }
            if ($field !== 'attribute' && $field !== 'ignored') {
                if (isset($seen[$field])) {
                    throw new DomainException('TABLE_AMBIGUOUS_COLUMNS');
                }
                $seen[$field] = true;
            }
            if ($field === 'price') {
                $unit = $cellUnit;
            }
            $columns[] = ['index' => $index, 'header' => $title, 'field' => $field];
        }
        if (! isset($seen['name'])) {
            throw new DomainException('TABLE_NO_NAME_COLUMN');
        }

        return [$columns, $unit];
    }

    /**
     * @param  list<array{index: int, header: string, field: string}>  $columns
     * @param  list<string>  $cells
     * @return array{line: int, draft: array<string, mixed>|null, price: int|null, unit: 'IRT'|'IRR'|null, problem: string|null}
     */
    private function row(array $columns, array $cells, int $line): array
    {
        // More filled cells than headers: an unquoted comma (1,200,000) shifted every value after it.
        if (count(array_filter(array_slice($cells, count($columns)), fn (string $cell): bool => $cell !== '')) > 0) {
            return ['line' => $line, 'draft' => null, 'price' => null, 'unit' => null, 'problem' => 'cell_count_mismatch'];
        }
        $known = fn (mixed $value): array => ['value' => $value, 'status' => $value === null ? 'unknown' : 'confirmed'];
        $draft = ['attributes' => []];
        $price = null;
        $unit = null;
        $problem = null;
        foreach ($columns as $column) {
            $cell = ($cells[$column['index']] ?? '') === '' ? null : $cells[$column['index']];
            switch ($column['field']) {
                case 'name':
                    $draft['name'] = $known($cell);
                    break;
                case 'sku':
                    $draft['sku'] = $known($cell);
                    break;
                case 'description':
                    $draft['description_fa'] = $known($cell);
                    break;
                case 'category':
                    $draft['category_path'] = $known($cell === null ? null : $this->split($cell, '/[>\/]/u'));
                    break;
                case 'attribute':
                    if ($cell !== null) {
                        $draft['attributes'][] = ['name' => $column['header'], 'values' => $this->split($cell, '/[،,|]/u'), 'status' => 'confirmed'];
                    }
                    break;
                case 'price':
                    if ($cell !== null) {
                        [$price, $unit] = $this->price($cell);
                        $problem = $price === null ? 'price_unreadable' : null;
                    }
                    break;
            }
        }
        $name = $draft['name']['value'] ?? null;
        if (! is_string($name) || $this->text->line($name) === '') {
            return ['line' => $line, 'draft' => null, 'price' => null, 'unit' => null, 'problem' => 'name_missing'];
        }

        // The draft rules see the price with a stand-in unit; the real one is the seller's.
        $checked = $price === null ? $draft : $draft + ['pricing' => ['regular_price' => $price, 'input_unit' => 'IRR', 'status' => 'confirmed']];
        $check = $this->validation->make($checked, ProductDraftRules::rules());
        $check->after(function (Validator $v) use ($checked): void {
            if ($v->errors()->isEmpty()) {
                $this->draftRules->checkText($v, $checked);
            }
            $this->draftRules->checkStatuses($v, $checked);
        });
        if ($check->fails()) {
            return ['line' => $line, 'draft' => null, 'price' => null, 'unit' => null, 'problem' => 'invalid_values'];
        }

        return ['line' => $line, 'draft' => $draft, 'price' => $price, 'unit' => $unit, 'problem' => $problem];
    }

    /**
     * A whole number with Persian, Arabic or Latin digits, optional thousands
     * separators and an optional unit word; anything else (a decimal, a
     * range, a phrase) is unreadable, never rounded or guessed. A dot is not
     * a thousands separator: 12.500 may mean twelve and a half. Zero is no
     * price (PRD E04).
     *
     * @return array{int|null, 'IRT'|'IRR'|null}
     */
    private function price(string $cell): array
    {
        $digits = mb_strtolower($this->text->sku($cell));
        $unit = null;
        $units = [];
        foreach (self::UNIT_WORDS as $word => $code) {
            if (str_contains($digits, $word)) {
                $units[$code] = true;
                $unit = $code;
                $digits = str_replace($word, '', $digits);
            }
        }
        if (count($units) > 1) {
            throw new DomainException('TABLE_MIXED_PRICE_UNITS');
        }
        $digits = trim($digits);
        // Latin digits only after the Persian and Arabic ones are mapped: \d would take any script.
        if (preg_match('/^[0-9]{1,3}(?:[,٬\x{2019}\' ][0-9]{3})+$|^[0-9]{1,12}$/u', $digits) !== 1) {
            return [null, null];
        }
        $number = (int) preg_replace('/[^0-9]/', '', $digits);

        return $number >= 1 && $number <= 999_999_999_999 ? [$number, $unit] : [null, null];
    }

    /** @return list<string>|null */
    private function split(string $cell, string $pattern): ?array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split($pattern, $cell) ?: []), fn (string $part): bool => $part !== ''));

        return $parts === [] ? null : $parts;
    }
}
