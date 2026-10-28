<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductIntake\PersianTextNormalizer;
use PHPUnit\Framework\TestCase;

/** PRD E04 text normalization (ADR-017). */
class PersianTextNormalizerTest extends TestCase
{
    private PersianTextNormalizer $text;

    protected function setUp(): void
    {
        $this->text = new PersianTextNormalizer;
    }

    public function test_arabic_letter_forms_and_digits_become_persian(): void
    {
        $this->assertSame('کیف یکی ۲۵ 25', $this->text->line('كيف يكى ٢٥ 25'));
        $this->assertSame('کیف', $this->text->line('كيـــف'));
    }

    public function test_zwnj_joins_letters_but_never_hides_a_space(): void
    {
        $this->assertSame("می\u{200C}روم", $this->text->line("می\u{200C}\u{200C}\u{200C}روم"));
        $this->assertSame('کیف چرم', $this->text->line("کیف \u{200C} چرم"));
        $this->assertSame('کیف چرم', $this->text->line("کیف\u{200C} چرم"));
        $this->assertSame('کیف', $this->text->line("\u{200C}کیف\u{200C}"));
        $this->assertSame('', $this->text->line("\u{200C}\u{0640} "));
    }

    public function test_whitespace_is_collapsed_and_lines_follow_the_field(): void
    {
        $this->assertSame('کیف مدل آوا', $this->text->line("  کیف\t\u{00A0} مدل\nآوا  "));
        $this->assertSame("خط اول\n\nخط دوم", $this->text->text("خط اول  \r\n\r\n\r\n\r\n  خط دوم\u{200C}"));
        $this->assertSame('الف ب ج د', $this->text->line("الف\u{2028}ب\x0Bج\u{0085}د"));
        $this->assertSame("الف\nب", $this->text->text("الف\u{2029}ب"));
    }

    public function test_sku_uses_latin_digits_and_compares_case_insensitively(): void
    {
        $this->assertSame('ary-1007', $this->text->sku(' ary-۱۰٠٧ '));
        $this->assertSame($this->text->skuKey('ARY-1007'), $this->text->skuKey('ary-۱۰۰۷'));
        $this->assertSame($this->text->skuKey('ARY-1007'), $this->text->skuKey('ＡＲＹ-１００７'));
        $this->assertNotSame($this->text->skuKey('ARY-1007'), $this->text->skuKey('ARY 1007'));
        $this->assertSame($this->text->skuKey("\u{06CC}1"), $this->text->skuKey("\u{FEF1}1"));
    }

    public function test_decomposed_input_is_composed(): void
    {
        // Alef + combining madda above composes to آ (U+0622).
        $this->assertSame("\u{0622}ب", $this->text->line("\u{0627}\u{0653}ب"));
    }

    public function test_name_key_ignores_spacing_letter_forms_and_marks(): void
    {
        $key = $this->text->nameKey('کیف چرمی آوا');
        $this->assertSame($key, $this->text->nameKey("کیف\u{200C}چرمی-آوا"));
        $this->assertSame($key, $this->text->nameKey('كيف چرمي (آوا)'));
        $this->assertSame($key, $this->text->nameKey('کیفِ چرمیِ اوا'));
        $this->assertSame($this->text->nameKey('مؤسسه'), $this->text->nameKey('موسسه'));
        $this->assertSame($this->text->nameKey('Bag 25'), $this->text->nameKey('bag ۲۵'));
        $this->assertSame($this->text->nameKey('ﻛﻴﻒ'), $this->text->nameKey('کیف'));
        $this->assertNotSame($key, $this->text->nameKey('کیف چرمی آوا ۲'));
        $this->assertSame('', $this->text->nameKey(" \u{200C}-ـ "));
        // A separator between digits is part of a size, elsewhere it is noise.
        $this->assertNotSame($this->text->nameKey('روغن ۱.۵ لیتری'), $this->text->nameKey('روغن ۱۵ لیتری'));
        $this->assertNotSame($this->text->nameKey('کفش 40-41'), $this->text->nameKey('کفش 4041'));
        $this->assertSame($this->text->nameKey('روغن ۱٫۵ لیتری.'), $this->text->nameKey('روغن 1 / 5 لیتری'));
        $this->assertSame($this->text->nameKey('کیف.آوا'), $this->text->nameKey('کیف آوا'));
        $this->assertSame($this->text->nameKey('مسءله'), $this->text->nameKey('مسله'));
    }
}
