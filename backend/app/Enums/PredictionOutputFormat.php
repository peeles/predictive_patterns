<?php

namespace App\Enums;

enum PredictionOutputFormat: string
{
    case Json = 'json';
    case Tiles = 'tiles';
}
