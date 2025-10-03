<?php

namespace App\Enums;

enum CrimeIngestionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
