<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWebPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        private readonly int $userId,
        private readonly array $payload
    ) {}

    public function handle(WebPushService $webPushService): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $this->userId)->get();

        foreach ($subscriptions as $subscription) {
            $webPushService->sendToSubscription($subscription, $this->payload);
        }
    }
}
