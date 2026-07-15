<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private function createWebPush(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid_subject'),
                'publicKey' => config('webpush.vapid_public_key'),
                'privateKey' => config('webpush.vapid_private_key'),
            ],
        ]);
    }

    public function sendToSubscription(PushSubscription $pushSubscription, array $payload): bool
    {
        $webPush = $this->createWebPush();

        $subscription = Subscription::create([
            'endpoint' => $pushSubscription->endpoint,
            'publicKey' => $pushSubscription->p256dh,
            'authToken' => $pushSubscription->auth,
            'contentEncoding' => $pushSubscription->content_encoding ?? 'aesgcm',
        ]);

        try {
            $report = $webPush->sendOneNotification($subscription, json_encode($payload));

            if ($report->isSuccess()) {
                return true;
            }

            if ($report->isSubscriptionExpired()) {
                $pushSubscription->delete();
                return false;
            }

            Log::warning('WebPush send failed', [
                'subscription_id' => $pushSubscription->id,
                'status' => $report->getResponse()?->getStatusCode(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WebPush exception', [
                'subscription_id' => $pushSubscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
