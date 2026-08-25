<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50]) ? (int) $request->query('per_page') : 10;
        $search = trim((string) $request->query('search', ''));
        $filter = trim((string) $request->query('filter', 'all'));

        $query = Conversation::with(['latestMessage', 'delivery'])->orderBy('last_message_at', 'desc');

        if ($filter !== 'all' && in_array($filter, ['rider', 'seller'])) {
            $query->where('participant_type', $filter);
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $digits = preg_replace('/\D/', '', $search);
            $orderRef = ltrim($search, '#O o r d e r');
            $orderDigits = preg_replace('/\D/', '', $orderRef);

            $query->where(function ($w) use ($like, $digits, $orderDigits) {
                $w->where('participant_name', 'like', $like)
                  ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $like))
                  ->orWhereHas('delivery', fn ($d) => $d->where('tracking_number', 'like', $like));

                if ($digits !== '') {
                    $w->orWhere('order_id', (int) $digits);
                }
                if ($orderDigits !== '' && $orderDigits !== $digits) {
                    $w->orWhere('order_id', (int) $orderDigits);
                }
            });
        }

        $conversations = $query->paginate($perPage)->withQueryString();

        $activeId = $request->get('conversation');
        $activeConversation = $activeId
            ? Conversation::with('messages')->find($activeId)
            : ($conversations->first() ? Conversation::with('messages')->find($conversations->first()->id) : null);

        if ($activeConversation) {
            $activeConversation->messages()->where('is_read', false)->where('sender_type', '!=', 'logistics')->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
            $activeConversation->update(['unread_count' => 0]);
        }

        return view('messages.index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load('messages');
        $conversation->messages()->where('is_read', false)->where('sender_type', '!=', 'logistics')->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'conversation' => $conversation,
            'total_unread' => Conversation::sum('unread_count'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'body' => 'required|string|max:2000',
        ]);

        $conversation = Conversation::find($validated['conversation_id']);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'logistics',
            'sender_id' => auth()->id(),
            'body' => $validated['body'],
        ]);

        $conversation->update([
            'last_message_preview' => $validated['body'],
            'last_message_at' => now(),
        ]);

        if ($request->ajax()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->back();
    }

    public function markRead(Conversation $conversation): JsonResponse
    {
        $conversation->messages()->where('is_read', false)->where('sender_type', '!=', 'logistics')->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'total_unread' => Conversation::sum('unread_count'),
        ]);
    }
}
