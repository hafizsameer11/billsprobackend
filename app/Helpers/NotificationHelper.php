<?php

namespace App\Helpers;

use App\Jobs\SendExpoPushToUserJob;
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
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'read' => false,
            'metadata' => $metadata,
        ]);

        self::dispatchPushNotification($notification);

        return $notification;
    }

    /**
     * Fan out app notifications to Expo push so login, transaction, and account events
     * can notify users outside the app as well.
     */
    private static function dispatchPushNotification(Notification $notification): void
    {
        $metadata = is_array($notification->metadata) ? $notification->metadata : [];
        $screen = $notification->type === 'virtual_card' ? 'VirtualCards' : 'Notifications';

        SendExpoPushToUserJob::dispatch(
            (int) $notification->user_id,
            (string) $notification->title,
            (string) $notification->message,
            [
                'screen' => $screen,
                'notification_id' => (string) $notification->id,
                'type' => (string) $notification->type,
                'kind' => isset($metadata['kind']) ? (string) $metadata['kind'] : null,
                'event_id' => isset($metadata['event_target_id']) ? (string) $metadata['event_target_id'] : null,
                'virtual_card_id' => isset($metadata['virtual_card_id']) ? (string) $metadata['virtual_card_id'] : null,
            ]
        );
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
     * Create a welcome notification for new users.
     */
    public static function createWelcomeNotification(User $user): Notification
    {
        return self::create(
            $user->id,
            'account',
            'Welcome to BillsPro',
            'Your account has been created successfully. Start by funding your wallet to make payments.',
            [
                'event' => 'user_created',
                'created_at' => now()->toIso8601String(),
            ]
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
