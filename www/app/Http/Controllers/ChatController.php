<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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
     * Show the chat interface for a specific conversation.
     * Sets session for "Back to Chat" from settings.
     */
    public function show(Request $request, string $conversationUuid): View
    {
        // Validate the conversation exists
        $conversation = Conversation::where('uuid', $conversationUuid)->first();

        if ($conversation) {
            // Store in session for "Back to Chat" from settings
            $request->session()->put('last_conversation_uuid', $conversationUuid);
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
            $request->session()->put('last_conversation_uuid', $conversationUuid);
        }

        return response()->noContent();
    }
}
