<?php

namespace App\Services;

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
     * Send Notification to the current device (current token)
     *
     * @param string $title
     * @param string $message
     * @param array $data
     * @return bool
     */
    public function sendNotificationToCurrentToken(string $title, string $message, array $data = []): bool
    {
        try {
            $token = auth()->user()->currentAccessToken();

            if (!$token || !$token->fcm_token) {
                return false;
            }

            $payloadData = array_merge([
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], $data);

            $cloudMessage = CloudMessage::withTarget('token', $token->fcm_token)
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
