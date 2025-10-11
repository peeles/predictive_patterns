# Model Training - Quick Reference

## Training Pipeline Overview

```
Dataset → Preprocess → Buffer → Train → Evaluate → Save
  (CSV)     (Stream)    (Temp)   (ML)   (Metrics) (Artifact)
```

## Memory Configuration

| Component | Setting | Value | File |
|-----------|---------|-------|------|
| PHP Memory Limit | `memory_limit` | 2048M | `docker/php.ini` |
| Horizon Worker | `memory` | 1536 MB | `config/horizon.php` |
| Docker Container | `limits.memory` | 3G | `docker-compose.yml` |
| Worker Timeout | `timeout` | 3600s | `config/horizon.php` |

## Training Phases & Memory

| Phase | Progress | Memory | Duration |
|-------|----------|--------|----------|
| Init | 0-10% | 150 MB | 1-5s |
| Dataset Analysis | 10-30% | 300 MB | 10-60s |
| Feature Prep | 30-40% | 800 MB | 30-120s |
| Grid Search | 40-62% | 1200 MB | 2-20m |
| Training | 62-75% | 1000 MB | 1-10m |
| Evaluation | 75-87% | 600 MB | 10-60s |
| Persistence | 87-100% | 400 MB | 5-30s |

## Dataset Size Guidelines

| Dataset Size | Memory | Duration | Recommendation |
|--------------|--------|----------|----------------|
| < 10 MB | 600 MB | 25-30m | ✅ Optimal |
| 10-50 MB | 1200 MB | 1-1.5h | ✅ Good |
| 50-200 MB | 1800 MB | 2-4h | ⚠️ Near limit |
| > 200 MB | 2000+ MB | 4+ h | ❌ Reduce or sample |

## Common Commands

```bash
# Monitor training
docker-compose logs -f horizon

# Check Horizon dashboard
open http://localhost:8000/horizon

# Check memory
curl http://localhost:8000/health/memory

# Retry failed job
php artisan queue:retry {job-id}

# Clear config
php artisan config:clear
php artisan cache:clear
```

## Troubleshooting

### Out of Memory
```ini
# Increase PHP limit
memory_limit=4096M

# Or reduce dataset
$hyperparameters['validation_split'] = 0.1;
```

### Timeout
```env
# Increase timeout
TRAINING_QUEUE_TIMEOUT=7200
```

### Slow Training
```php
# Use faster algorithm
'model_type' => 'naive_bayes',

# Reduce CV folds
'cv_folds' => 3,

# Simplify grid search
$searchGrid = ['learning_rate' => [0.01]];
```

## Algorithm Memory Profiles

| Algorithm | Memory | Speed | Best For |
|-----------|--------|-------|----------|
| Logistic Regression | O(n×d) | Medium | General purpose |
| Naive Bayes | O(d×c) | Fast | Low memory |
| Decision Tree | O(n×d×depth) | Fast | Interpretable |
| SVM | O(n²) | Slow | Small datasets |
| KNN | O(n×d) | Fast | Simple patterns |
| MLP | O(n×d+w) | Medium | Non-linear |

**Legend**: n=samples, d=features, c=classes, w=weights

## Memory Optimization Tips

1. **Sample large datasets**: Use 50% random sample
2. **Reduce CV folds**: Use 3 instead of 5
3. **Simplify grid search**: Fewer hyperparameter combinations
4. **Choose efficient algorithms**: Logistic Regression or Naive Bayes
5. **Clear memory explicitly**: `unset($var); gc_collect_cycles();`

## Key Files

| File | Purpose |
|------|---------|
| `app/Jobs/TrainModelJob.php` | Main training job |
| `app/Services/ModelTrainingService.php` | Training logic |
| `app/Support/DatasetRowBuffer.php` | Memory-efficient data streaming |
| `app/Support/FeatureBuffer.php` | Feature buffering with spillover |
| `config/horizon.php` | Queue worker configuration |
| `docker/php.ini` | PHP runtime settings |

## Monitoring Queries

```sql
-- Active training runs
SELECT id, status, started_at, 
       TIMESTAMPDIFF(MINUTE, started_at, NOW()) as running_minutes
FROM training_runs 
WHERE status = 'running';

-- Recent failures
SELECT id, error_message, finished_at
FROM training_runs 
WHERE status = 'failed' 
ORDER BY finished_at DESC 
LIMIT 10;
```

## Environment Variables

```env
# Required
QUEUE_CONNECTION=redis
TRAINING_QUEUE=training
HORIZON_TRAINING_MEMORY=1536

# Optional tuning
TRAINING_QUEUE_TIMEOUT=3600
HORIZON_TRAINING_MAX_PROCESSES=1
TRAINING_QUEUE_RETRY_AFTER=1800
```

## Quick Health Check

```bash
# All systems
./health-check.sh

# Or manually
docker-compose ps                    # All services up?
curl localhost:8000/health/memory    # Memory OK?
docker-compose logs --tail=20 horizon # Any errors?
```

---
