<?php

namespace App\Services\Chat;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChatService
{
    /**
     * Create a new chat session
     */
    public function createSession(int $userId, ?string $issueType = null): ChatSession
    {
        $session = ChatSession::create([
            'user_id' => $userId,
            'issue_type' => $issueType,
            'status' => 'waiting',
        ]);

        return $session->load(['user', 'admin', 'messages']);
    }

    /**
     * Get user's active chat session
     */
    public function getActiveSession(int $userId): ?ChatSession
    {
        return ChatSession::where('user_id', $userId)
            ->whereIn('status', ['active', 'waiting'])
            ->with(['user', 'admin', 'messages'])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get user's chat sessions
     */
    public function getUserSessions(int $userId, int $limit = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ChatSession::where('user_id', $userId)
            ->with(['user', 'admin', 'latestMessage'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }

    /**
     * Get a specific chat session
     */
    public function getSession(int $userId, int $sessionId): ChatSession
    {
        return ChatSession::where('user_id', $userId)
            ->where('id', $sessionId)
            ->with(['user', 'admin', 'messages'])
            ->firstOrFail();
    }

    /**
     * Start a new chat (create session and send first message)
     */
    public function startChat(int $userId, string $issueType, string $message): array
    {
        DB::beginTransaction();
        try {
            // Check if user has an active session
            $existingSession = $this->getActiveSession($userId);
            if ($existingSession) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'You already have an active chat session',
                    'session' => $existingSession,
                ];
            }

            // Create new session
            $session = $this->createSession($userId, $issueType);

            // Send first message
            $chatMessage = $this->sendMessage($session->id, $userId, $message, 'user');

            DB::commit();

            return [
                'success' => true,
                'session' => $session->fresh(['user', 'admin', 'messages']),
                'message' => $chatMessage,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Send a message in a chat session
     */
    public function sendMessage(int $sessionId, int $userId, string $message, string $senderType = 'user', ?string $attachmentPath = null, ?string $attachmentType = null): ChatMessage
    {
        $session = ChatSession::findOrFail($sessionId);

        // Verify user owns the session
        if ($session->user_id !== $userId && $senderType === 'user') {
            throw new \Exception('Unauthorized to send message in this session');
        }

        $chatMessage = ChatMessage::create([
            'chat_session_id' => $sessionId,
            'user_id' => $senderType === 'user' ? $userId : null,
            'admin_id' => $senderType === 'admin' ? $userId : null,
            'message' => $message,
            'sender_type' => $senderType,
            'status' => 'sent',
            'attachment_path' => $attachmentPath,
            'attachment_type' => $attachmentType,
        ]);

        // Update session last message time
        $session->update([
            'last_message_at' => now(),
            'status' => $session->status === 'waiting' && $senderType === 'user' ? 'active' : $session->status,
        ]);

        return $chatMessage->load(['user', 'admin', 'chatSession']);
    }

    /**
     * Get messages for a chat session
     */
    public function getMessages(int $userId, int $sessionId, int $limit = 50): Collection
    {
        $session = $this->getSession($userId, $sessionId);

        return ChatMessage::where('chat_session_id', $sessionId)
            ->with(['user', 'admin'])
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(int $userId, int $sessionId): void
    {
        $session = $this->getSession($userId, $sessionId);

        ChatMessage::where('chat_session_id', $sessionId)
            ->where('sender_type', '!=', 'user')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'status' => 'read',
            ]);
    }

    /**
     * Close a chat session
     */
    public function closeSession(int $userId, int $sessionId): ChatSession
    {
        $session = $this->getSession($userId, $sessionId);

        $session->update([
            'status' => 'closed',
        ]);

        return $session->fresh(['user', 'admin', 'messages']);
    }
}
