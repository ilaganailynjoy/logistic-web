<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Delivery;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderConversationController extends Controller
{
    private function riderId(Request $request): int
    {
        return (int) $request->user()->rider->id;
    }

    private function ensureConversationsForRider(int $riderId, int $userId): void
    {
        // Ensure logistics conversation exists (already handled by RiderMessageController)
        // For each active delivery, ensure seller/buyer conversations exist
        $deliveries = Delivery::where('rider_id', $riderId)->whereNotNull('order_id')->with(['items'])->get();
        foreach ($deliveries as $delivery) {
            $order = Order::find($delivery->order_id);
            if (!$order) continue;
            // Buyer
            $buyerId = $order->buyer_id;
            $buyer = \App\Models\User::find($buyerId);
            if ($buyer) {
                Conversation::firstOrCreate(
                    ['order_id' => $order->id, 'rider_id' => $riderId, 'participant_type' => 'buyer', 'participant_id' => $buyerId],
                    ['participant_name' => $buyer->name, 'subject' => 'Delivery ' . $delivery->tracking_number, 'last_message_at' => now()]
                );
            }
            // Seller(s) - one per distinct seller in order_items
            $sellerIds = $delivery->items->pluck('seller_id')->unique()->filter();
            // Fallback to order's seller if items empty
            if ($sellerIds->isEmpty() && isset($order->seller_id)) $sellerIds = collect([$order->seller_id]);
            foreach ($sellerIds as $sid) {
                $seller = \App\Models\User::find($sid);
                if (!$seller) continue;
                $sellerName = $seller->name;
                // Try to get store name if seller has seller profile
                if ($seller->seller) $sellerName = $seller->seller->business_name ?? $sellerName;
                Conversation::firstOrCreate(
                    ['order_id' => $order->id, 'rider_id' => $riderId, 'participant_type' => 'seller', 'participant_id' => $sid],
                    ['participant_name' => $sellerName, 'subject' => 'Delivery ' . $delivery->tracking_number, 'last_message_at' => now()]
                );
            }
        }
        // Also logistics conversation
        Conversation::firstOrCreate(
            ['participant_type' => 'rider', 'participant_id' => $userId],
            ['participant_name' => \App\Models\User::find($userId)?->name ?? 'Rider', 'subject' => 'Rider Support', 'last_message_at' => now(), 'rider_id' => $riderId]
        );
    }

    private function authorizeConversation(Request $request, Conversation $conv): void
    {
        $userId = (int) $request->user()->id;
        $riderId = $this->riderId($request);
        // Logistics: participant_type rider with participant_id = userId and rider_id = riderId
        // Seller/Buyer: rider_id = riderId
        if ($conv->participant_type === 'rider' && (int) $conv->participant_id === $userId && (int) $conv->rider_id === $riderId) return;
        if (in_array($conv->participant_type, ['seller', 'buyer']) && (int) $conv->rider_id === $riderId) {
            // Verify delivery still assigned to rider
            if ($conv->order_id) {
                $exists = Delivery::where('order_id', $conv->order_id)->where('rider_id', $riderId)->exists();
                if ($exists) return;
            }
        }
        abort(403, 'Not authorized for this conversation.');
    }

    private function payload(Conversation $c): array
    {
        $typeLabel = strtoupper($c->participant_type);
        return [
            'id' => $c->id,
            'name' => $c->participant_name,
            'type' => $c->participant_type,
            'type_label' => $typeLabel,
            'order_id' => $c->order_id,
            'tracking' => $c->delivery?->tracking_number,
            'preview' => $c->last_message_preview,
            'unread' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toISOString(),
            'time' => $c->last_message_at?->format('g:i A'),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $riderId = $this->riderId($request);
        $this->ensureConversationsForRider($riderId, $userId);

        $search = trim((string) $request->query('search', ''));
        $query = Conversation::where('rider_id', $riderId)->with(['delivery'])->orderByDesc('last_message_at');

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($like, $digits) {
                $q->where('participant_name', 'like', $like)
                  ->orWhere('last_message_preview', 'like', $like);
                if ($digits !== '') $q->orWhere('order_id', (int) $digits);
                // tracking search via delivery
                $q->orWhereHas('delivery', fn ($d) => $d->where('tracking_number', 'like', $like));
            });
        }

        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'conversations' => $paginator->getCollection()->map(fn ($c) => $this->payload($c))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));
        $query = $conversation->messages()->with('attachments')->orderByDesc('created_at')->orderByDesc('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        // Mark as read for rider's incoming messages
        $conversation->messages()->where('is_read', false)->where('sender_type', '!=', 'rider')->update(['is_read' => true, 'read_at' => now()]);
        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'conversation' => $this->payload($conversation),
            'messages' => $paginator->getCollection()->reverse()->map(fn ($m) => $this->messagePayload($m, $request->user()))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|max:5120',
        ]);

        $message = null;
        DB::transaction(function () use ($validated, $request, $conversation, &$message) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'rider',
                'sender_id' => $request->user()->id,
                'body' => $validated['body'],
            ]);
            if ($request->hasFile('attachment') && $request->file('attachment')->isValid()) {
                $file = $request->file('attachment');
                $filename = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
                $storedPath = $file->storeAs("message-attachments/{$conversation->id}", $filename, 'public');
                MessageAttachment::create([
                    'message_id' => $message->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }
            $conversation->update(['last_message_preview' => str($validated['body'])->limit(80), 'last_message_at' => now()]);
        });
        $message->load('attachments');

        return response()->json(['message' => 'Sent.', 'data' => $this->messagePayload($message, $request->user())], 201);
    }

    private function messagePayload(Message $m, $user): array
    {
        $deleted = $m->isDeleted();
        return [
            'id' => $m->id,
            'mine' => $m->sender_type === 'rider' && (int) $m->sender_id === (int) $user->id,
            'sender_type' => $m->sender_type,
            'body' => $deleted ? '' : $m->body,
            'deleted' => $deleted,
            'is_read' => (bool) $m->is_read,
            'created_at' => $m->created_at->toISOString(),
            'time' => $m->created_at->format('g:i A'),
            'day' => $m->created_at->format('Y-m-d'),
            'dayLabel' => $m->created_at->isToday() ? 'Today' : ($m->created_at->isYesterday() ? 'Yesterday' : $m->created_at->format('F j, Y')),
            'attachments' => $deleted ? [] : $m->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_filename, 'size' => $a->humanSize(), 'is_image' => $a->isImage(), 'url' => url('storage/' . $a->stored_path)])->values(),
        ];
    }
}
