<?php

namespace App\Enums;

enum ModelStatus: string
{
    case Draft = 'draft';
    case Training = 'training';
    case Active = 'active';
    case Inactive = 'inactive';
    case Failed = 'failed';
}
