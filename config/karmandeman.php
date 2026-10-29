<?php

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
    ],

];
