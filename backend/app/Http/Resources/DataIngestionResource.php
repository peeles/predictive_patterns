<?php

namespace App\Http\Resources;

use App\Enums\CrimeIngestionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataIngestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'month' => $this->month,
            'dry_request' => (bool)$this->dry_request,
            'status' => $this->status instanceof CrimeIngestionStatus
                ? $this->status->value
                : (string)$this->status,
            'records_detected' => $this->records_detected,
            'records_expected' => $this->records_expected,
            'records_inserted' => $this->records_inserted,
            'records_existing' => $this->records_existing,
            'error_message' => $this->error_message,
            'archive_checksum' => $this->archive_checksum,
            'archive_url' => $this->archive_url,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
