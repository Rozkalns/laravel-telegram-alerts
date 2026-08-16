<?php

declare(strict_types=1);

return [

    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    'chat_id' => env('TELEGRAM_CHAT_ID', ''),

    'log_level' => env('TELEGRAM_LOG_LEVEL', 'error'),

    'queue_failures' => true,

    'slow_response_threshold' => (int) env('TELEGRAM_SLOW_RESPONSE_THRESHOLD', 0),

    'slow_response_exclude' => ['/health', '/up'],

    // How long one component/route stays deduplicated, in seconds. Suppressed
    // repeats are counted and surfaced in the next alert, never dropped silently.
    'slow_response_dedup_window' => (int) env('TELEGRAM_SLOW_RESPONSE_DEDUP_WINDOW', 900),

    // Resolve PTR + ASN for the request IP so alerts name the caller. Adds DNS
    // lookups after the response has been flushed; results are cached per IP.
    'identify_caller' => (bool) env('TELEGRAM_IDENTIFY_CALLER', true),

    // Total budget for those lookups, checked between queries.
    'identify_caller_budget_ms' => (int) env('TELEGRAM_IDENTIFY_CALLER_BUDGET_MS', 1000),

    // What to do when a *verified* crawler (forward-confirmed reverse DNS)
    // triggers a slow response: 'alert' | 'digest' | 'ignore'. Defaults to
    // alerting — a crawler hitting a slow page is still a slow page.
    'slow_response_bot_policy' => env('TELEGRAM_SLOW_RESPONSE_BOT_POLICY', 'alert'),

    // Dedup window applied to verified crawlers under the 'digest' policy.
    'slow_response_bot_digest_window' => (int) env('TELEGRAM_SLOW_RESPONSE_BOT_DIGEST_WINDOW', 3600),

    // Deployed release identifier (e.g. short git SHA) shown in slow-response
    // alerts so each alert can be tied to a specific deploy. Null unless set.
    'release' => env('TELEGRAM_ALERTS_RELEASE'),

    'slow_query_threshold' => (int) env('TELEGRAM_SLOW_QUERY_THRESHOLD', 100),

    'n_plus_one_threshold' => (int) env('TELEGRAM_N_PLUS_ONE_THRESHOLD', 100),

    'scheduler_heartbeat' => (bool) env('TELEGRAM_SCHEDULER_HEARTBEAT', false),

    'backup_path' => env('TELEGRAM_BACKUP_PATH', ''),

    'backup_max_age_hours' => 25,

    'backup_min_size_bytes' => 1024,

    'retry_attempts' => (int) env('TELEGRAM_RETRY_ATTEMPTS', 3),

    'ci_webhook' => (bool) env('TELEGRAM_CI_WEBHOOK', false),

    'ci_webhook_secret' => env('TELEGRAM_CI_WEBHOOK_SECRET', ''),

    'glitchtip_webhook' => (bool) env('TELEGRAM_GLITCHTIP_WEBHOOK', false),

    'glitchtip_webhook_secret' => env('TELEGRAM_GLITCHTIP_WEBHOOK_SECRET', ''),

];
