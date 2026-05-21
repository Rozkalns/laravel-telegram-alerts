<?php

declare(strict_types=1);

return [

    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    'chat_id' => env('TELEGRAM_CHAT_ID', ''),

    'log_level' => env('TELEGRAM_LOG_LEVEL', 'error'),

    'queue_failures' => true,

    'slow_response_threshold' => (int) env('TELEGRAM_SLOW_RESPONSE_THRESHOLD', 0),

    'slow_response_exclude' => ['/health', '/up'],

    'scheduler_heartbeat' => (bool) env('TELEGRAM_SCHEDULER_HEARTBEAT', false),

    'backup_path' => env('TELEGRAM_BACKUP_PATH', ''),

    'backup_max_age_hours' => 25,

    'backup_min_size_bytes' => 1024,

    'retry_attempts' => (int) env('TELEGRAM_RETRY_ATTEMPTS', 3),

    'ci_webhook' => (bool) env('TELEGRAM_CI_WEBHOOK', false),

    'ci_webhook_secret' => env('TELEGRAM_CI_WEBHOOK_SECRET', ''),

];
