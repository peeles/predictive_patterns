<?php

namespace App\Http\Middleware;

use App\Support\SanctumTokenManager;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;

class EncryptCookies extends BaseEncryptCookies
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        SanctumTokenManager::REFRESH_COOKIE_NAME,
    ];
}
