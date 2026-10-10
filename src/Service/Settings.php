<?php

declare(strict_types=1);

namespace PushLocal\Service;

final class Settings
{
    public const OPTION = 'pushlocal_settings';

    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'keep_latest' => $this->boundedInt($stored['keep_latest'] ?? 10, 1, 50, 10),
            'max_age_days' => $this->boundedInt($stored['max_age_days'] ?? 30, 1, 365, 30),
            'abandoned_upload_hours' => $this->boundedInt(
                $stored['abandoned_upload_hours'] ?? 24,
                1,
                168,
                24
            ),
        ];
    }

    public function update(array $input): array
    {
        $current = $this->all();

        $next = [
            'keep_latest' => $this->boundedInt(
                $input['keep_latest'] ?? $current['keep_latest'],
                1,
                50,
                $current['keep_latest']
            ),
            'max_age_days' => $this->boundedInt(
                $input['max_age_days'] ?? $current['max_age_days'],
                1,
                365,
                $current['max_age_days']
            ),
            'abandoned_upload_hours' => $this->boundedInt(
                $input['abandoned_upload_hours'] ?? $current['abandoned_upload_hours'],
                1,
                168,
                $current['abandoned_upload_hours']
            ),
        ];

        update_option(self::OPTION, $next, false);

        return $next;
    }

    private function boundedInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $number = (int) $value;

        return max($min, min($max, $number));
    }
}
