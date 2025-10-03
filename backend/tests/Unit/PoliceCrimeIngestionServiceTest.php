<?php

namespace Tests\Unit;

use App\Exceptions\PoliceCrimeIngestionException;
use App\Services\H3IndexService;
use App\Services\PoliceCrimeIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PoliceCrimeIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_throws_domain_exception_when_archive_is_missing(): void
    {
        Http::fake(fn () => Http::response('', 404));

        $h3 = Mockery::mock(H3IndexService::class);
        $h3->shouldIgnoreMissing();
        $this->app->instance(H3IndexService::class, $h3);

        $service = $this->app->make(PoliceCrimeIngestionService::class);

        $this->expectException(PoliceCrimeIngestionException::class);
        $this->expectExceptionMessage('No police crime archive is available for 2025-08 yet.');

        $service->ingest('2025-08');
    }
}
