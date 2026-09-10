<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

/**
 * Strict semver helpers for panel release tags (vX.Y.Z, optional leading v).
 */
final class Semver
{
    public const TAG_PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public static function normalize(string $tag): ?string
    {
        $tag = trim($tag);
        if ($tag === '' || !preg_match(self::TAG_PATTERN, $tag, $m)) {
            return null;
        }

        return 'v' . $m[1] . '.' . $m[2] . '.' . $m[3];
    }

    public static function isValid(string $tag): bool
    {
        return self::normalize($tag) !== null;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    public static function parts(string $tag): ?array
    {
        $norm = self::normalize($tag);
        if ($norm === null) {
            return null;
        }
        $raw = substr($norm, 1);
        [$a, $b, $c] = array_map('intval', explode('.', $raw));

        return [$a, $b, $c];
    }

    /** @return int negative if $a < $b, 0 if equal, positive if $a > $b */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        if ($pa === null || $pb === null) {
            return strcmp(self::normalize($a) ?? $a, self::normalize($b) ?? $b);
        }
        foreach ([0, 1, 2] as $i) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string> normalized, descending (newest first)
     */
    public static function sortDescending(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $n = self::normalize((string) $tag);
            if ($n !== null) {
                $normalized[$n] = true;
            }
        }
        $list = array_keys($normalized);
        usort($list, static fn (string $x, string $y): int => self::compare($y, $x));

        return $list;
    }

    /** @param  list<string>  $tags */
    public static function latest(array $tags): ?string
    {
        $sorted = self::sortDescending($tags);

        return $sorted[0] ?? null;
    }

    /**
     * Suggest closest tag by numeric distance then levenshtein on normalized names.
     *
     * @param  list<string>  $tags
     */
    public static function suggest(string $wanted, array $tags): ?string
    {
        $wantedNorm = self::normalize($wanted) ?? strtolower(trim($wanted));
        $sorted = self::sortDescending($tags);
        if ($sorted === []) {
            return null;
        }
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($sorted as $tag) {
            $score = levenshtein($wantedNorm, $tag);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $tag;
            }
        }

        return $bestScore <= 4 ? $best : $sorted[0];
    }
}
