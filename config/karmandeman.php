<?php

return [

    // One-time gift credits granted to a newly eligible account (E08).
    // Set to 0 to disable the grant.
    'gift_credits' => (int) env('KM_GIFT_CREDITS', 0),

    // Default employee template provisioned for every new workspace.
    'default_employee_template' => 'product-prep',

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

];
