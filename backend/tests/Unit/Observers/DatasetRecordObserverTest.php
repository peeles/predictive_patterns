<?php

declare(strict_types=1);

use App\Models\DatasetRecord;
use App\Services\H3AggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->aggregationService = app(H3AggregationService::class);
});

it('invalidates cache when a dataset record is created', function (): void {
    $initialVersion = $this->aggregationService->cacheVersion();

    DatasetRecord::factory()->create([
        'occurred_at' => now(),
        'lat' => 51.5074,
        'lng' => -0.1278,
        'category' => 'test-category',
    ]);

    $newVersion = $this->aggregationService->cacheVersion();

    expect($newVersion)->toBeGreaterThan($initialVersion);
});

it('invalidates cache when a dataset record is updated', function (): void {
    $record = DatasetRecord::factory()->create([
        'occurred_at' => now(),
        'lat' => 51.5074,
        'lng' => -0.1278,
        'category' => 'test-category',
    ]);

    // Wait for cache to stabilize after creation
    $versionAfterCreate = $this->aggregationService->cacheVersion();

    $record->update([
        'category' => 'updated-category',
    ]);

    $versionAfterUpdate = $this->aggregationService->cacheVersion();

    expect($versionAfterUpdate)->toBeGreaterThan($versionAfterCreate);
});

it('invalidates cache when a dataset record is deleted', function (): void {
    $record = DatasetRecord::factory()->create([
        'occurred_at' => now(),
        'lat' => 51.5074,
        'lng' => -0.1278,
        'category' => 'test-category',
    ]);

    $versionAfterCreate = $this->aggregationService->cacheVersion();

    $record->delete();

    $versionAfterDelete = $this->aggregationService->cacheVersion();

    expect($versionAfterDelete)->toBeGreaterThan($versionAfterCreate);
});

it('handles cache invalidation failures gracefully', function (): void {
    // Mock the H3AggregationService to throw an exception
    $mockService = Mockery::mock(H3AggregationService::class);
    $mockService->shouldReceive('invalidateAggregatesForRecords')
        ->andThrow(new RuntimeException('Cache service unavailable'));

    $this->app->instance(H3AggregationService::class, $mockService);

    // This should not throw an exception - the observer catches and logs it
    $record = DatasetRecord::factory()->create([
        'occurred_at' => now(),
        'lat' => 51.5074,
        'lng' => -0.1278,
        'category' => 'test-category',
    ]);

    expect($record)->toBeInstanceOf(DatasetRecord::class);
    expect($record->exists)->toBeTrue();
});

it('invalidates cache for multiple operations in sequence', function (): void {
    $version1 = $this->aggregationService->cacheVersion();

    $record1 = DatasetRecord::factory()->create(['category' => 'cat1']);
    $version2 = $this->aggregationService->cacheVersion();

    $record2 = DatasetRecord::factory()->create(['category' => 'cat2']);
    $version3 = $this->aggregationService->cacheVersion();

    $record1->update(['category' => 'cat1-updated']);
    $version4 = $this->aggregationService->cacheVersion();

    expect($version2)->toBeGreaterThan($version1);
    expect($version3)->toBeGreaterThan($version2);
    expect($version4)->toBeGreaterThan($version3);
});