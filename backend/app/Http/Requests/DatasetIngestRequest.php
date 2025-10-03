<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Rules\ValidGeoJson;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use function pathinfo;
use const PATHINFO_FILENAME;
use JsonException;

class DatasetIngestRequest extends FormRequest
{
    use ResolvesRoles;

    protected function prepareForValidation(): void
    {
        $this->decodeJsonArrayInput('metadata');
        $this->decodeJsonArrayInput('schema');

        $uploadedFiles = $this->resolveUploadedFiles();

        if (! $this->filled('name') && $uploadedFiles !== []) {
            $firstFile = $uploadedFiles[0];
            $originalName = $firstFile->getClientOriginalName() ?? '';
            $inferredName = (string) pathinfo($originalName, PATHINFO_FILENAME);

            if ($inferredName === '' && $originalName !== '') {
                $inferredName = $originalName;
            }

            if ($inferredName !== '') {
                $this->merge(['name' => substr($inferredName, 0, 255)]);
            }
        }

        if (! $this->filled('source_type') && $uploadedFiles !== []) {
            $this->merge(['source_type' => 'file']);
        }
    }

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return in_array($role, [Role::Admin, Role::Analyst], true);
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        $maxKb = max((int) config('api.payload_limits.ingest', 204_800), 1);
        $mimeRules = config('api.allowed_ingest_mimes', []);
        $baseFileRules = array_filter([
            'file',
            'max:' . $maxKb,
            $mimeRules !== [] ? 'mimetypes:' . implode(',', $mimeRules) : null,
            new ValidGeoJson(),
        ]);

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_type' => ['required', Rule::in(['file', 'url'])],
            'file' => array_merge([
                Rule::requiredIf(fn () => $this->input('source_type') === 'file' && $this->resolveUploadedFiles() === []),
            ], $baseFileRules),
            'files' => ['nullable', 'array'],
            'files.*' => $baseFileRules,
            'source_uri' => ['required_if:source_type,url', 'url'],
            'metadata' => ['nullable', 'array'],
            'schema' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function resolveUploadedFiles(): array
    {
        $files = Arr::wrap($this->file('files'));
        $files = array_filter($files, static fn ($file) => $file instanceof UploadedFile);

        if ($files !== []) {
            return array_values($files);
        }

        $single = $this->file('file');

        if ($single instanceof UploadedFile) {
            return [$single];
        }

        return [];
    }

    private function decodeJsonArrayInput(string $key): void
    {
        $value = $this->input($key);

        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (is_array($decoded)) {
            $this->merge([$key => $decoded]);
        }
    }
}
