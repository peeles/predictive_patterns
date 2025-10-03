<?php

use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\DatasetController;
use App\Http\Controllers\Api\v1\ExportController;
use App\Http\Controllers\Api\v1\HealthController;
use App\Http\Controllers\Api\v1\HeatmapTileController;
use App\Http\Controllers\Api\v1\HexController;
use App\Http\Controllers\Api\v1\ModelController;
use App\Http\Controllers\Api\v1\NlqController;
use App\Http\Controllers\Api\v1\PredictionController;
use App\Http\Controllers\Api\v1\UserController;
use Illuminate\Support\Facades\Route;

/**
 * Authentication routes shared between versioned and unversioned APIs.
 */
$authRoutes = function (): void {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');
    Route::post('refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:auth-refresh');

    Route::middleware('auth.api')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
};

/**
 * Versioned API routes
 */
Route::prefix('v1')->group(function () use ($authRoutes): void {
    Route::prefix('auth')->group($authRoutes);

    Route::get('/health', HealthController::class);

    Route::middleware('auth.api')->group(function (): void {
        Route::get('/datasets', [DatasetController::class, 'index']);
        Route::get('/datasets/runs', [DatasetController::class, 'runs']);
        Route::post('/datasets/ingest', [DatasetController::class, 'ingest']);
        Route::get('/datasets/{dataset}', [DatasetController::class, 'show']);
        Route::get('/datasets/{dataset}/analysis', [DatasetController::class, 'analysis']);

        Route::get('/hexes', [HexController::class, 'index']);
        Route::get('/hexes/geojson', [HexController::class, 'geoJson']);
        Route::get('/heatmap/{z}/{x}/{y}', HeatmapTileController::class);

        Route::get('/export', ExportController::class);

        Route::post('/nlq', NlqController::class);

        Route::get('/models', [ModelController::class, 'index']);
        Route::post('/models', [ModelController::class, 'store']);
        Route::get('/models/{id}', [ModelController::class, 'show']);
        Route::get('/models/{id}/artifacts', [ModelController::class, 'artifacts']);
        Route::get('/models/{id}/metrics', [ModelController::class, 'metrics']);
        Route::get('/models/{id}/status', [ModelController::class, 'status']);
        Route::post('/models/train', [ModelController::class, 'train']);
        Route::post('/models/{id}/evaluate', [ModelController::class, 'evaluate']);
        Route::post('/models/{id}/activate', [ModelController::class, 'activate']);
        Route::post('/models/{id}/deactivate', [ModelController::class, 'deactivate']);
        Route::post('/models/{id}/rollback', [ModelController::class, 'rollback']);

        Route::get('/predictions', [PredictionController::class, 'index']);
        Route::post('/predictions', [PredictionController::class, 'store']);
        Route::get('/predictions/{id}', [PredictionController::class, 'show']);

        Route::patch('/users/{user}/role', [UserController::class, 'assignRole']);
        Route::apiResource('users', UserController::class)->only([
            'index',
            'store',
            'update',
            'destroy',
        ]);
    });
});

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is running',
        'timestamp' => now()->toDateTimeString(),
        'services' => [
            'sanctum' => class_exists('Laravel\Sanctum\Sanctum'),
            'reverb' => class_exists('Laravel\Reverb\ReverbServiceProvider'),
            'horizon' => class_exists('Laravel\Horizon\Horizon'),
        ]
    ]);
});
