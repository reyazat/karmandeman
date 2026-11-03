<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Storage\OggOpusInspector;
use DomainException;
use Tests\Fakes\OggFixture;
use Tests\TestCase;

final class OggOpusInspectorTest extends TestCase
{
    public function test_real_ogg_opus_has_a_checked_duration(): void
    {
        $this->assertSame(['mime' => 'audio/ogg', 'duration_ms' => 200], app(OggOpusInspector::class)->inspect(OggFixture::make()));
    }

    public function test_container_damage_and_non_audio_are_rejected(): void
    {
        $voice = OggFixture::make();
        foreach (['not an ogg', substr($voice, 0, -1), substr_replace($voice, 'X', 60, 1)] as $bad) {
            try {
                app(OggOpusInspector::class)->inspect($bad);
                $this->fail('Bad voice was accepted.');
            } catch (DomainException $e) {
                $this->assertContains($e->getMessage(), ['VOICE_UNSUPPORTED', 'VOICE_DAMAGED']);
            }
        }
    }

    public function test_caps_are_checked_against_bytes_and_container_granule(): void
    {
        config(['karmandeman.voice.max_kilobytes' => 0]);
        try {
            app(OggOpusInspector::class)->inspect(OggFixture::make());
            $this->fail('Large voice was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('VOICE_TOO_LARGE', $e->getMessage());
        }
        config(['karmandeman.voice.max_kilobytes' => 2048, 'karmandeman.voice.max_seconds' => 0]);
        try {
            app(OggOpusInspector::class)->inspect(OggFixture::make());
            $this->fail('Long voice was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('VOICE_TOO_LONG', $e->getMessage());
        }
    }
}
