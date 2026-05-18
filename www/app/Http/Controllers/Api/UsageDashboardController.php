<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClaudeCodeUsageService;
use App\Services\CursorUsageService;
use App\Services\ModelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UsageDashboardController extends Controller
{
    /**
     * Map CLI model aliases/names to their API-equivalent model IDs for pricing.
     * CLI providers use subscription billing (null pricing), so we map to API equivalents.
     */
    private const MODEL_PRICING_MAP = [
        // Claude Code aliases
        'sonnet' => 'claude-sonnet-4-6',
        'opus' => 'claude-opus-4-6',
        'haiku' => 'claude-haiku-4-5-20251001',
        // Codex models
        'gpt-5.4' => 'gpt-5.2-codex',
        'gpt-5.2-codex' => 'gpt-5.2-codex',
        'gpt-5.1-codex-max' => 'gpt-5.1-codex-max',
        'gpt-5.1-codex-mini' => 'gpt-5.1-codex-mini',
        // Cursor model aliases
        'auto' => 'claude-sonnet-4-6',
        'claude-opus-4-7-thinking-high' => 'claude-opus-4-6',
        'claude-opus-4-7-high' => 'claude-opus-4-6',
        'claude-4.6-sonnet-medium' => 'claude-sonnet-4-6',
        'claude-4.5-sonnet' => 'claude-sonnet-4-6',
        'gpt-5.5-medium' => 'gpt-5.2-codex',
        'gpt-5.4-medium' => 'gpt-5.2-codex',
        'composer-2-fast' => 'claude-sonnet-4-6',
    ];

    public function __construct(
        private ClaudeCodeUsageService $claudeUsage,
        private CursorUsageService $cursorUsage,
        private ModelRepository $models,
    ) {}

    /**
     * GET /api/usage/summary
     * Returns aggregated token usage grouped by provider, model, and date.
     */
    public function summary(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 14), 1), 90);

        $cacheKey = "usage_summary_v2_{$days}";

        $data = Cache::remember($cacheKey, 60, function () use ($days) {
            $rows = DB::table('messages as m')
                ->join('conversations as c', 'm.conversation_id', '=', 'c.id')
                ->whereIn('c.provider_type', ['claude_code', 'codex', 'cursor_agent'])
                ->where('m.created_at', '>=', now()->subDays($days))
                ->whereNotNull('m.input_tokens')
                ->selectRaw("
                    c.provider_type,
                    COALESCE(m.model, 'unknown') as model,
                    DATE(m.created_at) as date,
                    SUM(COALESCE(m.input_tokens, 0)) as input_tokens,
                    SUM(COALESCE(m.output_tokens, 0)) as output_tokens,
                    SUM(COALESCE(m.cache_creation_tokens, 0)) as cache_creation_tokens,
                    SUM(COALESCE(m.cache_read_tokens, 0)) as cache_read_tokens,
                    COUNT(DISTINCT c.id) as conversations
                ")
                ->groupBy('c.provider_type', 'm.model', DB::raw('DATE(m.created_at)'))
                ->orderBy('date', 'desc')
                ->get();

            // Compute API equivalent cost for each row
            $rows = $rows->map(function ($row) {
                $row->api_equiv_cost = $this->computeApiEquivCost(
                    $row->model,
                    (int) $row->input_tokens,
                    (int) $row->output_tokens,
                    (int) $row->cache_creation_tokens,
                    (int) $row->cache_read_tokens
                );
                return $row;
            });

            // Distinct conversation rows per bucket — used to count conversations
            // correctly at the rollup level (a conversation active across multiple
            // days/models must not be counted more than once).
            $convRows = DB::table('messages as m')
                ->join('conversations as c', 'm.conversation_id', '=', 'c.id')
                ->whereIn('c.provider_type', ['claude_code', 'codex', 'cursor_agent'])
                ->where('m.created_at', '>=', now()->subDays($days))
                ->whereNotNull('m.input_tokens')
                ->selectRaw("
                    c.provider_type,
                    COALESCE(m.model, 'unknown') as model,
                    DATE(m.created_at) as date,
                    c.id as conversation_id
                ")
                ->distinct()
                ->get();

            // Build by_day: array with provider_type, model, date (for chart)
            $byDay = $rows->map(fn ($r) => [
                'provider_type' => $r->provider_type,
                'model' => $r->model,
                'date' => $r->date,
                'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'cache_creation_tokens' => (int) $r->cache_creation_tokens,
                'cache_read_tokens' => (int) $r->cache_read_tokens,
                'api_equiv_cost' => round($r->api_equiv_cost, 4),
                'conversations' => (int) $r->conversations,
            ])->values();

            // Build per-model summary keyed by model name
            $byModel = [];
            $grouped = $rows->groupBy('model');
            $convByModel = $convRows->groupBy('model');
            $today = now()->toDateString();
            // "week" = today plus the prior 6 days (7 days inclusive).
            $weekAgo = now()->subDays(6)->toDateString();

            foreach ($grouped as $modelId => $group) {
                $first = $group->first();
                $convGroup = $convByModel->get($modelId, collect());
                $byModel[$modelId] = [
                    'provider' => $first->provider_type,
                    'display_name' => $this->getModelDisplayName($modelId),
                    'today' => $this->buildPeriodStats($group->where('date', $today), $convGroup->where('date', $today)),
                    'week' => $this->buildPeriodStats($group->where('date', '>=', $weekAgo), $convGroup->where('date', '>=', $weekAgo)),
                    'total' => $this->buildPeriodStats($group, $convGroup),
                ];
            }

            // Build per-provider summary
            $byProvider = [];

            foreach (['claude_code', 'codex', 'cursor_agent'] as $provider) {
                $pr = $rows->where('provider_type', $provider);
                $cr = $convRows->where('provider_type', $provider);
                $byProvider[$provider] = [
                    'today' => $this->buildPeriodStats($pr->where('date', $today), $cr->where('date', $today)),
                    'week' => $this->buildPeriodStats($pr->where('date', '>=', $weekAgo), $cr->where('date', '>=', $weekAgo)),
                    'total' => $this->buildPeriodStats($pr, $cr),
                ];
            }

            // Grand totals
            $allToday = $rows->where('date', $today);
            $allWeek = $rows->where('date', '>=', $weekAgo);
            $convToday = $convRows->where('date', $today);
            $convWeek = $convRows->where('date', '>=', $weekAgo);

            return [
                'by_day' => $byDay,
                'by_model' => $byModel,
                'by_provider' => $byProvider,
                'totals' => [
                    'today' => $this->buildPeriodStats($allToday, $convToday),
                    'week' => $this->buildPeriodStats($allWeek, $convWeek),
                    'total' => $this->buildPeriodStats($rows, $convRows),
                ],
            ];
        });

        $data['generated_at'] = now()->toIso8601String();

        return response()->json($data);
    }

    /**
     * GET /api/usage/claude-limits
     */
    public function claudeLimits(): JsonResponse
    {
        $utilization = $this->claudeUsage->getUtilization();

        if ($utilization === null) {
            return response()->json([
                'available' => false,
                'reason' => $this->claudeUsage->hasValidToken() ? 'api_error' : 'no_token',
            ]);
        }

        return response()->json([
            'available' => true,
            ...$utilization,
        ]);
    }

    /**
     * GET /api/usage/cursor-limits
     * Returns Cursor usage and subscription data from cursor.com API.
     */
    public function cursorLimits(): JsonResponse
    {
        if (!$this->cursorUsage->hasCredentials()) {
            return response()->json([
                'available' => false,
                'reason' => 'no_credentials',
            ]);
        }

        $data = $this->cursorUsage->getDashboardData();

        if ($data === null) {
            return response()->json([
                'available' => false,
                'reason' => 'api_error',
            ]);
        }

        return response()->json([
            'available' => true,
            ...$data,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  \Illuminate\Support\Collection  $rows      Aggregated token rows for the period.
     * @param  \Illuminate\Support\Collection  $convRows  Distinct conversation rows for the period.
     */
    private function buildPeriodStats($rows, $convRows): array
    {
        $input = (int) $rows->sum('input_tokens');
        $output = (int) $rows->sum('output_tokens');

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cache_creation_tokens' => (int) $rows->sum('cache_creation_tokens'),
            'cache_read_tokens' => (int) $rows->sum('cache_read_tokens'),
            // Count distinct conversations across the whole period so a conversation
            // spanning multiple days/models is not counted more than once.
            'conversations' => $convRows->pluck('conversation_id')->unique()->count(),
            'api_equiv_cost' => round((float) $rows->sum('api_equiv_cost'), 2),
        ];
    }

    private function computeApiEquivCost(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheCreationTokens = 0,
        int $cacheReadTokens = 0,
    ): float {
        // Try direct pricing first
        $pricing = $this->models->getPricing($model);
        if ($pricing && $pricing['input'] > 0) {
            return $this->calcFromPricing($pricing, $inputTokens, $outputTokens, $cacheCreationTokens, $cacheReadTokens);
        }

        // Map alias to API equivalent
        $mapped = self::MODEL_PRICING_MAP[$model] ?? null;
        if ($mapped) {
            $pricing = $this->models->getPricing($mapped);
            if ($pricing && $pricing['input'] > 0) {
                return $this->calcFromPricing($pricing, $inputTokens, $outputTokens, $cacheCreationTokens, $cacheReadTokens);
            }
        }

        // Fallback: Sonnet 4.6 rates
        return ($inputTokens / 1_000_000) * 3.0
             + ($outputTokens / 1_000_000) * 15.0
             + ($cacheCreationTokens / 1_000_000) * 3.75
             + ($cacheReadTokens / 1_000_000) * 0.30;
    }

    private function calcFromPricing(array $p, int $in, int $out, int $cw, int $cr): float
    {
        return ($in / 1_000_000) * $p['input']
             + ($out / 1_000_000) * $p['output']
             + ($cw / 1_000_000) * ($p['cacheWrite'] ?? 0)
             + ($cr / 1_000_000) * ($p['cacheRead'] ?? 0);
    }

    private function getModelDisplayName(string $modelId): string
    {
        $model = $this->models->findByModelId($modelId);
        if ($model) {
            // Strip " (via CLI)" suffix if present
            return str_replace(' (via CLI)', '', $model['display_name']);
        }
        return ucfirst(str_replace(['-', '_'], ' ', $modelId));
    }
}
