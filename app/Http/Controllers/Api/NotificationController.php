<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['success' => true, 'data' => [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->limit(10)->get()->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'body' => $n->data['body'] ?? '',
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }
}
