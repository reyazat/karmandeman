<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ImageMetadataUnparseable;
use App\Services\Storage\ImageMetadataStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\ImageFixture;

/** Photos leave Laravel without EXIF, XMP, comments, text chunks or trailing bytes (ADR-033). */
class ImageMetadataStripperTest extends TestCase
{
    private const SECRET = 'GPS-35.6892N-51.3890E';

    private function segment(string $marker, string $data): string
    {
        return "\xFF".$marker.pack('n', strlen($data) + 2).$data;
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private function webpChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
    }

    private function jpegWithMetadata(): string
    {
        return "\xFF\xD8"
            .$this->segment("\xE0", "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
            .$this->segment("\xE1", "Exif\x00\x00".self::SECRET)
            .$this->segment("\xE1", 'http://ns.adobe.com/xap/1.0/'."\x00".self::SECRET)
            .$this->segment("\xE2", "ICC_PROFILE\x00\x01\x01profile")
            .$this->segment("\xED", 'Photoshop 3.0'."\x00".self::SECRET)
            .$this->segment("\xFE", self::SECRET)
            .$this->segment("\xDB", str_repeat("\x01", 65))
            .$this->segment("\xC0", "\x08".pack('nn', 30, 40)."\x01\x01\x11\x00")
            .$this->segment("\xDA", "\x01\x01\x00\x00\x3F\x00")
            // Entropy data with a stuffed 0xFF00 and a restart marker.
            ."\x12\x34\xFF\x00\x56\xFF\xD0\x78"
            ."\xFF\xD9"
            .'<html>'.self::SECRET.'</html>';
    }

    public function test_jpeg_keeps_the_image_and_drops_every_metadata_segment(): void
    {
        $clean = (new ImageMetadataStripper)->strip('image/jpeg', $this->jpegWithMetadata());

        $this->assertStringNotContainsString(self::SECRET, $clean);
        $this->assertStringNotContainsString('Exif', $clean);
        $this->assertStringNotContainsString('<html>', $clean);
        $this->assertStringContainsString('JFIF', $clean);
        $this->assertStringContainsString('ICC_PROFILE', $clean);
        $this->assertStringContainsString("\x12\x34\xFF\x00\x56\xFF\xD0\x78\xFF\xD9", $clean);
        $this->assertStringEndsWith("\xFF\xD9", $clean);
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($clean), 0, 2));
    }

    public function test_png_drops_text_exif_and_time_chunks_and_trailing_bytes(): void
    {
        $png = ImageFixture::make(5, 'png');
        $iend = strrpos($png, 'IEND') - 4;
        $tagged = substr($png, 0, 33)
            .$this->pngChunk('tEXt', "Comment\x00".self::SECRET)
            .$this->pngChunk('iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00".self::SECRET)
            .$this->pngChunk('eXIf', 'MM'.self::SECRET)
            .$this->pngChunk('tIME', "\x07\xEA\x0A\x1F\x0A\x00\x00")
            .substr($png, 33, $iend - 33)
            .substr($png, $iend)
            .self::SECRET;

        $clean = (new ImageMetadataStripper)->strip('image/png', $tagged);

        $this->assertSame($png, $clean);
    }

    public function test_webp_drops_exif_and_xmp_and_clears_their_flags(): void
    {
        $vp8l = "\x2F".pack('V', 39 | (29 << 14)).str_repeat("\x07", 8);
        $body = 'WEBP'
            .$this->webpChunk('VP8X', "\x0C\x00\x00\x00".substr(pack('V', 39), 0, 3).substr(pack('V', 29), 0, 3))
            .$this->webpChunk('VP8L', $vp8l)
            .$this->webpChunk('EXIF', 'MM'.self::SECRET)
            .$this->webpChunk('XMP ', self::SECRET);
        $webp = 'RIFF'.pack('V', strlen($body)).$body.self::SECRET;

        $clean = (new ImageMetadataStripper)->strip('image/webp', $webp);

        $this->assertStringNotContainsString(self::SECRET, $clean);
        $this->assertSame(strlen($clean) - 8, unpack('V', substr($clean, 4, 4))[1]);
        $this->assertSame("\x00", $clean[20]);
        $this->assertStringContainsString('VP8L', $clean);
    }

    public function test_png_and_webp_keep_only_image_chunks(): void
    {
        $png = ImageFixture::make(6, 'png');
        $tagged = substr($png, 0, 33)
            .$this->pngChunk('exIf', 'MM'.self::SECRET)
            .$this->pngChunk('zxIf', self::SECRET)
            .$this->pngChunk('caBX', self::SECRET)
            .$this->pngChunk('prVW', self::SECRET)
            .$this->pngChunk('abCd', self::SECRET)
            .substr($png, 33);
        $this->assertSame($png, (new ImageMetadataStripper)->strip('image/png', $tagged));

        $vp8l = "\x2F".pack('V', 39 | (29 << 14)).str_repeat("\x07", 8);
        $body = 'WEBP'.$this->webpChunk('VP8L', $vp8l).$this->webpChunk('C2PA', self::SECRET)
            .$this->webpChunk('exif', self::SECRET).$this->webpChunk('ABCD', self::SECRET);
        $clean = (new ImageMetadataStripper)->strip('image/webp', 'RIFF'.pack('V', strlen($body)).$body);
        $this->assertStringNotContainsString(self::SECRET, $clean);
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($clean), 0, 2));
    }

    public function test_jpeg_keeps_fixed_headers_only_and_drops_unknown_markers(): void
    {
        $jpeg = "\xFF\xD8"
            // JFIF with a 1x1 thumbnail and an appended payload.
            .$this->segment("\xE0", "JFIF\x00\x01\x02\x00\x00\x48\x00\x48\x01\x01RGB".self::SECRET)
            .$this->segment("\xE0", "JFXX\x00\x10".self::SECRET)
            .$this->segment("\xEE", "Adobe\x00\x64\x00\x00\x00\x00\x01".self::SECRET)
            .$this->segment("\xF7", self::SECRET)
            .$this->segment("\xC8", self::SECRET)
            .$this->segment("\xC0", "\x08".pack('nn', 30, 40)."\x01\x01\x11\x00")
            .$this->segment("\xDD", "\x00\x04")
            .$this->segment("\xDA", "\x01\x01\x00\x00\x3F\x00")
            ."\x01\x02\xFF\xD9";

        $clean = (new ImageMetadataStripper)->strip('image/jpeg', $jpeg);

        $this->assertStringNotContainsString(self::SECRET, $clean);
        $this->assertStringNotContainsString('JFXX', $clean);
        $this->assertStringContainsString("\xFF\xE0\x00\x10JFIF\x00\x01\x02\x00\x00\x48\x00\x48\x00\x00", $clean);
        $this->assertStringContainsString("\xFF\xEE\x00\x0EAdobe\x00\x64\x00\x00\x00\x00\x01\xFF", $clean);
        $this->assertStringContainsString("\xFF\xDD\x00\x04\x00\x04", $clean);
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($clean), 0, 2));
    }

    public function test_fixtures_survive_unchanged_apart_from_comments(): void
    {
        $stripper = new ImageMetadataStripper;
        $this->assertSame(ImageFixture::make(3, 'png'), $stripper->strip('image/png', ImageFixture::make(3, 'png')));
        $jpeg = $stripper->strip('image/jpeg', ImageFixture::make(3));
        $this->assertStringNotContainsString('seed 3', $jpeg);
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($jpeg), 0, 2));
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($stripper->strip('image/webp', ImageFixture::make(3, 'webp'))), 0, 2));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unparseable(): iterable
    {
        $jpeg = ImageFixture::make(1);
        yield 'jpeg without end marker' => ['image/jpeg', substr($jpeg, 0, -2)];
        yield 'jpeg with a segment past the end' => ['image/jpeg', "\xFF\xD8\xFF\xE1\xFF\xFF"];
        yield 'jpeg with junk between segments' => ['image/jpeg', "\xFF\xD8\x00\x00"];
        yield 'not a jpeg' => ['image/jpeg', 'GIF89a'];
        yield 'png without IEND' => ['image/png', substr(ImageFixture::make(1, 'png'), 0, -12)];
        yield 'png with a broken chunk type' => ['image/png', "\x89PNG\r\n\x1a\n".pack('N', 0).'1234'.pack('N', 0)];
        yield 'webp with a chunk past the end' => ['image/webp', 'RIFF'.pack('V', 12).'WEBP'.'VP8L'.pack('V', 99)];
        yield 'webp longer than the file' => ['image/webp', 'RIFF'.pack('V', 999).'WEBP'];
        yield 'webp with only metadata' => ['image/webp', 'RIFF'.pack('V', 14).'WEBP'.'EXIF'.pack('V', 2).'MM'];
        yield 'other type' => ['image/gif', 'GIF89a'];
    }

    #[DataProvider('unparseable')]
    public function test_anything_it_cannot_parse_is_refused(string $mime, string $bytes): void
    {
        $this->expectException(ImageMetadataUnparseable::class);
        (new ImageMetadataStripper)->strip($mime, $bytes);
    }
}
