# Load Testing Quickstart

This project includes a starter [k6](https://k6.io/) script at `tests/perf/player-feed.k6.js` for validating player API behavior under concurrent viewers.

## What it covers

- `GET /api/v1/player/feed` with embed headers
- `POST /api/v1/player/viewer-ping` for active session updates
- Ramp profile from 5 to 150 virtual users
- Basic thresholds for error rate and latency

## Prerequisites

1. Run app services (`php artisan serve`, `php artisan horizon`, `php artisan reverb:start`).
2. Seed at least one active embed and published video.
3. Install k6 (Windows): `choco install k6`

## Run

```bash
k6 run tests/perf/player-feed.k6.js
```

With custom values:

```bash
k6 run -e BASE_URL=http://127.0.0.1:8000 -e EMBED_SLUG=demo-feed -e TEAM_ID=1 tests/perf/player-feed.k6.js
```

## Interpreting results

- Keep `http_req_failed` below `2%`.
- Keep `p95` request latency below `500ms`.
- Keep `p99` request latency below `1200ms`.

If thresholds fail, inspect:

- Horizon queue wait time (`critical`, `realtime`, `analytics`)
- MySQL slow query log for feed/viewer count queries
- Reverb event throughput and dropped websocket sessions
