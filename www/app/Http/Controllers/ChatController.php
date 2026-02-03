<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ChatController extends Controller
{
    /**
     * Show the chat interface (home page, no specific conversation).
     */
    public function index(): View
    {
        return view('chat');
    }

    /**
     * Show the chat interface for a specific session.
     * Sets the active workspace and last session for returning from settings.
     */
    public function showSession(Request $request, string $sessionId): View
    {
        $session = Session::find($sessionId);

        if ($session && $session->workspace_id) {
            $request->session()->put('active_workspace_id', $session->workspace_id);
            // Store per-workspace last session (for returning from settings)
            $request->session()->put("last_session_{$session->workspace_id}", $session->id);
        }

        return view('chat');
    }

    /**
     * Set the current session in PHP session (called by frontend when loading a session).
     * Enables "Back to Chat" from settings to return to the correct session.
     */
    public function setLastSession(Request $request, string $sessionId): Response
    {
        $session = Session::find($sessionId);

        if ($session && $session->workspace_id) {
            $request->session()->put("last_session_{$session->workspace_id}", $session->id);
        }

        return response()->noContent();
    }

    /**
     * Show the chat interface for a specific conversation.
     * Sets session for "Back to Chat" from settings.
     *
     * @deprecated Use showSession() instead
     */
    public function show(Request $request, string $conversationUuid): View
    {
        // Validate the conversation exists
        $conversation = Conversation::where('uuid', $conversationUuid)->first();

        if ($conversation) {
            // Store per-workspace last conversation (for returning from settings)
            $workspaceId = $conversation->workspace_id ?? 'default';
            $request->session()->put("last_conversation_{$workspaceId}", $conversationUuid);

            // Also set the active workspace to match the conversation
            if ($conversation->workspace_id) {
                $request->session()->put('active_workspace_id', $conversation->workspace_id);
            }
        }

        return view('chat');
    }

    /**
     * Set the current conversation in session (called by frontend after API create).
     * Enables "Back to Chat" from settings to return to the correct conversation.
     */
    public function setSession(Request $request, string $conversationUuid): Response
    {
        $conversation = Conversation::where('uuid', $conversationUuid)->first();

        if ($conversation) {
            // Store per-workspace last conversation
            $workspaceId = $conversation->workspace_id ?? 'default';
            $request->session()->put("last_conversation_{$workspaceId}", $conversationUuid);
        }

        return response()->noContent();
    }
}
