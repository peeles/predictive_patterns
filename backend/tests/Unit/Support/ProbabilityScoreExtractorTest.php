<?php

namespace Tests\Unit\Support;

use App\Support\ProbabilityScoreExtractor;
use PHPUnit\Framework\TestCase;

class ProbabilityScoreExtractorTest extends TestCase
{
    public function test_extract_handles_numeric_values(): void
    {
        $this->assertSame(0.75, ProbabilityScoreExtractor::extract(0.75));
        $this->assertSame(0.25, ProbabilityScoreExtractor::extract('0.25'));
    }

    public function test_extract_prefers_positive_class_keys(): void
    {
        $probabilities = ['0' => 0.2, '1' => 0.8];

        $this->assertSame(0.8, ProbabilityScoreExtractor::extract($probabilities));
    }

    public function test_extract_falls_back_to_highest_probability(): void
    {
        $probabilities = ['burglary' => 0.3, 'assault' => 0.6];

        $this->assertSame(0.6, ProbabilityScoreExtractor::extract($probabilities));
    }

    public function test_extract_list_normalizes_all_values(): void
    {
        $list = [
            ['0' => 0.3, '1' => 0.7],
            ['0' => 0.65, '1' => 0.35],
            0.5,
        ];

        $this->assertSame([0.7, 0.35, 0.5], ProbabilityScoreExtractor::extractList($list));
    }
}
