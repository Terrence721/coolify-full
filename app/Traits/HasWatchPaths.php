<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

/**
 * Watch-path glob matching for deciding whether a set of modified files
 * should trigger a redeploy. Extracted from App\Models\Application.
 */
trait HasWatchPaths
{
    private function parseWatchPaths(?string $value): ?string
    {
        if ($value) {
            $watch_paths = collect(explode("\n", $value))
                ->map(function (string $path): string {
                    // Trim whitespace
                    $path = trim($path);

                    if (str_starts_with($path, '!')) {
                        $negation = '!';
                        $pathWithoutNegation = substr($path, 1);
                        $pathWithoutNegation = ltrim(trim($pathWithoutNegation), '/');

                        return $negation.$pathWithoutNegation;
                    }

                    return ltrim($path, '/');
                })
                ->filter(function (string $path): bool {
                    return strlen($path) > 0;
                });

            return trim($watch_paths->implode("\n"));
        }

        return null;
    }

    public function watchPaths(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    return $this->parseWatchPaths($value);
                }
            }
        );
    }

    public function matchWatchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        return self::matchPaths($modified_files, $watch_paths);
    }

    /**
     * Static method to match paths against watch patterns with negation support
     * Uses order-based matching: last matching pattern wins
     */
    public static function matchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        if (is_null($watch_paths) || $watch_paths->isEmpty()) {
            return collect([]);
        }

        return $modified_files->filter(function ($file) use ($watch_paths) {
            $shouldInclude = null; // null means no patterns matched

            // Process patterns in order - last match wins
            foreach ($watch_paths as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) {
                    continue;
                }

                $isExclusion = str_starts_with($pattern, '!');
                $matchPattern = $isExclusion ? substr($pattern, 1) : $pattern;

                if (self::globMatch($matchPattern, $file)) {
                    // This pattern matches - it determines the current state
                    $shouldInclude = ! $isExclusion;
                }
            }

            // If no patterns matched and we only have exclusion patterns, include by default
            if ($shouldInclude === null) {
                // Check if we only have exclusion patterns
                $hasInclusionPatterns = $watch_paths->contains(fn ($p) => ! str_starts_with(trim($p), '!'));

                return ! $hasInclusionPatterns;
            }

            return $shouldInclude;
        })->values();
    }

    /**
     * Check if a path matches a glob pattern
     * Supports: *, **, ?, [abc], [!abc]
     */
    public static function globMatch(string $pattern, string $path): bool
    {
        $regex = self::globToRegex($pattern);

        return preg_match($regex, $path) === 1;
    }

    /**
     * Convert a glob pattern to a regular expression
     */
    public static function globToRegex(string $pattern): string
    {
        $regex = '';
        $inGroup = false;
        $chars = str_split($pattern);
        $len = count($chars);

        for ($i = 0; $i < $len; $i++) {
            $c = $chars[$i];

            switch ($c) {
                case '*':
                    // Check for **
                    if ($i + 1 < $len && $chars[$i + 1] === '*') {
                        // ** matches any number of directories
                        $regex .= '.*';
                        $i++; // Skip next *
                        // Skip optional /
                        if ($i + 1 < $len && $chars[$i + 1] === '/') {
                            $i++;
                        }
                    } else {
                        // * matches anything except /
                        $regex .= '[^/]*';
                    }
                    break;

                case '?':
                    // ? matches any single character except /
                    $regex .= '[^/]';
                    break;

                case '[':
                    // Character class
                    $inGroup = true;
                    $regex .= '[';
                    // Check for negation
                    if ($i + 1 < $len && ($chars[$i + 1] === '!' || $chars[$i + 1] === '^')) {
                        $regex .= '^';
                        $i++;
                    }
                    break;

                case ']':
                    if ($inGroup) {
                        $inGroup = false;
                        $regex .= ']';
                    } else {
                        $regex .= preg_quote($c, '#');
                    }
                    break;

                case '.':
                case '(':
                case ')':
                case '+':
                case '{':
                case '}':
                case '$':
                case '^':
                case '|':
                case '\\':
                    // Escape regex special characters
                    $regex .= '\\'.$c;
                    break;

                default:
                    $regex .= $c;
                    break;
            }
        }

        // Wrap in delimiters and anchors
        return '#^'.$regex.'$#';
    }

    public function normalizeWatchPaths(): void
    {
        if (is_null($this->watch_paths)) {
            return;
        }

        $normalized = $this->parseWatchPaths($this->watch_paths);
        if ($normalized !== $this->watch_paths) {
            $this->watch_paths = $normalized;
            $this->save();
        }
    }

    public function isWatchPathsTriggered(Collection $modified_files): bool
    {
        if (is_null($this->watch_paths)) {
            return false;
        }

        $this->normalizeWatchPaths();

        $watch_paths = collect(explode("\n", $this->watch_paths));

        if ($watch_paths->isEmpty()) {
            return false;
        }
        $matches = $this->matchWatchPaths($modified_files, $watch_paths);

        return $matches->count() > 0;
    }
}
