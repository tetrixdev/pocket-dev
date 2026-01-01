<?php

namespace App\Services;

use App\Tools\Tool;
use Illuminate\Support\Collection;

/**
 * Centralized helper for filtering tools by allowed slugs.
 * Used by ToolRegistry, ToolSelector, and other tool-related services.
 */
class ToolFilterHelper
{
    /**
     * Check if a tool's slug is in the allowed list (case-insensitive).
     *
     * @param string $slug The tool slug to check
     * @param array|null $allowedTools Array of allowed slugs (null = allow all)
     * @return bool True if tool is allowed
     */
    public static function isAllowed(string $slug, ?array $allowedTools): bool
    {
        if ($allowedTools === null) {
            return true;
        }

        $normalizedSlugs = array_map('mb_strtolower', $allowedTools);
        return in_array(mb_strtolower($slug), $normalizedSlugs, true);
    }

    /**
     * Normalize an array of tool slugs to lowercase.
     *
     * @param array|null $allowedTools Array of slugs to normalize
     * @return array|null Normalized array or null
     */
    public static function normalizeSlugs(?array $allowedTools): ?array
    {
        if ($allowedTools === null) {
            return null;
        }

        return array_map('mb_strtolower', $allowedTools);
    }

    /**
     * Filter a collection of tools by allowed slugs.
     *
     * @param Collection $tools Collection of Tool objects
     * @param array|null $allowedTools Array of allowed slugs (null = allow all)
     * @return Collection Filtered collection
     */
    public static function filterCollection(Collection $tools, ?array $allowedTools): Collection
    {
        if ($allowedTools === null) {
            return $tools;
        }

        $normalizedSlugs = self::normalizeSlugs($allowedTools);

        return $tools->filter(function (Tool $tool) use ($normalizedSlugs) {
            return in_array(mb_strtolower($tool->getSlug()), $normalizedSlugs, true);
        });
    }
}
