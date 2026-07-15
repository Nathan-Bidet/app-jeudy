<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2000'],
            'p256dh' => ['required', 'string', 'max:500'],
            'auth' => ['required', 'string', 'max:500'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $user = $request->user();
        $endpointHash = hash('sha256', $validated['endpoint']);

        PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint_hash' => $endpointHash,
            ],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['p256dh'],
                'auth' => $validated['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aesgcm',
            ]
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->noContent();
    }
}
