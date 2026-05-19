# Reliable Telegram Delivery
Priority: High | Status: Done

## Background

All Telegram sends are currently wrapped in `rescue()`, which silently swallows any failure — network errors, Telegram API rate limits (429), server errors (5xx), or invalid token (401). This means critical alerts (error notifications, queue failures) can be lost with zero indication that delivery failed.

The problem is amplified when multiple projects share the same bot and chat ID. If two projects fire alerts at the same moment, Telegram may rate-limit one, and `rescue()` hides the rejection.

The current approach was a pragmatic starting point: never let a failed Telegram send crash the app. But "never crash" and "silently lose alerts" aren't the only two options.

## Scope

### In Scope

- Retry logic in `TelegramClient::send()` for transient failures (429, 5xx)
- Configurable retry count and backoff
- Distinguish critical sends (synchronous with retry) from non-critical (queueable)
- Log a warning when all retries are exhausted so the failure is at least visible in the app log

### Out of Scope

- Delivery confirmation / read receipts from Telegram
- Fallback channels (email, Slack) when Telegram is down
- Persistent message queue or database-backed outbox
- Custom retry strategies per alert type

## Implementation

### Phase 1: Smart retry in TelegramClient

Replace the blind `rescue()` with retry logic that inspects the HTTP response:

- **429 Too Many Requests** — Telegram includes a `Retry-After` header. Respect it, retry once.
- **5xx Server Error** — retry up to 2 times with exponential backoff (1s, 3s).
- **401/403 Unauthorized** — do not retry. Token is bad. Log a warning.
- **Network exception** — retry up to 2 times with 1s delay.
- **2xx Success** — done.
- **Other 4xx** — do not retry. Log a warning.

After all retries exhausted, log a `warning` to the app's default log channel (not the `telegram` channel — that would cause infinite recursion). Never throw.

```php
// TelegramClient::send() pseudocode
public function send(string $text): void
{
    if (! $this->isConfigured()) {
        return;
    }

    $maxAttempts = 3;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $response = Http::timeout(5)->post(...);

            if ($response->successful()) {
                return;
            }

            if ($response->status() === 429) {
                $retryAfter = (int) $response->header('Retry-After', '1');
                sleep(min($retryAfter, 5));
                continue;
            }

            if ($response->serverError() && $attempt < $maxAttempts) {
                sleep($attempt); // 1s, 2s backoff
                continue;
            }

            // 4xx (not 429) — don't retry
            Log::warning('Telegram alert delivery failed', [
                'status' => $response->status(),
            ]);
            return;

        } catch (Throwable) {
            if ($attempt < $maxAttempts) {
                sleep($attempt);
                continue;
            }

            Log::warning('Telegram alert delivery failed after retries');
            return;
        }
    }
}
```

**Config:**
```php
'retry_attempts' => 3,  // 0 = no retry (current behavior)
```

### Phase 2: Queueable sends for non-critical messages

Add `sendQueued(string $text): void` to `TelegramClient` that dispatches a job. The job uses the same retry logic from Phase 1.

Callers decide which to use:
- `send()` — synchronous with retry. For error alerts, queue failures.
- `sendQueued()` — dispatched to the queue. For heartbeat, deploy notifications, backup alerts.

The queued job should use Laravel's built-in `$tries` and `$backoff` instead of custom retry loops.

**Circular dependency safeguard:** `QueueFailureListener` must always use `send()` (synchronous), never `sendQueued()`. If the queue is down, queuing a "queue is down" alert defeats the purpose.

## Files Affected

| File | Changes |
|------|---------|
| `src/TelegramClient.php` | Replace `rescue()` with retry loop, add `sendQueued()` |
| `src/Commands/HeartbeatCommand.php` | Switch to `sendQueued()` |
| `src/Commands/NotifyDeployCommand.php` | Switch to `sendQueued()` |
| `src/Commands/CheckBackupCommand.php` | Switch to `sendQueued()` |
| `config/telegram-alerts.php` | Add `retry_attempts` |
| `tests/TelegramClientTest.php` | Test retry on 429, 5xx, network error, and exhaustion warning |

## Technical Considerations

- **`sleep()` in synchronous sends** — retries with `sleep(1)` add up to ~6s worst case (3 attempts). This is acceptable for error handlers and queue failure listeners which aren't in the request path. The slow response middleware fires in `terminate()` (after response sent), so sleep is fine there too.
- **Infinite recursion guard** — when logging a warning about failed delivery, the warning must NOT go to the `telegram` log channel. Use `Log::channel('single')->warning(...)` or `Log::withoutChannels(['telegram'])->warning(...)` to avoid triggering another Telegram send.
- **Testing** — use `Http::fake()` with sequenced responses: `Http::sequence()->push(status: 429, headers: ['Retry-After' => '1'])->push(status: 200)` to test retry behavior. Mock `sleep` via `$this->travelTo()` or accept the real delay in tests (retries are 1-3s each).
- **Rate limit key per chat** — Telegram rate limits are per-chat, not per-bot. Multiple projects sharing a bot but using different chat IDs won't interfere with each other.

## Related

- [Auto-Monitoring Alerts](auto-monitoring-alerts.md) — all monitoring features use `TelegramClient::send()` and will benefit from retries
- [Daily Project Digest](daily-digest.md) — the scheduled digest would use `sendQueued()` since it's not time-critical
