<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class ApplicationNotificationService
{
    protected $messaging;

    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * Send Notification to a User
     *
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param array $data
     * @return bool
     */
    public function sendNotification(int $userId, string $title, string $message, array $data = []): bool
    {
        try {
            $user = User::find($userId);

            if (!$user || !$user->fcm_token) {
                return false;
            }

            $payloadData = array_merge([
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], $data);

            $cloudMessage = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification(Notification::create($title, $message))
                ->withData($payloadData);

            $this->messaging->send($cloudMessage);

            return true;
        } catch (\Throwable $e) {
            Log::error('Notification sending failed: ' . $e->getMessage());
            return false;
        }
    }
}
