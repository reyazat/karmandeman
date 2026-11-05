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

    public function __construct(private CatalogueText $text) {}

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
        $pdf->SetTitle($this->text->plain($brand).' — '.$this->text->plain($collection));
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
            $pdf->Cell(0, 8, $this->cell($this->text->plain($brand).' · '.$this->text->plain($collection)), 0, 1, 'R');
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(15, $pdf->GetY() + 1, 195, $pdf->GetY() + 1);
            $pdf->Ln(4);

            if ($product['photo'] !== null) {
                $this->photo($pdf, $product['photo']);
            }

            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('vazirmatnb', '', 18, $bold);
            $pdf->MultiCell(0, 10, $this->cell($this->text->name($payload)), 0, 'R');
            $pdf->SetFont('vazirmatnb', '', 15, $bold);
            $pdf->SetTextColor(0, 102, 85);
            $pdf->MultiCell(0, 9, $this->text->price($payload), 0, 'R');
            $pdf->SetTextColor(40, 40, 40);

            $pdf->SetFont('vazirmatn', '', 12, $regular);
            $description = $this->text->description($payload);
            if ($description !== null) {
                $pdf->Ln(2);
                $pdf->MultiCell(0, 7, $this->cell($description), 0, 'R');
            }
            $details = $this->text->details($payload);
            if ($details !== []) {
                $pdf->Ln(2);
                foreach ($details as $line) {
                    $pdf->MultiCell(0, 7, $this->cell('• '.$line), 0, 'R');
                }
            }

            $pdf->SetFont('vazirmatn', '', 9, $regular);
            $pdf->SetTextColor(130, 130, 130);
            // The footer sits in the bottom margin: without this it would open a page of its own.
            $pdf->SetAutoPageBreak(false);
            $pdf->SetY(-14);
            $pdf->Cell(0, 6, 'صفحه '.$this->text->digits((string) ($index + 1)).' از '.$this->text->digits((string) $count).' · نسخه '.$this->text->digits((string) $snapshotNumber), 0, 0, 'C');
            $pdf->SetAutoPageBreak(true, 18);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Product text as TCPDF writes it: a zero-width non-joiner beside every brace, so a value such
     * as "{:pnp:}" is never one of TCPDF's page-number aliases, which it replaces in the whole page.
     */
    private function cell(string $text): string
    {
        return str_replace(['{', '}'], ["{\u{200C}", "\u{200C}}"], $text);
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
}
