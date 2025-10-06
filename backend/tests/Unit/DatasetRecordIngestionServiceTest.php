<?php

namespace Tests\Unit;

use App\Exceptions\DatasetRecordIngestionException;
use App\Services\H3IndexService;
use App\Services\DatasetRecordIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DatasetRecordIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_throws_domain_exception_when_archive_is_missing(): void
    {
        Http::fake(fn () => Http::response('', 404));

        $h3 = Mockery::mock(H3IndexService::class);
        $h3->shouldIgnoreMissing();
        $this->app->instance(H3IndexService::class, $h3);

        $service = $this->app->make(DatasetRecordIngestionService::class);

        $this->expectException(DatasetRecordIngestionException::class);
        $this->expectExceptionMessage('No dataset archive is available for 2025-08 yet.');

        $service->ingest('2025-08');
    }
}
