<?php

namespace App\Support;

final class Format
{
    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }

    public static function duration(float $seconds): string
    {
        $s = (int) $seconds;
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return "{$d}d {$h}h";
        }
        if ($h > 0) {
            return "{$h}h {$m}m";
        }
        return "{$m}m";
    }

    public static function timestamp(int $unix): string
    {
        if ($unix <= 0) {
            return '—';
        }

        return gmdate('Y-m-d H:i', $unix);
    }

    public static function password(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
