<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Classifier
    |--------------------------------------------------------------------------
    |
    | "claude" sends each item to the Anthropic API. "fake" uses a deterministic
    | keyword classifier so the app and its tests run without an API key.
    |
    */

    'classifier' => env('MODERATION_CLASSIFIER', 'claude'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],

    // Classification calls allowed per minute across all queue workers.
    'ai_calls_per_minute' => (int) env('MODERATION_AI_CALLS_PER_MINUTE', 30),

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    'disk' => env('MODERATION_DISK', 'local'),

    'max_upload_kb' => (int) env('MODERATION_MAX_UPLOAD_KB', 8192),

    // Longest edge of the stored, re-encoded image (also what the model sees).
    'max_dimension' => 1568,

    'thumbnail_dimension' => 320,

    /*
    |--------------------------------------------------------------------------
    | Policy defaults
    |--------------------------------------------------------------------------
    |
    | Used for the first policy version. Admins change them on the Policy page,
    | which saves a new version.
    |
    */

    // Share of automated decisions sent to a human for a QA audit (0-1).
    'audit_sample_rate' => (float) env('MODERATION_AUDIT_SAMPLE_RATE', 0.05),

    // Largest Hamming distance between perceptual hashes that counts as a match (0-64).
    'blocklist_distance' => 6,

    /*
    |--------------------------------------------------------------------------
    | Review workflow
    |--------------------------------------------------------------------------
    */

    'claim_minutes' => 10,

    'appeal_window_days' => 14,

];
