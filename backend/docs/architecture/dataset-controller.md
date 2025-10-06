# DatasetController Responsibilities

The `DatasetController` orchestrates read and ingest operations for datasets in the v1 API. It now delegates write-heavy behaviour to focused collaborators, which keeps the controller small and oriented around routing and policy checks.

## Operations overview

| Endpoint | Method | Description | Key dependencies |
| --- | --- | --- | --- |
| `index` | `GET /datasets` | Lists datasets with pagination, sorting and optional filters for status, source type and search terms. | `Dataset` Eloquent model, `DatasetResource` (feature count detection), `InteractsWithPagination` helper. |
| `ingest` | `POST /datasets` | Validates and authorises ingestion requests, then delegates persistence and background processing to `DatasetIngestionAction`. Returns the created dataset resource. | `DatasetIngestionAction`, `DatasetResource` (feature count). |
| `show` | `GET /datasets/{dataset}` | Presents a single dataset record with optional feature counts. | `Dataset` model binding, `DatasetResource`. |
| `analysis` | `GET /datasets/{dataset}/analysis` | Provides summary analytics for a dataset. | `DatasetAnalysisService`. |
| `runs` | `GET /dataset-runs` | Lists ingestion runs with filtering and sorting controls. | `DatasetRecordIngestionRun` model, `DataIngestionCollection`. |

## Dependency mapping

- **Authorisation:** All methods rely on Laravel's policy layer (`authorize`) to enforce permissions on `Dataset` resources.
- **Collections & resources:** `DatasetCollection`, `DatasetResource` and `DataIngestionCollection` transform model output for API responses.
- **Domain services:**
  - `DatasetIngestionAction` encapsulates dataset creation, file handling and queuing of ingestion workflows.
  - `DatasetAnalysisService` computes aggregated analytics for the `analysis` endpoint.
- **Supporting infrastructure:**
  - `InteractsWithPagination` resolves pagination limits and sort directions from request input.
  - Eloquent models (`Dataset`, `DatasetRecordIngestionRun`) supply query scopes and persistence.

The refactor isolates ingestion-specific behaviour inside `DatasetIngestionAction` (and the downstream processing services it calls), leaving the controller responsible for validation, authorisation and shaping HTTP responses.
