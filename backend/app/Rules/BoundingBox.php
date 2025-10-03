<?php



namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BoundingBox implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute field must be a comma separated list of coordinates.');

            return;
        }

        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 4) {
            $fail('The :attribute field must contain west,south,east,north values.');

            return;
        }

        [$west, $south, $east, $north] = array_map('floatval', $parts);

        if ($west === $east || $south === $north) {
            $fail('The :attribute field must describe a rectangular bounding box.');

            return;
        }

        if ($west < -180 || $west > 180 || $east < -180 || $east > 180) {
            $fail('Longitude values in :attribute must be within -180 and 180 degrees.');

            return;
        }

        if ($south < -90 || $south > 90 || $north < -90 || $north > 90) {
            $fail('Latitude values in :attribute must be within -90 and 90 degrees.');

            return;
        }

        if ($west >= $east) {
            $fail('The west coordinate must be less than the east coordinate.');

            return;
        }

        if ($south >= $north) {
            $fail('The south coordinate must be less than the north coordinate.');
        }
    }
}
