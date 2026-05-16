<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Return the VAPID public key for frontend subscription.
     */
    public function vapidKey(): JsonResponse
    {
        $publicKey = config('webpush.vapid.public_key');

        if (!$publicKey) {
            return response()->json([
                'error' => 'VAPID keys not configured. Run: vendor/bin/web-push generate-vapid-keys',
            ], 503);
        }

        return response()->json(['public_key' => $publicKey]);
    }

    /**
     * Store a push subscription (upsert on endpoint).
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url|max:2048',
            'public_key' => 'required|string|max:512',
            'auth_token' => 'required|string|max:512',
        ]);

        $user = $request->user() ?? \App\Models\User::first();

        if (!$user) {
            return response()->json(['error' => 'No user found'], 401);
        }

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $user->id,
                'public_key' => $validated['public_key'],
                'auth_token' => $validated['auth_token'],
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'id' => $subscription->id,
            'message' => 'Subscription saved',
        ], 201);
    }

    /**
     * Remove a push subscription by endpoint.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url|max:2048',
        ]);

        $deleted = PushSubscription::where('endpoint', $validated['endpoint'])->delete();

        return response()->json([
            'deleted' => $deleted > 0,
        ]);
    }

    /**
     * Remove a specific subscription by ID (from settings device list).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user() ?? \App\Models\User::first();

        $subscription = PushSubscription::where('id', $id)
            ->where('user_id', $user?->id)
            ->firstOrFail();

        $subscription->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * List all subscriptions (for device management in settings).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? \App\Models\User::first();

        if (!$user) {
            return response()->json(['subscriptions' => []]);
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'endpoint', 'user_agent', 'created_at', 'updated_at']);

        return response()->json(['subscriptions' => $subscriptions]);
    }

    /**
     * Send a test notification to verify push is working.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user() ?? \App\Models\User::first();

        if (!$user) {
            return response()->json(['error' => 'No user found'], 401);
        }

        $count = PushSubscription::where('user_id', $user->id)->count();

        if ($count === 0) {
            return response()->json(['error' => 'No subscriptions found'], 404);
        }

        \App\Jobs\SendPushNotification::dispatch(
            userId: $user->id,
            title: 'Test notificatie',
            body: 'Push notificaties werken!',
            url: '/',
        );

        return response()->json([
            'message' => "Test notification queued for {$count} device(s)",
        ]);
    }

    /**
     * Get notification preferences.
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'notify_on_complete' => (bool) Setting::get('push.notify_on_complete', true),
            'notify_on_failure' => (bool) Setting::get('push.notify_on_failure', true),
            'min_duration_seconds' => (int) Setting::get('push.min_duration_seconds', 5),
        ]);
    }

    /**
     * Save notification preferences.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notify_on_complete' => 'required|boolean',
            'notify_on_failure' => 'required|boolean',
            'min_duration_seconds' => 'required|integer|min:0|max:3600',
        ]);

        Setting::setMany([
            'push.notify_on_complete' => $validated['notify_on_complete'],
            'push.notify_on_failure' => $validated['notify_on_failure'],
            'push.min_duration_seconds' => $validated['min_duration_seconds'],
        ]);

        return response()->json(['saved' => true]);
    }
}
