<?php

// An empty value counts as unset, so `KEY=` keeps the default instead of becoming 0.
$integer = fn (string $key, int $default): int => filled(env($key)) ? (int) env($key) : $default;

return [

    // One-time gift credits granted to a newly eligible account (E08).
    // Set to 0 to disable the grant.
    'gift_credits' => (int) env('KM_GIFT_CREDITS', 0),

    // Default employee template provisioned for every new workspace.
    'default_employee_template' => 'product-prep',

    // Private batch input files (FR-025, ADR-022). The disk must be private;
    // production uses the S3-compatible bucket. Files are encrypted in memory,
    // so the size cap also bounds memory per request.
    'assets' => [
        'disk' => env('KM_ASSET_DISK', 'assets'),
        'max_kilobytes' => (int) env('KM_ASSET_MAX_KILOBYTES', 10240),
        'max_pixels' => (int) env('KM_ASSET_MAX_PIXELS', 40_000_000),
        // Per user, for both the API route and the bot (ADR-024).
        'uploads_per_minute' => (int) env('KM_ASSET_UPLOADS_PER_MINUTE', 20),
    ],

    // n8n product extraction runs (Step 4, ADR-032). Off by default and always
    // off in production until an AI provider is approved and Step 5 reserves
    // credit before the run. The caps go to n8n with each run and are checked
    // again against the usage it reports (PRD §11 cost control).
    'ai' => [
        'extraction_enabled' => (bool) env('KM_AI_EXTRACTION_ENABLED', false),
        // The checked-in v1 export still calls Gemini. No OpenAI workflow is
        // selected until its signed replacement and quote path are verified.
        'workflow_version' => 'product-extraction.v1',
        'run_timeout_minutes' => (int) env('KM_AI_RUN_TIMEOUT_MINUTES', 15),
        'max_assets_per_run' => (int) env('KM_AI_MAX_ASSETS_PER_RUN', 20),
        // All photos of a run travel inline in one Gemini request, limited to 20 MB with base64 (ADR-037).
        // The workflow refuses more than 14 MB, so a higher value would only send runs that fail.
        'max_input_bytes_per_run' => min((int) env('KM_AI_MAX_INPUT_BYTES_PER_RUN', 14 * 1024 * 1024), 14 * 1024 * 1024),
        // ADR-045 supersedes the Google provider decision. The approved policy
        // must name OpenAI, and the legacy Gemini workflow is never eligible.
        'provider' => 'openai',
        'max_candidates_per_run' => (int) env('KM_AI_MAX_CANDIDATES_PER_RUN', 30),
        'max_model_requests' => (int) env('KM_AI_MAX_MODEL_REQUESTS', 40),
        'max_input_tokens' => (int) env('KM_AI_MAX_INPUT_TOKENS', 400_000),
        'max_output_tokens' => (int) env('KM_AI_MAX_OUTPUT_TOKENS', 60_000),
        // Callback clock skew and replay window, in seconds.
        'callback_tolerance_seconds' => 300,
    ],

    // A seller's CSV of simple products (ADR-040, FR-023); larger files are refused with a reason.
    'tables' => [
        'max_kilobytes' => (int) env('KM_TABLE_MAX_KILOBYTES', 1024),
        'max_rows' => (int) env('KM_TABLE_MAX_ROWS', 200),
        'max_columns' => 30,
    ],

    // Credit for paid work (PRD E08, ADR-039, ADR-042). An output is charged
    // its real usage at these rates, at least its class minimum, and never
    // more than the cap the seller accepted. The numbers are not final (PRD
    // §20); a quote keeps the tariff it was made with.
    'credits' => [
        // Change the version with any rate, minimum or cap change; each quote stores both.
        'tariff_version' => filled(env('KM_CREDIT_TARIFF_VERSION')) ? (string) env('KM_CREDIT_TARIFF_VERSION') : 'usage-v1',
        // Estimate per photo for an extraction quote; the cap adds the headroom below.
        'extraction_per_photo' => max(1, $integer('KM_CREDITS_EXTRACTION_PER_PHOTO', 1)),
        'quote_cap_percent' => max(0, $integer('KM_CREDIT_QUOTE_CAP_PERCENT', 50)),
        'quote_ttl_minutes' => max(1, $integer('KM_CREDIT_QUOTE_TTL_MINUTES', 15)),
        'minimum' => [
            'text' => max(1, $integer('KM_CREDITS_MIN_TEXT', 1)),
            'file_to_text' => max(1, $integer('KM_CREDITS_MIN_FILE_TO_TEXT', 1)),
            'file_output' => max(1, $integer('KM_CREDITS_MIN_FILE_OUTPUT', 1)),
        ],
        // Credits per million tokens and per model request; the provider's price plus margin.
        'rates' => [
            'input_tokens_per_million' => max(0, $integer('KM_CREDITS_PER_MILLION_INPUT_TOKENS', 100)),
            'output_tokens_per_million' => max(0, $integer('KM_CREDITS_PER_MILLION_OUTPUT_TOKENS', 400)),
            'model_request' => max(0, $integer('KM_CREDITS_PER_MODEL_REQUEST', 0)),
        ],
    ],

    // FR-087 / NFR-12 erasure timing (ADR-011, ADR-013, ADR-014). Completion is
    // only recorded when Ops has evidenced that both backup caps are enforced.
    'erasure' => [
        // Owner cap is 7 days; 144h leaves the 24h live-purge target inside T0+168h.
        'database_backup_max_age_hours' => (int) env('KM_DB_BACKUP_MAX_AGE_HOURS', 144),
        'key_backup_max_age_hours' => (int) env('KM_KEY_BACKUP_MAX_AGE_HOURS', 48),
        'backup_lifecycle_verified' => (bool) env('KM_ERASURE_BACKUP_LIFECYCLE_VERIFIED', false),
        'alert_hours_before_deadline' => (int) env('KM_ERASURE_ALERT_HOURS', 24),
        'batch_size' => (int) env('KM_ERASURE_BATCH_SIZE', 20),
    ],

    // FR-084 retention limits in hours (ADR-015), from the proposed table
    // retention-20261027-v3; final values follow the approved policy bundle.
    'retention' => [
        'conversation_event_hours' => (int) env('KM_RETENTION_EVENT_HOURS', 168),
        'queued_message_hours' => (int) env('KM_RETENTION_QUEUED_MESSAGE_HOURS', 24),
        // Both message clocks use the payload's original createdAt, which retries keep.
        'failed_message_hours' => (int) env('KM_RETENTION_FAILED_MESSAGE_HOURS', 168),
        // 8h token lifetime + 1h after expiry.
        'auth_replay_hours' => (int) env('KM_RETENTION_AUTH_REPLAY_HOURS', 9),
        'api_snapshot_hours' => (int) env('KM_RETENTION_API_SNAPSHOT_HOURS', 168),
        'onboarding_draft_hours' => (int) env('KM_RETENTION_ONBOARDING_DRAFT_HOURS', 168),
        // A bot photo not imported by then is dropped with its encrypted file reference (ADR-024).
        'media_intake_hours' => (int) env('KM_RETENTION_MEDIA_INTAKE_HOURS', 24),
        // A quote never accepted (ADR-043); an accepted one stays with its run and ledger entry.
        'credit_quote_hours' => (int) env('KM_RETENTION_CREDIT_QUOTE_HOURS', 168),
    ],

];
