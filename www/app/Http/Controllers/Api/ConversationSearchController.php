<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConversationSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationSearchController extends Controller
{
    public function __construct(
        private ConversationSearchService $searchService
    ) {}

    /**
     * Search conversations semantically.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:500',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if (!$this->searchService->isAvailable()) {
            return response()->json([
                'error' => 'Search service not available. Please configure OpenAI API key.',
            ], 503);
        }

        $results = $this->searchService->search(
            $validated['query'],
            $validated['limit'] ?? 20
        );

        return response()->json([
            'results' => $results->values(),
        ]);
    }
}
