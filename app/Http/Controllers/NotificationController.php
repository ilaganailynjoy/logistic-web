<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::latest()
            ->take(20)
            ->get();

        $unreadCount = Notification::unread()->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $notification->markAsRead();

        return response()->json([
            'unread_count' => Notification::unread()->count(),
        ]);
    }

    public function markAllRead(): JsonResponse
    {
        Notification::unread()->update(['is_read' => true]);

        return response()->json([
            'unread_count' => 0,
        ]);
    }
}