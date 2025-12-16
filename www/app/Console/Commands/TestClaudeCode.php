<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Providers\ClaudeCodeProvider;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use Illuminate\Console\Command;

class TestClaudeCode extends Command
{
    protected $signature = 'test:claude-code {message=hi}';
    protected $description = 'Test Claude Code CLI integration';

    public function handle(ModelRepository $models, AppSettingsService $appSettings): int
    {
        $this->info('Testing Claude Code CLI integration...');

        // Check if claude CLI is available
        $provider = new ClaudeCodeProvider($models, $appSettings);

        if (!$provider->isAvailable()) {
            $this->error('Claude Code CLI is not available!');
            return 1;
        }

        $this->info('✓ Claude CLI is available');

        // Create a test conversation
        $conversation = Conversation::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'title' => 'Test conversation',
            'working_directory' => '/var/www',
            'provider_type' => 'claude_code',
            'model' => 'opus',
        ]);

        $this->info("✓ Created test conversation: {$conversation->uuid}");

        // Add a user message
        $message = $this->argument('message');
        $conversation->messages()->create([
            'role' => 'user',
            'content' => $message,
        ]);

        $this->info("✓ Added user message: {$message}");

        // Try to stream
        $this->info('Streaming response...');
        $this->newLine();

        try {
            $fullResponse = '';
            $eventCount = 0;

            foreach ($provider->streamMessage($conversation) as $event) {
                $eventCount++;

                if ($event->type === 'text_delta') {
                    $this->output->write($event->content);
                    $fullResponse .= $event->content;
                } elseif ($event->type === 'error') {
                    $this->newLine();
                    $this->error("Stream error: " . $event->content);
                    return 1;
                } elseif ($event->type === 'done') {
                    $this->newLine();
                    $this->info("✓ Stream completed ({$eventCount} events)");
                } else {
                    // Log other event types for debugging
                    $this->line("<comment>[{$event->type}]</comment>");
                }
            }

            if (empty($fullResponse)) {
                $this->warn('No text response received');
            }

            // Check if session ID was saved
            $conversation->refresh();
            if ($conversation->claude_session_id) {
                $this->info("✓ Session ID saved: {$conversation->claude_session_id}");
            }

            // Clean up
            $conversation->messages()->delete();
            $conversation->delete();
            $this->info('✓ Cleaned up test conversation');

            return 0;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("Exception: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ":" . $e->getLine());

            // Clean up
            $conversation->messages()->delete();
            $conversation->delete();

            return 1;
        }
    }
}
