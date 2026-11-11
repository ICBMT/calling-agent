<?php

namespace App\Support;

class Duration
{
    public static function format(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = max(0, (int) $seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
