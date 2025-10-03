<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function canManageModels(): bool
    {
        return $this === self::Admin;
    }

    public function canQueueTraining(): bool
    {
        return $this === self::Admin || $this === self::Analyst;
    }

    public function canEvaluateModels(): bool
    {
        return $this === self::Admin || $this === self::Analyst;
    }

    public function canCreatePredictions(): bool
    {
        return $this !== self::Viewer;
    }

    public function throttleKey(): string
    {
        return match ($this) {
            self::Admin => 'api.rate_limits.admin',
            self::Analyst => 'api.rate_limits.analyst',
            self::Viewer => 'api.rate_limits.viewer',
        };
    }
}
