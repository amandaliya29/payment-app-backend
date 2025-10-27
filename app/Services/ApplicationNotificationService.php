<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Class ApplicationNotificationService
 *
 * Service class to send notifications to users via Firebase Cloud Messaging (FCM).
 */
class ApplicationNotificationService
{
    /**
     * @var Messaging Firebase Messaging instance
     */
    protected Messaging $messaging;

    /**
     * ApplicationNotificationService constructor.
     *
     * @param Messaging $messaging Firebase Messaging instance
     */
    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * Send a notification to a user's devices using their FCM tokens.
     *
     * This method retrieves all non-null FCM tokens for the given user ID
     * and sends a notification to each token with optional additional data.
     *
     * @param int $receiverID The ID of the user to send the notification to
     * @param string $title The notification title
     * @param string $message The notification message
     * @param array $data Optional additional data to send with the notification
     *
     * @return bool Returns true if notifications were successfully sent, false otherwise
     */
    public function sendNotificationToCurrentToken(
        int $receiverID,
        string $title,
        string $message,
        array $data = []
    ): bool {
        try {
            // Fetch non-null, trimmed FCM tokens
            $fcmTokens = DB::table('personal_access_tokens')
                ->where('name', 'user-auth')
                ->where('tokenable_id', $receiverID)
                ->whereNotNull('fcm_token')
                ->pluck('fcm_token')
                ->map(fn($token) => trim($token))
                ->filter();

            if ($fcmTokens->isEmpty()) {
                return false;
            }

            $payloadData = array_merge(['click_action' => 'FLUTTER_NOTIFICATION_CLICK'], $data);

            // Send notifications to each token
            foreach ($fcmTokens as $fcmToken) {
                try {
                    $this->messaging->send(
                        CloudMessage::withTarget('token', $fcmToken)
                            ->withNotification(Notification::create($title, $message))
                            ->withData($payloadData)
                    );
                } catch (\Throwable $th) {
                    Log::warning("FCM send failure for user {$receiverID}: " . $th->getMessage());
                    continue;
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Notification sending failed for user ID {$receiverID}: {$e->getMessage()}");
            return false;
        }
    }
}
