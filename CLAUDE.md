# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel package (`rozkalns/laravel-telegram-alerts`) that sends production errors and deploy notifications to Telegram. It registers a Monolog handler as a Laravel log channel and provides an artisan deploy-notify command.

- **Namespace:** `Rozkalns\TelegramAlerts`
- **PHP:** 8.5+, **Laravel:** 13
- **License:** Beerware

## Commands

```bash
# Run full test suite (lint, type coverage, typos, unit tests, static analysis, rector)
composer test

# Individual checks
composer test:lint       # pint --test
composer test:unit       # pest --coverage --exactly=100
composer test:types      # phpstan
composer test:refactor   # rector --dry-run
composer test:type-coverage  # pest --type-coverage --exactly=100
composer test:typos      # peck

# Auto-fix
composer lint            # pint (fix style)
composer refactor        # rector (apply refactors)
```

## Quality Gates

All of these must pass — they are enforced by `composer test`:

- **100% code coverage** (pest `--exactly=100`)
- **100% type coverage** (pest type-coverage `--exactly=100`)
- **Zero lint violations** (pint)
- **Zero phpstan errors**
- **Zero rector suggestions**
- **Zero typos** (peck)

## Architecture

The package has two features, both auto-registered by the service provider:

1. **Error logging** — `TelegramHandler` (Monolog `AbstractProcessingHandler`) is registered as a `telegram` log channel in `logging.channels`. When included in `LOG_STACK`, it sends formatted error messages to Telegram via the Bot API. Messages are rate-limited (1 per unique message per 60s via cache) and truncated at 3000 chars. Failed sends are silently swallowed with `rescue()`.

2. **Deploy command** — `NotifyDeployCommand` (`telegram:notify-deploy`) sends a deploy notification including the latest git commit. It's a fire-and-forget artisan command for deploy scripts.

Both features read `bot_token` and `chat_id` from `config/telegram-alerts.php` (backed by env vars `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`). If either is empty, they silently no-op.

## Code Style

- All files use `declare(strict_types=1)`
- All classes are `final`
- Uses Laravel 13 features: `#[Signature]`/`#[Description]` attributes, `config()->string()`
- Config follows laravel/pao conventions: `phpstan.neon.dist`, `rector.php`, `pint.json`, `phpunit.xml.dist`
- Dev environment uses `orchestra/testbench` for testing and static analysis
- `peck` (typo checker) requires `aspell` system package
