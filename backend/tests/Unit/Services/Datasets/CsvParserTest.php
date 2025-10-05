<?php

namespace Tests\Unit\Services\Datasets;

use App\Services\Datasets\CsvParser;
use PHPUnit\Framework\TestCase;

class CsvParserTest extends TestCase
{
    private CsvParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new CsvParser();
    }

    public function test_normalise_headers_trims_values(): void
    {
        $headers = $this->parser->normaliseHeaders(["\xEF\xBB\xBF id ", null, ' name']);

        $this->assertSame(['id', '', 'name'], $headers);
    }

    public function test_is_empty_row_detects_content(): void
    {
        $this->assertTrue($this->parser->isEmptyRow(['', null]));
        $this->assertFalse($this->parser->isEmptyRow(['', 'value']));
    }

    public function test_combine_row_discards_blank_columns(): void
    {
        $row = $this->parser->combineRow(['id', '', 'name'], ['1', 'skip', 'Alice']);

        $this->assertSame(['id' => '1', 'name' => 'Alice'], $row);
    }

    public function test_read_csv_rows_streams_associative_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-parser-');
        file_put_contents($path, "id,name\n1,Alice\n2,Bob\n");

        $rows = iterator_to_array($this->parser->readCsvRows($path));

        unlink($path);

        $this->assertSame([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ], $rows);
    }
}
