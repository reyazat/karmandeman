<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Storage\OggOpusInspector;
use App\Services\Storage\OggOpusTagStripper;
use DomainException;
use Tests\Fakes\OggFixture;
use Tests\TestCase;

final class OggOpusTagStripperTest extends TestCase
{
    public function test_the_comment_header_goes_and_the_audio_stays(): void
    {
        foreach ([OggFixture::make(), OggFixture::withLongTags(), OggFixture::withPageEndingNoPacket()] as $voice) {
            $before = app(OggOpusInspector::class)->inspect($voice);
            $clean = app(OggOpusTagStripper::class)->strip($voice);

            // Still a valid voice of the same length: every page resealed and in sequence.
            $this->assertSame($before, app(OggOpusInspector::class)->inspect($clean));
            foreach (['Lavf', 'encoder=', 'TITLE=', 'Seller private note', 'PhoneRecorder', 'Vendor1'] as $tag) {
                $this->assertStringNotContainsString($tag, $clean);
            }
            $this->assertStringContainsString("OpusTags\0\0\0\0\0\0\0\0", $clean);
            // The identification page and the audio payload are kept byte for byte.
            $this->assertSame(substr($voice, 0, 47), substr($clean, 0, 47));
            $this->assertStringEndsWith(substr($voice, -150), $clean);
        }
        $this->assertStringContainsString('Seller private note', OggFixture::withLongTags());
    }

    public function test_a_layout_it_does_not_expect_is_refused(): void
    {
        $voice = OggFixture::make();
        // Headers only, no audio page; a cut page; not Ogg at all.
        foreach ([substr($voice, 0, 47 + 90), substr($voice, 0, -1), 'not an ogg'] as $bad) {
            try {
                app(OggOpusTagStripper::class)->strip($bad);
                $this->fail('A voice it cannot clean was returned.');
            } catch (DomainException $e) {
                $this->assertSame('VOICE_DAMAGED', $e->getMessage());
            }
        }
    }
}
