<?php

namespace App\Enums;

enum DatasetRecordIngestionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
