<?php

namespace App\Http\Requests;

use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;

class RollbackModelRequest extends FormRequest
{
    use ResolvesRoles;

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return $role->canQueueTraining();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string'],
        ];
    }
}
