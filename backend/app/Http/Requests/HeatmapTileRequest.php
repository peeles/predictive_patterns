<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class HeatmapTileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'z' => ['required', 'integer', 'between:0,22'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'ts_start' => ['nullable', 'date'],
            'ts_end' => ['nullable', 'date', 'after_or_equal:ts_start'],
            'horizon' => ['nullable', 'integer', 'min:0', 'max:336'],
        ];
    }

    public function validationData(): array
    {
        $route = $this->route();
        $parameters = method_exists($route, 'parametersWithoutNulls')
            ? $route->parametersWithoutNulls()
            : ($route?->parameters() ?? []);

        return array_merge(parent::validationData(), $parameters);
    }

    protected function passedValidation(): void
    {
        $zoom = $this->zoom();
        $maxIndex = 1 << $zoom;

        if ($this->tileX() >= $maxIndex || $this->tileY() >= $maxIndex) {
            throw ValidationException::withMessages([
                'tile' => ['Tile coordinates are outside the valid range for this zoom level.'],
            ]);
        }
    }

    public function zoom(): int
    {
        return (int) ($this->input('z') ?? $this->route('z'));
    }

    public function tileX(): int
    {
        return (int) ($this->input('x') ?? $this->route('x'));
    }

    public function tileY(): int
    {
        return (int) ($this->input('y') ?? $this->route('y'));
    }

    public function horizonHours(): ?int
    {
        $value = $this->input('horizon');

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function startTime(): ?CarbonImmutable
    {
        $value = $this->input('ts_start');

        if ($value === null || $value === '') {
            return null;
        }

        return new CarbonImmutable($value);
    }

    public function endTime(?CarbonImmutable $start = null): ?CarbonImmutable
    {
        $value = $this->input('ts_end');

        if ($value !== null && $value !== '') {
            return new CarbonImmutable($value);
        }

        $horizon = $this->horizonHours();

        if ($start && $horizon !== null && $horizon > 0) {
            return $start->addHours($horizon);
        }

        return null;
    }
}
