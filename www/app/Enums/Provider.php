<?php

namespace App\Enums;

/**
 * AI Provider identifiers.
 *
 * This enum is the single source of truth for provider identifiers.
 * The string values match what's used in configs, database, and JavaScript.
 *
 * NOTE: API providers (Anthropic, OpenAI, OpenAI Compatible) are currently in a
 * partially broken state. The system prompt building and tool formatting is
 * optimized for CLI providers (Claude Code, Codex). API providers may not receive
 * properly formatted tool instructions or memory schema documentation.
 * TODO: Restore full support for API providers.
 */
enum Provider: string
{
    // CLI providers - fully supported
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';

    // API providers - partially broken (tool formatting not optimized)
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case OpenAICompatible = 'openai_compatible';

    /**
     * Get a human-readable label for the provider.
     */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAI => 'OpenAI',
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
            self::OpenAICompatible => 'OpenAI Compatible',
        };
    }

    /**
     * Check if this provider is a CLI-based provider (has native tools).
     */
    public function isCliProvider(): bool
    {
        return match ($this) {
            self::ClaudeCode, self::Codex => true,
            default => false,
        };
    }

    /**
     * Check if this provider is an API-based provider.
     */
    public function isApiProvider(): bool
    {
        return !$this->isCliProvider();
    }

    /**
     * Get all provider values as an array of strings.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all providers as a label => value array (for dropdowns).
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /**
     * Get CLI providers only.
     */
    public static function cliProviders(): array
    {
        return array_filter(self::cases(), fn($p) => $p->isCliProvider());
    }

    /**
     * Get API providers only.
     */
    public static function apiProviders(): array
    {
        return array_filter(self::cases(), fn($p) => $p->isApiProvider());
    }
}
