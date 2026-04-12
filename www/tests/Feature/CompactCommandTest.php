<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversationStream;
use App\Models\Conversation;
use App\Services\StreamManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CompactCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_requires_feature_flag(): void
    {
        config()->set('ai.providers.claude_code.enable_compact_command', false);

        $conversation = Conversation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_type' => 'claude_code',
            'model' => 'claude-sonnet-4-6',
            'working_directory' => '/workspace/default',
            'provider_session_id' => 'sess_123',
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->uuid}/compact");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_compact_requires_claude_code_provider(): void
    {
        config()->set('ai.providers.claude_code.enable_compact_command', true);

        $conversation = Conversation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'working_directory' => '/workspace/default',
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->uuid}/compact");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_compact_requires_existing_provider_session(): void
    {
        config()->set('ai.providers.claude_code.enable_compact_command', true);

        $conversation = Conversation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_type' => 'claude_code',
            'model' => 'claude-sonnet-4-6',
            'working_directory' => '/workspace/default',
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->uuid}/compact");

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_compact_dispatches_stream_job_when_valid(): void
    {
        config()->set('ai.providers.claude_code.enable_compact_command', true);
        Queue::fake();

        $conversation = Conversation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider_type' => 'claude_code',
            'model' => 'claude-sonnet-4-6',
            'working_directory' => '/workspace/default',
            'provider_session_id' => 'sess_123',
        ]);

        $streamManager = Mockery::mock(StreamManager::class);
        $streamManager->shouldReceive('isStreaming')->once()->with($conversation->uuid)->andReturn(false);
        $streamManager->shouldReceive('startStream')->once();
        $this->app->instance(StreamManager::class, $streamManager);

        $response = $this->postJson("/api/conversations/{$conversation->uuid}/compact");

        $response->assertOk()->assertJsonPath('success', true);
        Queue::assertPushed(ProcessConversationStream::class, function (ProcessConversationStream $job) use ($conversation) {
            return $job->conversationUuid === $conversation->uuid && $job->prompt === '/compact';
        });
    }
}
