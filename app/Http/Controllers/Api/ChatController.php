<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StartChatRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Get active chat session
     */
    #[OA\Get(path: "/api/chat/session", summary: "Get active chat session", description: "Get the user's active chat session if exists.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Response(response: 200, description: "Session retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object", nullable: true)]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getActiveSession(Request $request): JsonResponse
    {
        try {
            $session = $this->chatService->getActiveSession($request->user()->id);
            
            if (!$session) {
                return ResponseHelper::success(null, 'No active chat session found.');
            }

            return ResponseHelper::success($session, 'Session retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get active session error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving session. Please try again.');
        }
    }

    /**
     * Get user's chat sessions
     */
    #[OA\Get(path: "/api/chat/sessions", summary: "Get chat sessions", description: "Get all chat sessions for the authenticated user.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records per page", schema: new OA\Schema(type: "integer", example: 20))]
    #[OA\Response(response: 200, description: "Sessions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getSessions(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 20);
            $sessions = $this->chatService->getUserSessions($request->user()->id, $limit);
            return ResponseHelper::success($sessions, 'Sessions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get sessions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving sessions. Please try again.');
        }
    }

    /**
     * Start a new chat
     */
    #[OA\Post(path: "/api/chat/start", summary: "Start new chat", description: "Start a new chat session with support.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["issue_type", "message"], properties: [new OA\Property(property: "issue_type", type: "string", enum: ["fiat_issue", "virtual_card_issue", "crypto_issue", "general"], example: "crypto_issue"), new OA\Property(property: "message", type: "string", example: "I have issue with crypto")]))]
    #[OA\Response(response: 201, description: "Chat started successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Active chat session already exists")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function startChat(StartChatRequest $request): JsonResponse
    {
        try {
            $result = $this->chatService->startChat(
                $request->user()->id,
                $request->issue_type,
                $request->message
            );

            if (!$result['success']) {
                return ResponseHelper::error($result['message'], 400, ['session' => $result['session']]);
            }

            return ResponseHelper::success($result, 'Chat started successfully.', 201);
        } catch (\Exception $e) {
            Log::error('Start chat error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while starting chat. Please try again.');
        }
    }

    /**
     * Get a specific chat session
     */
    #[OA\Get(path: "/api/chat/sessions/{id}", summary: "Get chat session", description: "Get a specific chat session with messages.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Session ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Session retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Session not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getSession(Request $request, int $id): JsonResponse
    {
        try {
            $session = $this->chatService->getSession($request->user()->id, $id);
            return ResponseHelper::success($session, 'Session retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get session error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'session_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Session not found.');
        }
    }

    /**
     * Send a message
     */
    #[OA\Post(path: "/api/chat/sessions/{id}/messages", summary: "Send message", description: "Send a message in a chat session.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Session ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["message"], properties: [new OA\Property(property: "message", type: "string", example: "Can you help me with this issue?"), new OA\Property(property: "attachment", type: "string", format: "binary", nullable: true)]))]
    #[OA\Response(response: 201, description: "Message sent successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Session not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function sendMessage(SendMessageRequest $request, int $id): JsonResponse
    {
        try {
            $attachmentPath = null;
            $attachmentType = null;

            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $attachmentPath = $file->store('chat-attachments', 'public');
                $attachmentType = $file->getMimeType();
            }

            $message = $this->chatService->sendMessage(
                $id,
                $request->user()->id,
                $request->message,
                'user',
                $attachmentPath,
                $attachmentType
            );

            return ResponseHelper::success($message, 'Message sent successfully.', 201);
        } catch (\Exception $e) {
            Log::error('Send message error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'session_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            if (str_contains($e->getMessage(), 'Unauthorized')) {
                return ResponseHelper::unauthorized('Unauthorized to send message in this session.');
            }
            
            return ResponseHelper::serverError('An error occurred while sending message. Please try again.');
        }
    }

    /**
     * Get messages for a session
     */
    #[OA\Get(path: "/api/chat/sessions/{id}/messages", summary: "Get messages", description: "Get all messages for a chat session.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Session ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of messages", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Messages retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 404, description: "Session not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getMessages(Request $request, int $id): JsonResponse
    {
        try {
            $limit = $request->query('limit', 50);
            $messages = $this->chatService->getMessages($request->user()->id, $id, $limit);
            return ResponseHelper::success($messages, 'Messages retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get messages error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'session_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Session not found.');
        }
    }

    /**
     * Mark messages as read
     */
    #[OA\Post(path: "/api/chat/sessions/{id}/read", summary: "Mark messages as read", description: "Mark all messages in a chat session as read.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Session ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Messages marked as read", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Messages marked as read")]))]
    #[OA\Response(response: 404, description: "Session not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        try {
            $this->chatService->markAsRead($request->user()->id, $id);
            return ResponseHelper::success(null, 'Messages marked as read.');
        } catch (\Exception $e) {
            Log::error('Mark as read error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'session_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Session not found.');
        }
    }

    /**
     * Close a chat session
     */
    #[OA\Post(path: "/api/chat/sessions/{id}/close", summary: "Close chat session", description: "Close a chat session.", security: [["sanctum" => []]], tags: ["Support"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Session ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Session closed successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Session not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function closeSession(Request $request, int $id): JsonResponse
    {
        try {
            $session = $this->chatService->closeSession($request->user()->id, $id);
            return ResponseHelper::success($session, 'Session closed successfully.');
        } catch (\Exception $e) {
            Log::error('Close session error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'session_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::notFound('Session not found.');
        }
    }
}
