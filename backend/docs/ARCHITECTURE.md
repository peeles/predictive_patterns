# Predictive Patterns backend architecture

## High-level system map
- **Laravel API** – REST endpoints under `/api` expose spatial aggregations, exports, NLQ, and ingestion controls. Controllers such as the hex aggregation handler enforce Sanctum-protected access and throttle profile-specific rate limits to keep H3 queries deterministic under load.【F:backend/app/Http/Controllers/Api/v1/HexController.php†L12-L53】
- **Spatial core** – H3 conversion, aggregation, and geometry services wrap whichever PHP bindings are available and fall back to a bundled Node helper, so the same code path works in local containers and production nodes.【F:backend/app/Services/H3IndexService.php†L18-L66】【F:backend/app/Services/H3GeometryService.php†L17-L65】
- **Background workers** – Queueable jobs download, normalise, and archive datasets. Laravel Horizon supervises workers and restricts dashboard access to privileged roles.【F:backend/app/Jobs/IngestRemoteDataset.php†L36-L138】【F:backend/app/Providers/HorizonServiceProvider.php†L15-L37】
- **Realtime events** – Domain events broadcast dataset lifecycle updates over private Sockudo (Pusher-compatible) channels, keeping the SPA and MCP clients synchronised.【F:backend/app/Events/DatasetStatusUpdated.php†L8-L61】【F:backend/app/Services/DatasetProcessingService.php†L286-L327】

## Authentication and request safety
- **Session model** – Tokens are minted through `SanctumTokenManager`, which issues paired access (60-minute TTL) and refresh (30-day TTL) tokens tagged with a session UUID. Refresh requests revoke the old pair before issuing replacements to prevent replay attacks.【F:backend/app/Support/SanctumTokenManager.php†L12-L88】
- **Request guards** – `EnsureApiTokenIsValid` accepts either a Bearer or `X-API-Key` token, resolves the backing `PersonalAccessToken`, and binds the authenticated user into the request context. Expired or invalid tokens are deleted eagerly.【F:backend/app/Http/Middleware/EnsureApiTokenIsValid.php†L19-L57】
- **Rate limiting** – Per-feature limiters dynamically scope to the caller’s resolved role and user identifier to keep ingestion, model training, and auth endpoints responsive under bursty traffic.【F:backend/app/Providers/AppServiceProvider.php†L60-L119】【F:backend/app/Providers/AppServiceProvider.php†L200-L217】
- **Horizon access control** – Horizon routes run behind the same role resolver, so only operators with `canManageModels()` privileges can reach the dashboard in non-local environments.【F:backend/app/Providers/HorizonServiceProvider.php†L19-L32】

## Spatial analytics pipeline
- **Index resolution** – `H3IndexService` prefers native PHP bindings (`latLngToCell` / `geoToH3`) when installed. When the extension is missing, it launches a long-lived Node.js helper, caches up to 10,000 lookups per worker, and shuts the daemon down as the service is destructed.【F:backend/app/Services/H3IndexService.php†L40-L88】
- **Aggregation service** – `H3AggregationService` converts bbox filters, applies temporal/category constraints, and returns DTO-backed aggregates ready for HTTP or MCP responses. Results are cached for 10 minutes using tag-aware stores so cache busting can target specific resolutions or months after ingests.【F:backend/app/Services/H3AggregationService.php†L24-L108】【F:backend/app/Services/H3AggregationService.php†L70-L108】【F:backend/app/Services/H3AggregationService.php†L560-L608】
- **Geometry generation** – GeoJSON responses derive polygon coordinates through the geometry service, which normalises output from different H3 bindings and guards against malformed vertices.【F:backend/app/Services/H3GeometryService.php†L17-L116】

## Dataset ingestion and automation
- **Remote ingestion job** – `IngestRemoteDataset` streams remote archives to local storage, verifies the checksum, persists metadata, and hands off to the processing service. Failures roll back metadata, emit failure events, and bubble exceptions for retries.【F:backend/app/Jobs/IngestRemoteDataset.php†L54-L156】
- **Processing service** – `DatasetProcessingService` normalises schema mappings, dispatches progress updates, and broadcasts `DatasetStatusUpdated` events. Event fakes used during testing still trigger broadcast payloads so downstream listeners remain exercised.【F:backend/app/Services/DatasetProcessingService.php†L41-L327】
- **Queue topology** – `config/queue.php` provisions a dedicated `training` connection whose driver automatically degrades to the best available backend (Redis preferred, database fallback) and honours long retry windows suited to ML workloads.【F:backend/config/queue.php†L17-L112】
- **Horizon supervision** – Horizon’s Redis-backed metrics, memory ceilings, and notification hooks stay configurable through `config/horizon.php`; operators can harden dashboards via the generated gate stub.【F:backend/config/horizon.php†L9-L182】【F:backend/app/Providers/HorizonServiceProvider.php†L33-L40】

## Caching and operational safeguards
- **Ephemeral caches during migrations** – Console invocations that mutate the schema switch the cache driver to in-memory array storage to avoid stale tags or Redis misses during deploys.【F:backend/app/Providers/AppServiceProvider.php†L91-L119】
- **Node helper cache** – When using the Node fallback, index lookups are memoised per worker with an LRU eviction policy so high-volume conversions avoid repeated child-process round trips.【F:backend/app/Services/H3IndexService.php†L40-L66】【F:backend/app/Services/H3IndexService.php†L214-L236】
- **Aggregate tagging** – Cached aggregation entries include resolution and month tags, enabling fine-grained invalidation after dataset ingests or backfills complete.【F:backend/app/Services/H3AggregationService.php†L24-L108】【F:backend/app/Services/H3AggregationService.php†L560-L608】

## Where to go next
- **Backend README** – Environment variables, QA tooling, and end-to-end setup (Docker, Sockudo, queue workers).【F:backend/README.md†L1-L172】
- **Dataset controller deep dive** – End-to-end flow for dataset ingestion and validation logic (`docs/architecture/dataset-controller.md`).
- **Frontend docs** – See the SPA repository for consumption patterns of broadcast events and heatmap endpoints.
