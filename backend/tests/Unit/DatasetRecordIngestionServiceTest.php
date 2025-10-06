<?php

namespace Tests\Unit;

use App\Exceptions\DatasetRecordIngestionException;
use App\Events\DatasetIngestionRunUpdated;
use App\Exceptions\PoliceCrimeIngestionException;
use App\Services\H3IndexService;
use App\Services\DatasetRecordIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
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
        Event::fake([DatasetIngestionRunUpdated::class]);

        try {
            $service->ingest('2025-08');
            $this->fail('Expected PoliceCrimeIngestionException was not thrown.');
        } catch (PoliceCrimeIngestionException $exception) {
            $this->assertSame('No police crime archive is available for 2025-08 yet.', $exception->getMessage());
        }

        Event::assertDispatched(DatasetIngestionRunUpdated::class, function (DatasetIngestionRunUpdated $event): bool {
            return $event->status === 'failed' && $event->message !== null;
        });
    }
}
