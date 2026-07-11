<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolConflict extends Model
{
    // Conflict type constants
    public const TYPE_EQUIVALENT = 'equivalent';
    public const TYPE_INCOMPATIBLE = 'incompatible';

    protected $fillable = [
        'tool_a_slug',
        'tool_b_slug',
        'conflict_type',
        'resolution_hint',
    ];

    /**
     * Find a conflict between two tools (checks both directions).
     */
    public static function findConflict(string $slugA, string $slugB): ?self
    {
        return static::where(function ($q) use ($slugA, $slugB) {
            $q->where('tool_a_slug', $slugA)
              ->where('tool_b_slug', $slugB);
        })->orWhere(function ($q) use ($slugA, $slugB) {
            $q->where('tool_a_slug', $slugB)
              ->where('tool_b_slug', $slugA);
        })->first();
    }

    /**
     * Get all conflicts for a specific tool.
     */
    public static function getConflictsFor(string $slug): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('tool_a_slug', $slug)
            ->orWhere('tool_b_slug', $slug)
            ->get();
    }

    /**
     * Check if two tools conflict.
     */
    public static function hasConflict(string $slugA, string $slugB): bool
    {
        return static::findConflict($slugA, $slugB) !== null;
    }

    /**
     * Get the other tool in this conflict.
     */
    public function getOtherTool(string $slug): string
    {
        return $this->tool_a_slug === $slug ? $this->tool_b_slug : $this->tool_a_slug;
    }

    /**
     * Check if this is an equivalence conflict.
     */
    public function isEquivalent(): bool
    {
        return $this->conflict_type === self::TYPE_EQUIVALENT;
    }

    /**
     * Check if this is an incompatibility conflict.
     */
    public function isIncompatible(): bool
    {
        return $this->conflict_type === self::TYPE_INCOMPATIBLE;
    }
}
