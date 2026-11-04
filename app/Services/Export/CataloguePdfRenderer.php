<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;
use TCPDF;

/**
 * The fixed Persian A4 catalogue of one approved snapshot (ADR-052): a cover
 * line with the brand and collection, then one product per page with its main
 * photo, name, price and unit (or "no price"), description and known
 * attributes. Every text comes from the approved payload as plain cells, never
 * HTML, so a product value cannot change the layout or run markup.
 */
final readonly class CataloguePdfRenderer
{
    private const FONT_DIR = 'fonts/vazirmatn/';

    /** Photo box on the page, in millimetres. */
    private const PHOTO_WIDTH = 180.0;

    private const PHOTO_HEIGHT = 140.0;

    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /**
     * @param  list<array{payload: array<string, mixed>, photo: string|null}>  $products  approved payloads in snapshot order, each with its main photo as JPEG bytes
     * @return string the PDF bytes
     */
    public function render(string $brand, string $collection, int $snapshotNumber, array $products): string
    {
        if ($products === []) {
            throw new RuntimeException('A catalogue needs at least one product.');
        }
        $regular = resource_path(self::FONT_DIR.'vazirmatn.php');
        $bold = resource_path(self::FONT_DIR.'vazirmatnb.php');

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Karmandeman');
        $pdf->SetTitle($this->plain($brand).' — '.$this->plain($collection));
        $pdf->setRTL(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        // Embedded and subset: the file opens the same on any phone.
        $pdf->setFontSubsetting(true);

        $count = count($products);
        foreach ($products as $index => $product) {
            $payload = $product['payload'];
            $pdf->AddPage();
            $pdf->SetFont('vazirmatnb', '', 13, $bold);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->Cell(0, 8, $this->plain($brand).' · '.$this->plain($collection), 0, 1, 'R');
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(15, $pdf->GetY() + 1, 195, $pdf->GetY() + 1);
            $pdf->Ln(4);

            if ($product['photo'] !== null) {
                $this->photo($pdf, $product['photo']);
            }

            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('vazirmatnb', '', 18, $bold);
            $pdf->MultiCell(0, 10, $this->plain((string) ($payload['name']['value'] ?? '')), 0, 'R');
            $pdf->SetFont('vazirmatnb', '', 15, $bold);
            $pdf->SetTextColor(0, 102, 85);
            $pdf->MultiCell(0, 9, $this->price($payload), 0, 'R');
            $pdf->SetTextColor(40, 40, 40);

            $pdf->SetFont('vazirmatn', '', 12, $regular);
            $description = $payload['description_fa']['value'] ?? null;
            if (is_string($description)) {
                $pdf->Ln(2);
                $pdf->MultiCell(0, 7, $this->plain($description, multiline: true), 0, 'R');
            }
            $details = $this->details($payload);
            if ($details !== []) {
                $pdf->Ln(2);
                foreach ($details as $line) {
                    $pdf->MultiCell(0, 7, '• '.$line, 0, 'R');
                }
            }

            $pdf->SetFont('vazirmatn', '', 9, $regular);
            $pdf->SetTextColor(130, 130, 130);
            // The footer sits in the bottom margin: without this it would open a page of its own.
            $pdf->SetAutoPageBreak(false);
            $pdf->SetY(-14);
            $pdf->Cell(0, 6, 'صفحه '.$this->digits((string) ($index + 1)).' از '.$this->digits((string) $count).' · نسخه '.$this->digits((string) $snapshotNumber), 0, 0, 'C');
            $pdf->SetAutoPageBreak(true, 18);
        }

        return $pdf->Output('', 'S');
    }

    /** The photo scaled into its box, centred, keeping its proportions. */
    private function photo(TCPDF $pdf, string $jpeg): void
    {
        $size = @getimagesizefromstring($jpeg);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return;
        }
        $scale = min(self::PHOTO_WIDTH / $size[0], self::PHOTO_HEIGHT / $size[1]);
        $width = $size[0] * $scale;
        $height = $size[1] * $scale;
        $top = $pdf->GetY();
        // Placed in left-to-right coordinates: RTL mode would mirror the box off the page.
        $pdf->setRTL(false);
        $pdf->Image('@'.$jpeg, 15 + (self::PHOTO_WIDTH - $width) / 2, $top, $width, $height, 'JPG', '', '', true, 150);
        $pdf->setRTL(true);
        $pdf->SetY($top + $height + 5);
    }

    /** @param  array<string, mixed>  $payload */
    private function price(array $payload): string
    {
        $irr = $payload['pricing']['regular_price_irr'] ?? null;
        if (! is_int($irr)) {
            return 'قیمت: از فروشنده بپرسید';
        }
        $toman = ($payload['pricing']['display_unit'] ?? null) === 'toman';

        return 'قیمت: '.$this->digits(number_format($toman ? intdiv($irr, 10) : $irr, 0, '', '٬')).($toman ? ' تومان' : ' ریال');
    }

    /**
     * Known attributes, then code and category; an attribute without a value is left out.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function details(array $payload): array
    {
        $lines = [];
        foreach ($payload['attributes'] ?? [] as $attribute) {
            if (is_array($attribute['values'] ?? null) && $attribute['values'] !== []) {
                $lines[] = $this->plain((string) $attribute['name']).': '.$this->plain(implode('، ', $attribute['values']));
            }
        }
        $sku = $payload['sku']['value'] ?? null;
        if (is_string($sku)) {
            // Embedded left to right, so "BAG-1" is not shown as "1-BAG".
            $lines[] = 'کد: '."\u{202A}".$this->plain($sku)."\u{202C}";
        }
        $path = $payload['category_path']['value'] ?? null;
        if (is_array($path) && $path !== []) {
            $lines[] = 'دسته: '.$this->plain(implode(' › ', $path));
        }

        return $lines;
    }

    /** Text without bidi or other format controls (ZWNJ and ZWJ stay), so no value reorders a line. */
    private function plain(string $value, bool $multiline = false): string
    {
        $value = (string) preg_replace('/[^\P{Cf}\x{200C}\x{200D}]/u', '', $value);

        return $multiline ? $value : (string) preg_replace('/\s*\R\s*/u', ' / ', $value);
    }

    private function digits(string $value): string
    {
        return str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::PERSIAN_DIGITS, $value);
    }
}
