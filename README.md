# Laravel Telegram Alerts

Send production errors and deploy notifications to Telegram. Zero config — install the package, add three env vars, done.

## Features

- **Error alerts** — ERROR+ log entries sent to Telegram with exception class, file, and line number
- **Deploy notifications** — artisan command to announce successful deploys
- **Project identification** — `[APP_NAME]` prefix on every message, so one bot handles all your projects
- **Rate limiting** — max 1 message per unique error per minute to avoid notification storms
- **Auto-registration** — the log channel and command register themselves via the service provider

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require rozkalns/laravel-telegram-alerts:dev-main
```

The package auto-discovers — no manual service provider registration needed.

## Setup

### 1. Create a Telegram Bot (one-time)

1. Open Telegram, message **@BotFather**
2. Send `/newbot` and follow the prompts
3. Save the bot token

One bot works for all your projects.

### 2. Get Your Chat ID

1. Message your bot on Telegram (send anything)
2. Run on any server with the token configured:
   ```bash
   php artisan tinker --execute '
   $token = config("telegram-alerts.bot_token");
   $response = Http::get("https://api.telegram.org/bot{$token}/getUpdates");
   dump($response->json());
   '
   ```
3. Find `"chat": {"id": 123456789}` in the response

For group chats: add the bot to the group, send a message, then check. Group IDs are negative numbers.

### 3. Configure .env

```env
TELEGRAM_BOT_TOKEN=your-bot-token
TELEGRAM_CHAT_ID=your-chat-id
LOG_STACK=single,telegram
```

Clear the config cache if needed:

```bash
php artisan config:clear
```

### 4. Deploy Notifications (optional)

Add to the end of your deploy script:

```bash
php artisan telegram:notify-deploy
```

## What You Get

### Error notification

```
🚨 [MyApp] ERROR

`Class "SomeClass" not found`

📄 `app/Http/Controllers/OrderController.php:42`
💥 `Error`

📍 https://myapp.com (production)
🕐 2026-05-19 10:06:55 UTC
```

### Deploy notification

```
✅ [MyApp] deployed

`a1b2c3d feat: add payment processing`

📍 https://myapp.com
🕐 2026-05-19 10:14:20 UTC
```

## Configuration

The package works with zero configuration out of the box. If you need to customize, publish the config:

```bash
php artisan vendor:publish --tag=telegram-alerts-config
```

This creates `config/telegram-alerts.php`:

```php
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),
];
```

The log channel level defaults to `error`. Override with:

```env
LOG_TELEGRAM_LEVEL=critical
```

## How It Works

The package registers a `telegram` channel in Laravel's logging system via its service provider. When `LOG_STACK` includes `telegram`, any log entry at the configured level or above is sent to your Telegram chat.

- The Monolog handler uses Laravel's `Http` facade with a 5-second timeout
- Failed Telegram API calls are silently swallowed (via `rescue()`) so they never break your app
- Rate limiting uses the cache to deduplicate — 1 message per unique error message per 60 seconds
- If cache is unavailable, rate limiting is skipped and the message sends anyway

## License

MIT
