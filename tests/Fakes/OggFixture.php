<?php

declare(strict_types=1);

namespace Tests\Fakes;

/** 200 ms of silence, generated with ffmpeg/libopus; no seller data. */
final class OggFixture
{
    public static function make(): string
    {
        return base64_decode(
            'T2dnUwACAAAAAAAAAAAJH3EcAAAAADp+H+IBE09wdXNIZWFkAQE4AYC7AAAAAABPZ2dTAAAAAAAAAAAAAAkfcRwBAAAAaI+SPgE+T3B1c1RhZ3MNAAAATGF2ZjYwLjE2LjEwMAEAAAAdAAAAZW5jb2Rlcj1MYXZjNjAuMzEuMTAyIGxpYm9wdXNPZ2dTAAS4JgAAAAAAAAkfcRwCAAAA231GqAsOEA8PDw8PDw8PD2gL5ME27MWNjElIQmzQaAfJcifhROpV8fDA0TIpMGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcA==',
            true,
        );
    }
}
