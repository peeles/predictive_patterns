<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:training' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'api' => [
            'connection' => env('HORIZON_API_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
            'queue' => [env('HORIZON_API_QUEUE', 'default')],
            'balance' => env('HORIZON_API_BALANCE', 'auto'),
            'autoScalingStrategy' => env('HORIZON_API_AUTO_SCALING', 'time'),
            'maxProcesses' => (int) env('HORIZON_API_MAX_PROCESSES', 3),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('HORIZON_API_MEMORY', 128),
            'tries' => (int) env('HORIZON_API_TRIES', 1),
            'timeout' => (int) env('HORIZON_API_TIMEOUT', 90),
            'nice' => (int) env('HORIZON_API_NICE', 0),
        ],
        'training' => [
            'connection' => env('HORIZON_TRAINING_CONNECTION', 'training'),
            'queue' => [env('TRAINING_QUEUE', 'training')],
            'balance' => env('HORIZON_TRAINING_BALANCE', 'simple'),
            'autoScalingStrategy' => env('HORIZON_TRAINING_AUTO_SCALING', 'time'),
            'maxProcesses' => (int) env('HORIZON_TRAINING_MAX_PROCESSES', 1),
            'maxTime' => 0,
            'maxJobs' => (int) env('HORIZON_TRAINING_MAX_JOBS', 1),
            'memory' => (int) env('HORIZON_TRAINING_MEMORY', 512),
            'tries' => (int) env('HORIZON_TRAINING_TRIES', 1),
            'timeout' => (int) env('HORIZON_TRAINING_TIMEOUT', 3600),
            'nice' => (int) env('HORIZON_TRAINING_NICE', 0),
        ],
        'supervisor-training' => [
            'connection' => env('HORIZON_TRAINING_CONNECTION', 'training'),
            'queue' => [env('TRAINING_QUEUE', 'training')],
            'balance' => 'simple',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => (int) env('TRAINING_QUEUE_TIMEOUT', 3600),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'api' => [
                'connection' => env('HORIZON_API_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
                'queue' => [env('HORIZON_API_QUEUE', 'default')],
                'balance' => env('HORIZON_API_BALANCE', 'auto'),
                'minProcesses' => (int) env('HORIZON_API_MIN_PROCESSES', 2),
                'maxProcesses' => (int) env('HORIZON_API_MAX_PROCESSES', 10),
                'balanceMaxShift' => (int) env('HORIZON_API_BALANCE_MAX_SHIFT', 1),
                'balanceCooldown' => (int) env('HORIZON_API_BALANCE_COOLDOWN', 3),
            ],
            'training' => [
                'connection' => env('HORIZON_TRAINING_CONNECTION', 'training'),
                'queue' => [env('TRAINING_QUEUE', 'training')],
                'balance' => env('HORIZON_TRAINING_BALANCE', 'simple'),
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_TRAINING_MAX_PROCESSES', 2),
            ],
            'supervisor-training' => [
                'maxProcesses' => (int) env('HORIZON_TRAINING_MAX_PROCESSES', 4),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 10,
            ],
        ],

        'local' => [
            'api' => [
                'connection' => env('HORIZON_API_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
                'queue' => [env('HORIZON_API_QUEUE', 'default')],
                'maxProcesses' => (int) env('HORIZON_LOCAL_API_MAX_PROCESSES', 2),
            ],
            'training' => [
                'connection' => env('HORIZON_TRAINING_CONNECTION', 'training'),
                'queue' => [env('TRAINING_QUEUE', 'training')],
                'maxProcesses' => (int) env('HORIZON_LOCAL_TRAINING_MAX_PROCESSES', 1),
            ],
            'supervisor-training' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

];
