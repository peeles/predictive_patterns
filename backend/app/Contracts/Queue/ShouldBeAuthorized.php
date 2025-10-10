<?php

namespace App\Contracts\Queue;

interface ShouldBeAuthorized
{
    public function authorize(): bool;
}
