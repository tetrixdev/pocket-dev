<?php

namespace App\Http\Controllers;

use App\Models\ClaudeSession;
use App\Services\ClaudeCodeService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Emergency fallback controller for Claude Code interface.
 * 
 * This controller provides a simple, framework-free interface to Claude Code
 * that cannot be broken by Livewire or frontend changes. Use this when:
 * - The main Livewire interface is broken
 * - You need emergency access to Claude during development
 * - Testing backend functionality without frontend dependencies
 */
class SafeModeController extends Controller
{
    public function __construct(
        protected ClaudeCodeService $claude
    ) {}

    /**
     * Show the safe-mode interface.
     */
    public function index(Request $request): View
    {
        $sessionId = $request->query('session_id');
        $session = null;
        $messages = [];

        if ($sessionId) {
            $session = ClaudeSession::find($sessionId);
            if ($session) {
                $messages = $session->messages ?? [];
            }
        }

        return view('claude.safe-mode', [
            'sessionId' => $sessionId,
            'session' => $session,
            'messages' => $messages,
        ]);
    }

    /**
     * Handle Claude query submission.
     */
    public function query(Request $request): RedirectResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:10000',
            'session_id' => 'nullable|exists:claude_sessions,id',
        ]);

        try {
            $sessionId = $request->input('session_id');
            
            // Create new session if none provided
            if (!$sessionId) {
                $session = ClaudeSession::create([
                    'title' => 'Safe Mode Session',
                    'project_path' => '/var/www',
                    'status' => 'active',
                    'last_activity_at' => now(),
                ]);
                $sessionId = $session->id;
            } else {
                $session = ClaudeSession::findOrFail($sessionId);
            }

            // Add user message
            $prompt = $request->input('prompt');
            $session->addMessage('user', $prompt);

            // Query Claude
            $response = $this->claude->query($prompt, [
                'cwd' => $session->project_path,
            ]);

            // Add Claude response
            $session->addMessage('assistant', $response);

            // Extract result text
            $resultText = is_array($response) && isset($response['result']) 
                ? $response['result'] 
                : json_encode($response);

            return redirect()
                ->route('claude.safe-mode', ['session_id' => $sessionId])
                ->with('response', $resultText);

        } catch (\Exception $e) {
            return redirect()
                ->route('claude.safe-mode', ['session_id' => $sessionId ?? null])
                ->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Create a new session.
     */
    public function newSession(): RedirectResponse
    {
        $session = ClaudeSession::create([
            'title' => 'Safe Mode Session ' . now()->format('Y-m-d H:i'),
            'project_path' => '/var/www',
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        return redirect()->route('claude.safe-mode', ['session_id' => $session->id]);
    }

    /**
     * List all sessions.
     */
    public function listSessions(): View
    {
        $sessions = ClaudeSession::orderBy('last_activity_at', 'desc')->paginate(20);

        return view('claude.sessions-list', [
            'sessions' => $sessions,
        ]);
    }
}
