## Appendix A: Useful Commands

### Queue Management
```bash
php artisan queue:work --tries=3 --timeout=90
php artisan queue:work training --timeout=3600 --memory=1024
php artisan horizon
php artisan horizon:pause
php artisan horizon:continue
php artisan horizon:terminate
```

# Queue Inspection
php artisan queue:failed
php artisan queue:retry all
php artisan queue:forget {id}
php artisan queue:flush

# Broadcasting
php artisan queue:work broadcasts --tries=3 --timeout=30

# Maintenance
php artisan queue:prune-failed --hours=168
php artisan horizon:snapshot
php artisan cache:clear
php artisan config:cache

# Monitoring
php artisan horizon:list
php artisan queue:monitor redis:default,redis:training --max=100

Appendix B: Useful Monitoring Queries
php// Check queue size
Redis::llen('queues:default');
Redis::llen('queues:training');

// Check processing jobs
Redis::scard('queues:default:processing');

// Check failed jobs
DB::table('failed_jobs')->count();
DB::table('failed_jobs')->latest()->take(10)->get();

// Check job batches
DB::table('job_batches')->where('pending_jobs', '>', 0)->get();

// Horizon metrics
$metrics = [
    'jobs_per_minute' => Horizon::jobsProcessedPerMinute(),
    'failed_per_minute' => Horizon::failedJobsPerMinute(),
    'wait_time' => Horizon::averageWaitTime(),
    'process_time' => Horizon::averageProcessTime(),
];
