<?php

namespace App\Services;

use App\Models\MemoryEmbedding;
use App\Models\MemoryObject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $dimensions;

    public function __construct(AppSettingsService $settings)
    {
        // API key from database (set via UI, uses OpenAI key)
        $this->apiKey = $settings->getOpenAiApiKey() ?? '';
        $this->baseUrl = config('ai.embeddings.base_url', 'https://api.openai.com');
        $this->model = config('ai.embeddings.model', 'text-embedding-3-small');
        $this->dimensions = config('ai.embeddings.dimensions', 1536);
    }

    /**
     * Generate embedding for a single text.
     *
     * @return array<float>|null
     */
    public function embed(string $text): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('EmbeddingService: No API key configured');
            return null;
        }

        if (empty($text)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v1/embeddings', [
                'model' => $this->model,
                'input' => $text,
                'dimensions' => $this->dimensions,
            ]);

            if (!$response->successful()) {
                Log::error('EmbeddingService: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['data'][0]['embedding'] ?? null;
        } catch (\Exception $e) {
            Log::error('EmbeddingService: Exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts in a batch.
     *
     * @param array<string> $texts
     * @return array<array<float>>|null Array of embeddings in same order as input
     */
    public function embedBatch(array $texts): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('EmbeddingService: No API key configured');
            return null;
        }

        // Filter out empty texts but preserve indices
        $nonEmptyTexts = array_filter($texts, fn($t) => !empty($t));
        if (empty($nonEmptyTexts)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/v1/embeddings', [
                'model' => $this->model,
                'input' => array_values($nonEmptyTexts),
                'dimensions' => $this->dimensions,
            ]);

            if (!$response->successful()) {
                Log::error('EmbeddingService: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $embeddings = [];
            foreach ($data['data'] as $item) {
                $embeddings[$item['index']] = $item['embedding'];
            }

            // Map back to original indices
            $result = [];
            $embeddingIndex = 0;
            foreach ($texts as $originalIndex => $text) {
                if (!empty($text)) {
                    $result[$originalIndex] = $embeddings[$embeddingIndex] ?? null;
                    $embeddingIndex++;
                } else {
                    $result[$originalIndex] = null;
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('EmbeddingService: Exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate and store embeddings for a memory object's embeddable fields.
     */
    public function embedObject(MemoryObject $object): void
    {
        $structure = $object->structure;
        if (!$structure) {
            return;
        }

        $embeddableFields = $structure->getEmbeddableFields();
        if (empty($embeddableFields)) {
            return;
        }

        // Collect field contents and check for changes
        $fieldsToEmbed = [];
        foreach ($embeddableFields as $fieldPath) {
            $content = $object->getField($fieldPath);
            if (!is_string($content) || empty($content)) {
                continue;
            }

            $contentHash = MemoryEmbedding::hashContent($content);

            // Check if embedding already exists and is up to date
            $existing = $object->embeddings()
                ->where('field_path', $fieldPath)
                ->first();

            if ($existing && !$existing->hasContentChanged($contentHash)) {
                continue; // Content hasn't changed, skip
            }

            $fieldsToEmbed[$fieldPath] = [
                'content' => $content,
                'hash' => $contentHash,
                'existing' => $existing,
            ];
        }

        if (empty($fieldsToEmbed)) {
            return;
        }

        // Generate embeddings in batch
        $contents = array_column($fieldsToEmbed, 'content');
        $embeddings = $this->embedBatch($contents);

        if ($embeddings === null) {
            return;
        }

        // Store embeddings
        $fieldPaths = array_keys($fieldsToEmbed);
        foreach ($fieldPaths as $index => $fieldPath) {
            $embedding = $embeddings[$index] ?? null;
            if ($embedding === null) {
                continue;
            }

            $fieldData = $fieldsToEmbed[$fieldPath];
            $existing = $fieldData['existing'];

            if ($existing) {
                // Update existing embedding
                $existing->content_hash = $fieldData['hash'];
                $existing->embedding = $embedding;
                $existing->save();
            } else {
                // Create new embedding
                MemoryEmbedding::create([
                    'object_id' => $object->id,
                    'field_path' => $fieldPath,
                    'content_hash' => $fieldData['hash'],
                    'embedding' => $embedding,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Delete all embeddings for a memory object.
     */
    public function deleteObjectEmbeddings(MemoryObject $object): void
    {
        $object->embeddings()->delete();
    }

    /**
     * Check if the embedding service is configured and available.
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get the configured embedding dimensions.
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
