# Queue & Horizon Runbook

## Overview
Training and other heavy-weight model operations now run on a dedicated `training` queue. Laravel Horizon supervises this queue separately from the default workload to provide clearer observability and to ensure resource isolation.

## Queue Responsibilities
- `default`: Handles lightweight API and background jobs.
- `training`: Executes `TrainModelJob` instances and any other CPU intensive work.

## Worker Layout
Horizon defines two supervisors:
- **supervisor-1** &mdash; manages the `default` queue with automatic balancing.
- **supervisor-training** &mdash; manages the `training` queue with simple balancing and a higher memory ceiling. In production it scales up to four concurrent processes (configurable via `HORIZON_TRAINING_MAX_PROCESSES`).

Each training worker honors the `TRAINING_QUEUE_TIMEOUT` (default 3600 seconds) so long-running jobs terminate gracefully.

## Operational Tasks
### Starting Horizon locally
```bash
php artisan horizon
```
This command boots both supervisors. Training jobs will appear under the `training` queue in the Horizon dashboard.

### Monitoring
The Horizon dashboard exposes per-queue metrics:
- Navigate to `/horizon`.
- Use the **Metrics** tab to review snapshots for both `default` and `training` queues.
- Configure alerting or external dashboards via Horizon's notification routes if required.

### Scaling guidance
- Increase `HORIZON_TRAINING_MAX_PROCESSES` when concurrent training capacity is required and infrastructure resources allow.
- Lower the value (or adjust `TRAINING_QUEUE_TIMEOUT`) if memory pressure or long-running jobs impact stability.
- For emergency draining, run `php artisan horizon:pause training` to stop new training jobs and `php artisan horizon:continue training` to resume.

### Troubleshooting
1. **Jobs stuck in queued state**
   - Verify Redis connectivity and that Horizon is running.
   - Confirm `TRAINING_QUEUE_DRIVER` is `redis`; Horizon workers require Redis-backed queues.
   - Check worker logs for memory limit or timeout exceptions.
2. **Training metrics missing**
   - Ensure `php artisan horizon:snapshot` is scheduled (included in Laravel's default scheduler).
   - Confirm the queue name in dispatched jobs matches `TRAINING_QUEUE`.

## References
- `.env` variables: `TRAINING_QUEUE_*`, `HORIZON_TRAINING_*`.
- Configuration files: `config/queue.php`, `config/horizon.php`.
