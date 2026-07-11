<?php

namespace App\Console\Commands;

use App\Jobs\GenerateConversationEmbeddings;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillConversationEmbeddingsCommand extends Command
{
    protected $signature = 'conversation:backfill-embeddings
        {--limit= : Maximum number of conversations to process}
        {--sync : Run synchronously instead of dispatching jobs}';

    protected $description = 'Backfill turn numbers and embeddings for existing conversations';

    public function handle(): int
    {
        $limit = $this->option('limit');
        $sync = $this->option('sync');

        // Find conversations that need processing
        // Either: no turn numbers set, or max turn > last embedded turn
        $query = Conversation::query()
            ->where('status', '!=', Conversation::STATUS_PROCESSING)
            ->where(function ($q) {
                $q->whereRaw('last_embedded_turn_number < (SELECT COALESCE(MAX(turn_number), -1) FROM messages WHERE conversation_id = conversations.id)')
                    ->orWhereRaw('(SELECT COUNT(*) FROM messages WHERE conversation_id = conversations.id AND turn_number IS NULL) > 0');
            })
            ->orderBy('updated_at', 'desc');

        if ($limit) {
            $query->limit((int) $limit);
        }

        $conversations = $query->get();

        if ($conversations->isEmpty()) {
            $this->info('No conversations need processing.');
            return Command::SUCCESS;
        }

        $this->info("Found {$conversations->count()} conversations to process.");

        $bar = $this->output->createProgressBar($conversations->count());
        $bar->start();

        foreach ($conversations as $conversation) {
            // First, calculate and store turn numbers (same logic as ProcessConversationStream)
            $this->calculateAndStoreTurns($conversation);

            // Then generate embeddings
            if ($sync) {
                // Run synchronously
                $job = new GenerateConversationEmbeddings($conversation);
                $job->handle(app(\App\Services\EmbeddingService::class));
            } else {
                // Dispatch to queue
                GenerateConversationEmbeddings::dispatch($conversation);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info('Completed processing all conversations.');
        } else {
            $this->info('Dispatched jobs for all conversations. Check queue worker for progress.');
        }

        return Command::SUCCESS;
    }

    /**
     * Calculate turns and update turn_number on messages.
     * Same logic as ProcessConversationStream.
     */
    private function calculateAndStoreTurns(Conversation $conversation): void
    {
        $turns = $this->calculateTurns($conversation);

        if (empty($turns)) {
            return;
        }

        DB::transaction(function () use ($turns) {
            foreach ($turns as $turnNumber => $messages) {
                $messageIds = collect($messages)->pluck('id');
                Message::whereIn('id', $messageIds)
                    ->update(['turn_number' => $turnNumber]);
            }
        });
    }

    private function calculateTurns(Conversation $conversation): array
    {
        $messages = $conversation->messages()->orderBy('sequence')->get();
        $turns = [];
        $currentTurn = null;
        $turnNumber = 0;
        $hasResponse = false;

        foreach ($messages as $message) {
            $isRealUserMessage = $message->role === 'user'
                && $this->hasRealUserContent($message);

            if ($isRealUserMessage) {
                if ($currentTurn !== null && $hasResponse) {
                    $turns[$turnNumber] = $currentTurn;
                    $turnNumber++;
                    $currentTurn = [];
                    $hasResponse = false;
                }

                $currentTurn = $currentTurn ?? [];
                $currentTurn[] = $message;
            } else {
                if ($currentTurn !== null) {
                    $currentTurn[] = $message;

                    if ($message->role === 'assistant') {
                        $hasResponse = true;
                    }
                }
            }
        }

        if ($currentTurn !== null && $hasResponse) {
            $turns[$turnNumber] = $currentTurn;
        }

        return $turns;
    }

    private function hasRealUserContent(Message $message): bool
    {
        $content = $message->content;

        if (!is_array($content)) {
            return is_string($content) && !empty($content);
        }

        return collect($content)
            ->contains(fn($block) => ($block['type'] ?? '') === 'text');
    }
}
