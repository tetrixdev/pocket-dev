<?php

namespace App\Livewire;

use App\Models\ClaudeSession;
use App\Services\ClaudeCodeService;
use Livewire\Component;
use Livewire\Attributes\On;

class ClaudeChat extends Component
{
    public ?int $sessionId = null;
    public string $prompt = "";
    public array $messages = [];
    public bool $isProcessing = false;
    public string $currentResponse = "";
    public string $projectPath = "/var/www";

    public function mount(?int $sessionId = null)
    {
        if ($sessionId) {
            $this->sessionId = $sessionId;
            $this->loadSession();
        }
    }

    public function loadSession()
    {
        $session = ClaudeSession::find($this->sessionId);
        if ($session) {
            $this->messages = $session->messages ?? [];
            $this->projectPath = $session->project_path;
        }
    }

    public function createSession()
    {
        $session = ClaudeSession::create([
            "title" => "New Session",
            "project_path" => $this->projectPath,
            "status" => "active",
            "last_activity_at" => now(),
        ]);

        $this->sessionId = $session->id;
        $this->messages = [];
    }

    public function sendMessage()
    {
        if (empty(trim($this->prompt))) {
            return;
        }

        if (!$this->sessionId) {
            $this->createSession();
        }

        $this->isProcessing = true;
        $this->currentResponse = "";

        $this->messages[] = [
            "role" => "user",
            "content" => $this->prompt,
            "timestamp" => now()->toISOString(),
        ];

        $session = ClaudeSession::find($this->sessionId);
        $session->addMessage("user", $this->prompt);

        $userPrompt = $this->prompt;
        $this->prompt = "";

        $this->dispatch("message-sent");

        try {
            $claude = app(ClaudeCodeService::class);

            $response = $claude->query($userPrompt, [
                "cwd" => $this->projectPath,
            ]);

            $this->messages[] = [
                "role" => "assistant",
                "content" => $response,
                "timestamp" => now()->toISOString(),
            ];

            $session->addMessage("assistant", $response);
            $this->isProcessing = false;

            $this->dispatch("message-sent");
        } catch (\Exception $e) {
            $this->messages[] = [
                "role" => "error",
                "content" => "Error: " . $e->getMessage(),
                "timestamp" => now()->toISOString(),
            ];
            $this->isProcessing = false;
        }
    }

    public function clearSession()
    {
        $this->sessionId = null;
        $this->messages = [];
        $this->prompt = "";
    }

    public function render()
    {
        $sessions = ClaudeSession::query()
            ->orderBy("last_activity_at", "desc")
            ->limit(10)
            ->get();

        return view("livewire.claude-chat", [
            "sessions" => $sessions,
        ]);
    }
}
