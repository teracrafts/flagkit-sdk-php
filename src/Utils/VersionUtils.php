<?php

declare(strict_types=1);

namespace FlagKit\Utils;

/**
 * Semantic version comparison utilities for SDK version metadata handling.
 *
 * These utilities are used to compare the current SDK version against
 * server-provided version requirements (min, recommended, latest).
 */
class VersionUtils
{
    /** Maximum allowed value for version components (defensive limit). */
    private const MAX_VERSION_COMPONENT = 999999999;

    /**
     * Parse a semantic version string into numeric components.
     * Returns null if the version is not a valid semver.
     *
     * @return array{major: int, minor: int, patch: int}|null
     */
    public static function parseVersion(string $version): ?array
    {
        // Trim whitespace
        $trimmed = trim($version);
        if (empty($trimmed)) {
            return null;
        }

        // Strip leading 'v' or 'V' if present
        $normalized = (str_starts_with($trimmed, 'v') || str_starts_with($trimmed, 'V'))
            ? substr($trimmed, 1)
            : $trimmed;

        // Match semver pattern (allows pre-release suffix but ignores it for comparison)
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $normalized, $matches)) {
            return null;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        // Validate components are within reasonable bounds
        if ($major < 0 || $major > self::MAX_VERSION_COMPONENT ||
            $minor < 0 || $minor > self::MAX_VERSION_COMPONENT ||
            $patch < 0 || $patch > self::MAX_VERSION_COMPONENT) {
            return null;
        }

        return [
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
        ];
    }

    /**
     * Compare two semantic versions.
     * Returns:
     *  - negative number if a < b
     *  - 0 if a == b
     *  - positive number if a > b
     *
     * Returns 0 if either version is invalid.
     */
    public static function compareVersions(string $a, string $b): int
    {
        $parsedA = self::parseVersion($a);
        $parsedB = self::parseVersion($b);

        if ($parsedA === null || $parsedB === null) {
            return 0;
        }

        // Compare major
        if ($parsedA['major'] !== $parsedB['major']) {
            return $parsedA['major'] - $parsedB['major'];
        }

        // Compare minor
        if ($parsedA['minor'] !== $parsedB['minor']) {
            return $parsedA['minor'] - $parsedB['minor'];
        }

        // Compare patch
        return $parsedA['patch'] - $parsedB['patch'];
    }

    /**
     * Check if version a is less than version b.
     */
    public static function isVersionLessThan(string $a, string $b): bool
    {
        return self::compareVersions($a, $b) < 0;
    }

    /**
     * Check if version a is greater than or equal to version b.
     */
    public static function isVersionAtLeast(string $a, string $b): bool
    {
        return self::compareVersions($a, $b) >= 0;
    }
}
