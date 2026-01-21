<?php

namespace App\Helpers;

use App\Models\Notification;
use App\Models\User;

class NotificationHelper
{
    /**
     * Create a notification for a user
     *
     * @param int $userId
     * @param string $type
     * @param string $title
     * @param string $message
     * @param array|null $metadata
     * @return Notification
     */
    public static function create(int $userId, string $type, string $title, string $message, ?array $metadata = null): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'read' => false,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a login notification
     *
     * @param User $user
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return Notification
     */
    public static function createLoginNotification(User $user, ?string $ipAddress = null, ?string $userAgent = null): Notification
    {
        return self::create(
            $user->id,
            'login',
            'Successful Login',
            'You have successfully logged into your account.',
            [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'login_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Create a transaction notification
     *
     * @param User $user
     * @param string $transactionType
     * @param string $title
     * @param string $message
     * @param array|null $transactionData
     * @return Notification
     */
    public static function createTransactionNotification(
        User $user,
        string $transactionType,
        string $title,
        string $message,
        ?array $transactionData = null
    ): Notification {
        return self::create(
            $user->id,
            'transaction',
            $title,
            $message,
            array_merge([
                'transaction_type' => $transactionType,
            ], $transactionData ?? [])
        );
    }

    /**
     * Mark notification as read
     *
     * @param int $notificationId
     * @param int $userId
     * @return bool
     */
    public static function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->update(['read' => true]);
        return true;
    }

    /**
     * Mark all notifications as read for a user
     *
     * @param int $userId
     * @return int Number of notifications marked as read
     */
    public static function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('read', false)
            ->update(['read' => true]);
    }

    /**
     * Get unread count for a user
     *
     * @param int $userId
     * @return int
     */
    public static function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('read', false)
            ->count();
    }
}
