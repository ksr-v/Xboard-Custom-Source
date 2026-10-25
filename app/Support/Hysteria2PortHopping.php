<?php

namespace App\Support;

/** Parse the deliberately small port syntax accepted for managed UDP forwarding. */
final class Hysteria2PortHopping
{
    public static function parsePort(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        if (!preg_match('/\A[0-9]{1,5}\z/', (string) $value)) {
            return null;
        }
        $port = (int) $value;
        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /** @return array{start: int, end: int}|null */
    public static function parseRange(mixed $value): ?array
    {
        if (!is_string($value) || !preg_match('/\A([0-9]{1,5})-([0-9]{1,5})\z/', $value, $matches)) {
            return null;
        }
        $start = self::parsePort($matches[1]);
        $end = self::parsePort($matches[2]);
        if ($start === null || $end === null || $start > $end) {
            return null;
        }
        return ['start' => $start, 'end' => $end];
    }
}
