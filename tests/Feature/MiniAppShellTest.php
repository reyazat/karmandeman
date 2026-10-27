<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class MiniAppShellTest extends TestCase
{
    public function test_privacy_page_shell_is_served_rtl_with_the_telegram_sdk(): void
    {
        $this->withoutVite();

        $this->get('/app/privacy')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('https://telegram.org/js/telegram-web-app.js', false)
            ->assertSee('<div id="app"></div>', false);
    }
}
