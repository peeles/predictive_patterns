<?php

namespace App\Http\Requests;

use App\Support\SanctumTokenManager;
use Illuminate\Foundation\Http\FormRequest;

class AuthRefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function refreshToken(): ?string
    {
        $token = $this->cookie(SanctumTokenManager::REFRESH_COOKIE_NAME);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
