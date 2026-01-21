<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    /**
     * Get user notifications
     */
    #[OA\Get(path: "/api/user/notifications", summary: "Get user notifications", security: [["sanctum" => []]], tags: ["User"])]
    #[OA\Parameter(name: "page", in: "query", description: "Page number", required: false, schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Parameter(name: "per_page", in: "query", description: "Items per page", required: false, schema: new OA\Schema(type: "integer", example: 20))]
    #[OA\Parameter(name: "read", in: "query", description: "Filter by read status (true/false)", required: false, schema: new OA\Schema(type: "boolean"))]
    #[OA\Parameter(name: "type", in: "query", description: "Filter by notification type", required: false, schema: new OA\Schema(type: "string", example: "login"))]
    #[OA\Response(response: 200, description: "Notifications retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Notifications retrieved successfully"), new OA\Property(property: "data", type: "object", properties: [new OA\Property(property: "notifications", type: "array", items: new OA\Items(type: "object")), new OA\Property(property: "unread_count", type: "integer", example: 5), new OA\Property(property: "pagination", type: "object")])]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getNotifications(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $request->get('per_page', 20);
            $read = $request->get('read');
            $type = $request->get('type');

            $query = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc');

            // Filter by read status
            if ($read !== null) {
                $query->where('read', filter_var($read, FILTER_VALIDATE_BOOLEAN));
            }

            // Filter by type
            if ($type) {
                $query->where('type', $type);
            }

            $notifications = $query->paginate($perPage);
            $unreadCount = NotificationHelper::getUnreadCount($user->id);

            return ResponseHelper::success([
                'notifications' => $notifications->items(),
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ], 'Notifications retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Get notifications error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving notifications. Please try again.');
        }
    }

    /**
     * Mark notification as read
     */
    #[OA\Post(path: "/api/user/notifications/{id}/read", summary: "Mark notification as read", security: [["sanctum" => []]], tags: ["User"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Notification ID", schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Notification marked as read", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Notification marked as read")]))]
    #[OA\Response(response: 404, description: "Notification not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function markNotificationAsRead(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();
            $success = NotificationHelper::markAsRead($id, $user->id);

            if (!$success) {
                return ResponseHelper::notFound('Notification not found');
            }

            return ResponseHelper::success(null, 'Notification marked as read');
        } catch (\Exception $e) {
            Log::error('Mark notification as read error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'notification_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while marking notification as read. Please try again.');
        }
    }

    /**
     * Mark all notifications as read
     */
    #[OA\Post(path: "/api/user/notifications/read-all", summary: "Mark all notifications as read", security: [["sanctum" => []]], tags: ["User"])]
    #[OA\Response(response: 200, description: "All notifications marked as read", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "All notifications marked as read"), new OA\Property(property: "data", type: "object", properties: [new OA\Property(property: "marked_count", type: "integer", example: 5)])]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function markAllNotificationsAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $markedCount = NotificationHelper::markAllAsRead($user->id);

            return ResponseHelper::success([
                'marked_count' => $markedCount,
            ], 'All notifications marked as read');
        } catch (\Exception $e) {
            Log::error('Mark all notifications as read error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while marking all notifications as read. Please try again.');
        }
    }

    /**
     * Get user profile
     */
    #[OA\Get(path: "/api/user/profile", summary: "Get user profile", security: [["sanctum" => []]], tags: ["User"])]
    #[OA\Response(response: 200, description: "Profile retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Profile retrieved successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return ResponseHelper::success([
                'user' => $user->makeHidden(['password', 'pin']),
            ], 'Profile retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Get profile error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving profile. Please try again.');
        }
    }

    /**
     * Update user profile
     */
    #[OA\Put(path: "/api/user/profile", summary: "Update user profile", security: [["sanctum" => []]], tags: ["User"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: "first_name", type: "string", nullable: true, example: "John"), new OA\Property(property: "last_name", type: "string", nullable: true, example: "Doe"), new OA\Property(property: "phone_number", type: "string", nullable: true, example: "08012345678"), new OA\Property(property: "country_code", type: "string", nullable: true, example: "NG")]))]
    #[OA\Response(response: 200, description: "Profile updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Profile updated successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $data = $request->validated();

            // Update name if first_name or last_name changed
            if (isset($data['first_name']) || isset($data['last_name'])) {
                $firstName = $data['first_name'] ?? $user->first_name;
                $lastName = $data['last_name'] ?? $user->last_name;
                $data['name'] = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
            }

            $user->update($data);

            return ResponseHelper::success([
                'user' => $user->fresh()->makeHidden(['password', 'pin']),
            ], 'Profile updated successfully');
        } catch (\Exception $e) {
            Log::error('Update profile error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while updating profile. Please try again.');
        }
    }
}
