<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public ?int $userId,
        public string $title,
        public string $body,
        public string $url = '/',
    ) {}

    public function handle(): void
    {
        $query = PushSubscription::query();
        if ($this->userId !== null) {
            $query->where('user_id', $this->userId);
        }
        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $vapid = config('webpush.vapid');

        if (!$vapid['public_key'] || !$vapid['private_key']) {
            Log::warning('SendPushNotification: VAPID keys not configured');
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapid['subject'],
                'publicKey' => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
            ],
        ]);

        $payload = json_encode([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => 'pocketdev-' . md5($this->url),
        ]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->public_key,
                        'auth' => $sub->auth_token,
                    ],
                ]),
                $payload
            );
        }

        // Flush all queued notifications
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                Log::info('SendPushNotification: Removed expired subscription', [
                    'endpoint' => $report->getEndpoint(),
                ]);
            } elseif (!$report->isSuccess()) {
                Log::warning('SendPushNotification: Push failed', [
                    'endpoint' => $report->getEndpoint(),
                    'reason' => $report->getReason(),
                    'status' => $report->getHTTPCode(),
                ]);
            }
        }
    }
}
