# Backend (Laravel API)

The backend is a Laravel 12 application running on PHP 8.2. It serves predictive risk analytics, orchestrates dataset record ingestion pipelines, and exposes a Model Context Protocol (MCP) toolset for automation clients.

## Key features

- **Hex aggregation API** – `/api/hexes` and `/api/hexes/geojson` expose validated aggregation responses with PSR-12 compliant controllers and DTO-backed services.
- **H3 integration** – services gracefully resolve either the PHP H3 extension or compatible FFI bindings at runtime.
- **Dataset record archive ingestion** – resilient downloader normalises, deduplicates, and bulk inserts dataset records with H3 enrichment.
- **MCP support** – `php artisan mcp:serve` exposes the API’s capabilities to Model Context Protocol compatible clients and now includes discovery helpers such as `get_categories`, `list_ingested_months`, and `get_top_cells`.


## Project conventions

- Strict types and [PSR-12](https://www.php-fig.org/psr/psr-12/) formatting across the codebase.
- Request validation lives in `App\Http\Requests`; spatial constraints are handled via dedicated `Rule` classes.
- Services return typed Data Transfer Objects to keep controllers lean and serialisation explicit.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

### Machine learning dependencies

Install [PHP-ML](https://github.com/php-ai/php-ml) to unlock the full training toolchain. The lightweight namespace shims that
ship with the application keep the test suite green when the package is absent, but production and CI builds must depend on the
official library for full algorithm support and optimised math primitives.

```bash
composer require php-ai/php-ml
```

When using Docker, run the same command inside the backend container so the dependency is cached in the image layers and
available to queue workers without an interactive shell step:

```bash
docker compose exec backend composer require php-ai/php-ml
```

Run the automated test suite:

```bash
php artisan test
```

## Queues and background workers

Predictive workloads run across two queues managed by Laravel Horizon:

- **Default queue (`default`)** – handles lightweight API notifications and ingestion events.
- **Training queue (`training`)** – isolates long-running machine-learning jobs so they do not contend with realtime requests.

Start the supervisors locally with Docker:

```bash
docker compose up -d redis
docker compose up -d backend horizon
docker compose exec backend php artisan horizon
```

The Horizon dashboard is available at `http://localhost:8000/horizon` and surfaces per-queue wait times, throughput metrics, and job status. Training jobs dispatched via the `ModelJobFactory` automatically target the `training` connection configured in `config/queue.php`.

For manual queue operations, a few helper commands are available:

```bash
php artisan queue:work               # Process the default queue
php artisan queue:work training      # Process the training queue connection
php artisan horizon:terminate        # Gracefully restart Horizon after code changes
```

See `docs/runbooks/queues.md` for operational guidance, including scaling Horizon supervisors and triaging stalled jobs.

## Realtime broadcasting

Sockudo powers local WebSocket broadcasting by emulating the Pusher protocol. Events are queued onto the `broadcasts` queue connection defined in `config/queue.php`; ensure a Redis-backed queue worker is running to deliver them.

When the WebSocket server is unavailable, broadcasting automatically falls back to the logging driver. You can force this mode during development by exporting:

```bash
export BROADCAST_DRIVER=log
export BROADCAST_FALLBACK_ENABLED=true
export BROADCAST_FALLBACK_CONNECTION=log
```

Events will be written to `storage/logs/laravel.log`, including metadata emitted by `App\Support\Broadcasting\BroadcastDispatcher`. Check the Sockudo health endpoints to confirm when you are ready to switch back to realtime transport:

```bash
curl http://localhost:6001/up/predictive-patterns
curl http://localhost:9601/metrics
```

Both endpoints should return `200 OK` when Sockudo is healthy.

### Quality tooling

Common quality gates can be executed locally via Composer scripts:

```bash
composer update      # Install the QA toolchain defined in require-dev
composer lint        # Laravel Pint (PSR-12)
composer analyse     # LaraStan at level 6
composer test:pest   # Pest with code coverage
```

Generate type-safe refactors in small batches with Rector’s incremental cache at `storage/framework/rector` to keep feedback loops snappy.

## Available commands

| Command | Description |
|---------|-------------|
| `php artisan dataset-records:ingest 2024-03` | Download and import a dataset archive for March 2024. |
| `php artisan schedule:run` | Trigger scheduled ingestion or housekeeping tasks. |
| `php artisan mcp:serve` | Start the Model Context Protocol bridge for the Predictive Patterns API. |

## HTTP endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/hexes` | Aggregated counts for H3 cells intersecting a bounding box. Supports `bbox`, `resolution`, `from`, `to`, and `dataset_type` query parameters. |
| `GET` | `/api/v1/heatmap/{z}/{x}/{y}` | Tile-friendly aggregate payload for the requested XYZ tile. Supports optional `ts_start`, `ts_end`, and `horizon` filters. |
| `GET` | `/api/hexes/geojson` | GeoJSON feature collection for aggregated H3 cells. |
| `GET` | `/api/export` | Download aggregated data as CSV (default) or GeoJSON via `format=geojson`. Accepts the same filters as `/api/hexes`. |
| `POST` | `/api/nlq` | Ask a natural-language question and receive a structured answer describing the translated query. |

### Authentication

Authenticated routes require either an `Authorization: Bearer <token>` header issued by the login endpoint or an `X-API-Key` header when using static API keys. Query string tokens (for example, `?api_token=...`) are not accepted.

## MCP toolset

| Tool | Purpose |
|------|---------|
| `aggregate_hexes` | Aggregate dataset record counts for a bounding box. |
| `export_geojson` | Produce a GeoJSON feature collection for dataset aggregates. |
| `get_categories` | List distinct dataset categories available in the datastore. |
| `get_top_cells` | Return the highest ranking H3 cells for the supplied filters. |
| `ingest_dataset_data` | Queue a background job to ingest a month of dataset records. |
| `list_ingested_months` | Summarise the months currently present in the relational store. |


## Environment variables

Core runtime settings:

| Variable | Purpose | Default |
|----------|---------|---------|
| `APP_ENV` | Application environment (`local`, `staging`, `production`). | `local` |
| `APP_URL` | Base URL used when generating links. | `http://localhost` |
| `API_TOKENS` | Comma-separated list of allowed API tokens for authenticating requests. | _(empty)_ |
| `API_RATE_LIMIT` | Requests per minute allowed for each client IP when using the API. | `60` |
| `API_RATE_LIMIT_AUTH_LOGIN` | Requests per minute allowed for login attempts per email/IP combination. | `10` |
| `API_RATE_LIMIT_AUTH_REFRESH` | Requests per minute allowed for refresh attempts per token/IP combination. | `60` |

Dataset ingestion:

| Variable | Purpose | Default |
|----------|---------|---------|
| `DATASET_RECORD_INGESTION_TEMP_PATH` | Temporary directory for dataset archive downloads. | `storage_path('app/dataset-record-ingestion')` |
| `DATASET_RECORD_INGESTION_NOTIFY_MAIL` | Comma-separated list of email recipients for ingestion failure alerts. | _(empty)_ |
| `DATASET_RECORD_INGESTION_NOTIFY_SLACK_WEBHOOK` | Slack webhook URL for ingestion failure alerts. | _(empty)_ |
| `DATASET_RECORD_INGESTION_PROGRESS_INTERVAL` | Interval (rows) between ingestion progress log entries. | `5000` |
| `DATASET_RECORD_INGESTION_CHUNK_SIZE` | Batch size used when inserting dataset records. | `500` |

Queues and Horizon:

| Variable | Purpose | Default |
|----------|---------|---------|
| `QUEUE_CONNECTION` | Primary queue backend for the application. | `redis` |
| `REDIS_QUEUE_CONNECTION` | Redis connection name used for queue workers. | `default` |
| `REDIS_QUEUE` | Redis list name processed by the default queue worker. | `default` |
| `REDIS_QUEUE_RETRY_AFTER` | Seconds before a stalled Redis job is retried. | `90` |
| `TRAINING_QUEUE_DRIVER` | Backend driver used for the dedicated training queue. | Same as `QUEUE_CONNECTION` |
| `TRAINING_QUEUE_CONNECTION` | Connection name for the training queue. | _(empty)_ |
| `TRAINING_QUEUE` | Queue name processed by training workers. | `training` |
| `TRAINING_QUEUE_RETRY_AFTER` | Seconds before a stalled training job is retried. | `1800` |
| `TRAINING_QUEUE_BLOCK_FOR` | Optional block time (seconds) for long polling on the training queue. | _(empty)_ |
| `HORIZON_PATH` | URI path that serves the Horizon dashboard. | `horizon` |
| `HORIZON_TRAINING_MAX_PROCESSES` | Maximum concurrent training worker processes. | `1` |
| `HORIZON_TRAINING_WAIT` | Minutes a training job may wait before triggering an alert. | `5` |

Broadcasting:

| Variable | Purpose | Default |
|----------|---------|---------|
| `BROADCAST_DRIVER` | Primary broadcast driver. Sockudo (Pusher protocol) is used by default. | `pusher` |
| `BROADCAST_FALLBACK_ENABLED` | Enables automatic fallback when the primary broadcaster is unavailable. | `true` |
| `BROADCAST_FALLBACK_CONNECTION` | Driver used when fallback broadcasting is enabled. | `log` |
| `PUSHER_APP_ID` | Sockudo application identifier used for websocket authentication. | `predictive-patterns` |
| `PUSHER_APP_KEY` | Public Sockudo key shared with SPA clients. | `local-key` |
| `PUSHER_APP_SECRET` | Sockudo application secret used to sign broadcast requests. | `local-secret` |
| `PUSHER_HOST` | Hostname of the Sockudo server. | `localhost` |
| `PUSHER_PORT` | WebSocket port exposed by Sockudo. | `6001` |
| `PUSHER_SCHEME` | Protocol used when connecting to Sockudo (`http` or `https`). | `http` |

Keep secrets such as database credentials and API keys in the `.env` file and never commit them to version control.

## Runbooks

- [Queue and Horizon operations](docs/runbooks/queues.md) – configuring supervisors, diagnosing stuck jobs, and understanding the training queue segregation.

## Model training queue

Long-running model training jobs should be isolated from the default queue so they are not re-queued mid-flight.

1. Configure the training queue connection via the environment variables above. By default the queue uses Redis with a
   generous `retry_after` of 1,800 seconds (30 minutes).
2. Run a dedicated worker that honours the long timeout and higher memory ceiling:

   ```bash
   php artisan queue:work training --timeout=1740 --memory=1024
   ```

   The timeout is deliberately shorter than `retry_after` so the job can finish gracefully without being reclaimed by
   the queue worker supervisor.
3. Monitor the worker's RSS to ensure memory stays within the `--memory` limit. Because CLI processes can leak over
   time, keep an eye on `dmesg` and other system logs for out-of-memory kill events.
4. When supervising the worker with `supervisord`, set `stopwaitsecs` longer than the slowest training run so the
   process receives enough time to shut down cleanly. An example program stanza looks like:

    ```ini
   [program:training-worker]
   command=php /var/www/html/artisan queue:work training --timeout=1740 --memory=1024
   stopwaitsecs=1900
   autostart=true
   autorestart=true
   ```

### Debugging stalled training jobs

- Run queue workers in verbose mode so uncaught exceptions reach the console: `php artisan queue:work training --timeout=1740 --memory=1024 --verbose`. Combine this with `.env` settings such as `APP_DEBUG=true` and `LOG_LEVEL=debug` to ensure PHP error reporting is surfaced instead of being swallowed by output buffering.

## Dataset ingestion queue

- Local file uploads now dispatch a background job to generate previews and derive spatial features. Ensure the default queue worker is running (`php artisan queue:work --timeout=120 --memory=512 --verbose`) so datasets transition from `processing` to `ready` after the HTTP upload completes.
- Failures during background processing will publish a `DatasetStatusUpdated` event with the error message stored in the dataset metadata under `ingest_error`.
- If no messages reach the worker logs, assume the process is being killed externally (for example, container memory limits or supervisor timeouts) and inspect the host's service manager or kernel logs.
- The training service now profiles buffered features and will throw an exception if NaN or infinite values are encountered. Watch for warnings about near-constant or extremely large feature magnitudes—they indicate the dataset needs normalisation before retrying.
- When diagnosing convergence issues, sample the dataset and retry with a much smaller file to confirm the algorithm completes. If the small run works but the full dataset does not, batch the training set or ingest data in windows to stay within memory and timeout budgets.


Based on the project setup, here are the commands to run the test suite:

Backend Tests (Laravel/Pest)

# Run all tests
docker compose exec backend php artisan test

# Run all tests with coverage
docker compose exec backend composer test:pest

# Run specific test file
docker compose exec backend php artisan test tests/Feature/HexAggregationTest.php

# Run specific test class/method
docker compose exec backend php artisan test --filter=ModelApiTest

# Run tests without coverage (faster)
docker compose exec backend php artisan test --no-coverage

# Run unit tests only
docker compose exec backend php artisan test tests/Unit

# Run feature tests only
docker compose exec backend php artisan test tests/Feature

# From inside the backend container (after: make be)
php artisan test
composer test:pest

Frontend Tests (Vitest + Playwright)

# Inside frontend container (make fe) or locally
- npm run test:unit      # Vitest unit tests with coverage
- npm run test:e2e       # Playwright end-to-end tests

### Code Quality Checks

# From inside backend container
- composer lint          # Check PSR-12 compliance
- composer lint:fix      # Auto-fix style issues
- composer analyse       # Run Larastan static analysis

Quick Commands (Makefile)

# From project root
make be                # Enter backend container shell
make fe                # Enter frontend container shell
make fresh             # Reset database with seeders

The most common command for running the full backend test suite is:

docker compose exec backend php artisan test --no-coverage

Or with coverage:

docker compose exec backend composer test:pest
