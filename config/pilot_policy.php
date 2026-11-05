<?php

return [
    // No secrets; resources ships in Docker with the reviewed application version.
    'directory' => resource_path('policies/pilot-2026-10-27-v3'),
    // Owner-invited testers (ADR-057): these Telegram accounts use the test bundle below,
    // an unapproved draft, and nobody else does. Empty list or directory: no testers.
    'tester_telegram_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('KM_PILOT_TESTER_TELEGRAM_IDS', ''))), fn (string $id): bool => preg_match('/^[1-9][0-9]{0,19}$/D', $id) === 1)),
    'test_directory' => filled(env('KM_PILOT_TEST_POLICY')) && preg_match('/^pilot-[0-9]{4}-[0-9]{2}-[0-9]{2}-v[0-9]+$/D', (string) env('KM_PILOT_TEST_POLICY')) === 1
        ? resource_path('policies/'.env('KM_PILOT_TEST_POLICY'))
        : '',
];
