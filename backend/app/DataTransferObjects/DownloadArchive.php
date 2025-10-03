<?php

namespace App\DataTransferObjects;

class DownloadArchive
{
    public function __construct(
        public string $path,
        public int    $bytes,
        public string $checksum,
        public string $url,
    ) {
    }
}
