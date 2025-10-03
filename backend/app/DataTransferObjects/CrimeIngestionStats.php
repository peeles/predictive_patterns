<?php

namespace App\DataTransferObjects;

class CrimeIngestionStats
{
    public function __construct(
        public int $recordsDetected,
        public int $recordsExpected,
        public int $recordsExisting,
        public int $recordsDuplicates,
        public int $recordsInvalid,
    ) {
    }

    public function existingRecords(): int
    {
        return $this->recordsExisting;
    }

    public function skippedRecords(): int
    {
        return $this->recordsDuplicates + $this->recordsInvalid;
    }
}
