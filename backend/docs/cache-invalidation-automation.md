# Automatic Cache Invalidation

## Overview

The H3 aggregation cache now automatically invalidates when dataset records are created, updated, or deleted through the `DatasetRecordObserver`. This ensures aggregation queries always return fresh data without requiring manual cache management.

## Implementation

### DatasetRecordObserver

**Location**: `backend/app/Observers/DatasetRecordObserver.php`

Listens to Eloquent model events:
- `created` - Invalidates cache after record insertion
- `updated` - Invalidates cache after record modification
- `deleted` - Invalidates cache after record removal

The observer automatically:
1. Extracts H3 cell and temporal data from the affected record
2. Bumps the global cache version
3. Flushes relevant cache tags (month-specific and resolution-specific)
4. Logs cache invalidation for debugging
5. Handles failures gracefully (logs but doesn't fail the transaction)

### Registration

**Location**: `backend/app/Providers/AppServiceProvider.php`

```php
DatasetRecord::observe(DatasetRecordObserver::class);
```

Registered alongside other observers in the `boot()` method.

## Benefits

### Before: Manual Invalidation

```php
// Developer had to remember to invalidate cache
$record = DatasetRecord::create($data);
$h3Service->invalidateAggregatesForRecords([$record]);
```

**Problems:**
- Easy to forget
- Inconsistent across codebase
- Manual invalidation in batch operations only
- Single record CRUD operations had stale cache

### After: Automatic Invalidation

```php
// Cache automatically invalidates
$record = DatasetRecord::create($data);
// ✅ Cache is fresh
```

**Advantages:**
- ✅ Always consistent
- ✅ Zero developer overhead
- ✅ Works for single records and batch operations
- ✅ Covers all CRUD operations (create, update, delete)
- ✅ Framework-level integration (can't forget)

## Cache Invalidation Strategy

### Tag-Based Invalidation

When tagging is supported (Redis):

```
h3_aggregations:tag:all
h3_aggregations:tag:resolution:6
h3_aggregations:tag:resolution:7
h3_aggregations:tag:resolution:8
h3_aggregations:tag:month:2024-03
h3_aggregations:tag:month:2024-04
```

Observer flushes tags matching the affected record's:
- All resolutions (6, 7, 8)
- Month (extracted from `occurred_at`)

### Version-Based Invalidation

When tagging not supported (file/database cache):

```
h3_aggregations:version: 1 → 2 → 3
```

Cache keys include version number:
```
h3_aggregations:2:abc123def456...
```

Version bump invalidates all keys globally.

## Performance Considerations

### Single Record Operations

**Impact**: Negligible (<5ms per operation)
- Version increment: 1 Redis command
- Tag flush: 1-3 Redis commands (per resolution × month)

### Batch Operations

**Impact**: Still handled efficiently
- `RecordBatchInserter` calls invalidation once per batch (500 records)
- Observer fires after individual insert (e.g., factory in tests)
- Both approaches trigger same invalidation logic

**Note**: Bulk inserts via `DB::table()->insert()` don't fire observers. Use `Model::insert()` or batch insertion services.

## Testing

### Unit Tests

**Location**: `backend/tests/Unit/Observers/DatasetRecordObserverTest.php`

Tests verify:
- ✅ Cache invalidation on create
- ✅ Cache invalidation on update
- ✅ Cache invalidation on delete
- ✅ Graceful failure handling
- ✅ Sequential operations increment version correctly

### Feature Tests

**Location**: `backend/tests/Feature/HexAggregationTest.php`

Updated test: `test_cached_results_refresh_after_cache_version_bump`

**Before**: Tested that cache returned stale data until manual bump
**After**: Tests that cache returns fresh data immediately after record creation

## Migration Notes

### Breaking Changes

None. The API remains the same.

### Behavioral Changes

1. **Immediate invalidation**: Aggregation queries now see new data immediately instead of after TTL expiration (10 minutes)

2. **Test expectations**: Tests that verify cached data is stale after record creation will fail and should be updated to expect fresh data

3. **Manual invalidation still works**: `bumpCacheVersion()` can still be called explicitly for edge cases

## Debugging

### Log Messages

**Success**:
```
DEBUG: H3 aggregation cache invalidated
  event: created
  record_count: 1
  record_ids: ["abc-123"]
```

**Failure**:
```
WARNING: Failed to invalidate H3 aggregation cache
  event: updated
  record_count: 1
  error: Cache service unavailable
```

### Cache Version Monitoring

Check current version:
```php
app(H3AggregationService::class)->cacheVersion();
```

Check if tagging is supported:
```php
app(H3AggregationService::class)->supportsTagging();
```

## Future Enhancements

1. **Batch optimization**: Detect bulk operations and invalidate once instead of per-record
2. **Selective invalidation**: Only invalidate affected H3 cells instead of global invalidation
3. **Cache warming**: Pre-populate cache after invalidation for frequently accessed regions
4. **Metrics**: Track invalidation frequency and cache hit rates

## Related Files

- `backend/app/Observers/DatasetRecordObserver.php` - Observer implementation
- `backend/app/Services/H3/H3CacheManager.php` - Cache invalidation logic
- `backend/app/Services/H3AggregationService.php` - Public invalidation API
- `backend/app/Services/DatasetIngestion/RecordBatchInserter.php` - Batch invalidation (still used)
- `backend/tests/Unit/Observers/DatasetRecordObserverTest.php` - Unit tests
- `backend/tests/Feature/HexAggregationTest.php` - Feature tests