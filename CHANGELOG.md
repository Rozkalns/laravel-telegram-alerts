# Changelog

All notable changes to `rozkalns/laravel-telegram-alerts` will be documented in this file.

## v0.1.3

### Fixed

- Slow response alerts now include the full request URI with query string instead of just the path ([#4](https://github.com/Rozkalns/laravel-telegram-alerts/issues/4))
- Rate-limit cache key for slow responses now includes query parameters, so the same path with different query strings triggers separate alerts

### Upgrade notes

No breaking changes. After updating, slow response alerts will show the full URI:

```diff
- GET /articles/show
+ GET /articles/show?n=1&layout=overlay&width=1920
```

Rate limiting now treats each unique path+query combination separately. If you previously relied on a single path being rate-limited regardless of query string, be aware that different query strings will now produce individual alerts.

## v0.1.2

Initial tagged release with error alerts, deploy notifications, queue failure alerts, slow response detection, scheduler heartbeat, and backup verification.
