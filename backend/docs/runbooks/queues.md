# Queue and Horizon Runbook

This runbook explains how predictive-model workloads are isolated onto dedicated queues and how to operate the Horizon dashboards that supervise them.

## Queue segregation

- **Default queue (`default`)** – lightweight API notifications and ingestion events continue to run here. It is processed by the `api` Horizon supervisor.
- **Training queue (`training`)** – long-running `TrainModelJob` and `EvaluateModelJob` instances are dispatched onto the `training` queue connection during job construction. This keeps CPU intensive work off of the default worker pool.

When dispatching jobs manually, call the `dispatch` helper without providing a queue name—the constructors already pin the job to the `training` connection. Example:

```php
TrainModelJob::dispatch($trainingRunId, $hyperparameters, $webhookUrl, $userId);
EvaluateModelJob::dispatch($modelId);
```

Provide the identifier of the user who initiated the training run so the job can authorize itself before execution.

Both jobs will use the queue declared in `config/queue.php` under the `training` connection, which defaults to a Redis-backed `training` queue.

## Horizon dashboards

Horizon now exposes two supervisors:

| Supervisor | Connection | Queues | Purpose |
|------------|------------|--------|---------|
| `api` | Redis (default) | `default` | Handles API facing broadcasts, cache refreshes, and other short tasks. |
| `training` | Redis | `training` | Runs long-lived model training and evaluation jobs with limited parallelism. |

### Local development

Run the development workflow as usual:

```bash
php artisan horizon
```

The `training` supervisor will start with a single process. If you need to process multiple training jobs concurrently, export `HORIZON_LOCAL_TRAINING_MAX_PROCESSES` before launching Horizon.

### Production

In production the `api` supervisor autos-scales between the configured minimum and maximum processes, while the `training` supervisor stays at the conservative concurrency defined by `HORIZON_TRAINING_MAX_PROCESSES`.

Queue wait time alerts in Horizon are configured for both the `default` and `training` queues. If the `training` queue backs up past the `HORIZON_TRAINING_WAIT` threshold (default 5 minutes), investigate stalled jobs and consider temporarily increasing the training supervisor capacity.

## Troubleshooting

1. **Jobs stuck in pending** – confirm that the `training` supervisor is running (`php artisan horizon:status`). Restart Horizon if needed.
2. **Jobs fail immediately** – inspect `storage/logs/laravel.log`. Transport errors from Redis or Pusher are logged with their fallback decisions, including whether credentials were available.
3. **Fallback disabled or missing credentials** – Horizon will continue running, but broadcasts may be logged only. Check the environment variables `BROADCAST_FALLBACK_ENABLED` and `BROADCAST_FALLBACK_CONNECTION`, and ensure the corresponding credentials are present.
