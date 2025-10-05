<?php

namespace Tests\Unit\Support\Filesystem;

use App\Support\Filesystem\CsvCombiner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvCombinerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_combine_merges_multiple_csv_files(): void
    {
        $combiner = new CsvCombiner();

        $first = UploadedFile::fake()->createWithContent('first.csv', "id,name\n1,Alice\n");
        $second = UploadedFile::fake()->createWithContent('second.csv', "id,name\n2,Bob\n");

        [$path, $mime] = $combiner->combine([$first, $second]);

        $this->assertSame('text/csv', $mime);
        Storage::disk('local')->assertExists($path);

        $combined = Storage::disk('local')->get($path);

        $this->assertStringContainsString("1,Alice", $combined);
        $this->assertStringContainsString("2,Bob", $combined);
        $this->assertSame(1, substr_count($combined, 'id,name'));
    }
}
