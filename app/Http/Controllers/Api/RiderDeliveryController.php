<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryFailure;
use App\Models\DeliveryProof;
use App\Models\DeliveryStatusLog;
use App\Models\Rider;
use App\Models\RiderEarning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RiderDeliveryController extends Controller
{
    private const ACTIVE_STATUSES = [
        'assigned', 'accepted', 'going_to_pickup', 'arrived_at_shop',
        'picked_up', 'out_for_delivery', 'arrived_at_customer',
    ];

    private const TRANSITIONS = [
        'assigned' => ['accepted', 'cancelled'],
        'accepted' => ['going_to_pickup'],
        'going_to_pickup' => ['arrived_at_shop', 'picked_up'],
        'arrived_at_shop' => ['picked_up'],
        'picked_up' => ['out_for_delivery'],
        'out_for_delivery' => ['arrived_at_customer'],
        'arrived_at_customer' => ['delivered'],
    ];

    /**
     * List the rider's deliveries, optionally filtered.
     */
    public function index(Request $request): JsonResponse
    {
        $rider = $request->user()->rider;

        $validated = $request->validate([
            'status' => 'sometimes|string|in:new,accepted,pickup,in_transit,delivered,failed,cancelled,all',
        ]);

        $query = Delivery::with(['items', 'rider'])->where('rider_id', $rider->id);

        switch ($validated['status'] ?? 'all') {
            case 'new':
                $query->where('status', 'assigned');
                break;
            case 'accepted':
                $query->where('status', 'accepted');
                break;
            case 'pickup':
                $query->whereIn('status', ['accepted', 'going_to_pickup', 'arrived_at_shop']);
                break;
            case 'in_transit':
                $query->whereIn('status', ['picked_up', 'out_for_delivery', 'arrived_at_customer']);
                break;
            case 'delivered':
                $query->where('status', 'delivered');
                break;
            case 'failed':
                $query->where('status', 'delivery_failed');
                break;
            case 'cancelled':
                $query->where('status', 'cancelled');
                break;
        }

        $deliveries = $query->latest()->paginate(20);

        return response()->json([
            'deliveries' => collect($deliveries->items())
                ->map(fn ($d) => $this->payload($d))->values(),
            'pagination' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'total' => $deliveries->total(),
                'per_page' => $deliveries->perPage(),
            ],
        ]);
    }

    /**
     * Show a delivery assigned to the rider.
     */
    public function show(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        $delivery->load(['items', 'statusLogs' => fn ($q) => $q->latest(), 'proof', 'failure']);

        return response()->json([
            'delivery' => $this->detailPayload($delivery),
        ]);
    }

    /**
     * Accept an assigned delivery.
     */
    public function accept(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        if (! in_array('accepted', self::TRANSITIONS[$delivery->status] ?? [])) {
            return $this->invalidTransition($delivery);
        }

        $delivery->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->logStatus($delivery, 'accepted', 'Delivery accepted by rider.');
        $this->markRiderDelivering($delivery->rider);

        return response()->json([
            'message' => 'Delivery accepted.',
            'delivery' => $this->detailPayload($delivery->load(['items', 'statusLogs', 'proof', 'failure'])),
        ]);
    }

    /**
     * Generic status update (enforces the state machine).
     */
    public function updateStatus(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        $validated = $request->validate([
            'status' => 'required|string|in:going_to_pickup,arrived_at_shop,out_for_delivery,arrived_at_customer',
        ]);

        $next = $validated['status'];

        if (! in_array($next, self::TRANSITIONS[$delivery->status] ?? [])) {
            return $this->invalidTransition($delivery);
        }

        $delivery->update(['status' => $next]);

        $this->logStatus($delivery, $next, $this->transitionNote($next));

        return response()->json([
            'message' => 'Delivery status updated.',
            'delivery' => $this->detailPayload($delivery->load(['items', 'statusLogs', 'proof', 'failure'])),
        ]);
    }

    /**
     * Confirm pickup at the shop (optionally PIN-verified).
     */
    public function pickup(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        if (! in_array($delivery->status, ['going_to_pickup', 'arrived_at_shop'])) {
            return $this->invalidTransition($delivery);
        }

        $validated = $request->validate([
            'pickup_pin' => 'sometimes|string|max:10',
        ]);

        if (! empty($delivery->pickup_pin)) {
            if (empty($validated['pickup_pin']) || $validated['pickup_pin'] !== $delivery->pickup_pin) {
                return response()->json([
                    'message' => 'Invalid pickup PIN. Please verify the PIN with the shop.',
                    'errors' => ['pickup_pin' => ['The pickup PIN is incorrect.']],
                ], 422);
            }
        }

        $delivery->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);

        $this->logStatus($delivery, 'picked_up', 'Package picked up from shop.');

        return response()->json([
            'message' => 'Pickup confirmed.',
            'delivery' => $this->detailPayload($delivery->load(['items', 'statusLogs', 'proof', 'failure'])),
        ]);
    }

    /**
     * Complete the delivery with optional proof of delivery + COD settlement.
     */
    public function complete(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        if (! in_array($delivery->status, ['out_for_delivery', 'arrived_at_customer'])) {
            return $this->invalidTransition($delivery);
        }

        $validated = $request->validate([
            'proof_type' => 'sometimes|string|in:photo,signature,otp',
            'photo' => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:8192',
            'signature_name' => 'sometimes|string|max:255',
            'otp' => 'sometimes|string|max:10',
            'amount_received' => 'sometimes|numeric|min:0',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
        ]);

        // COD: the rider must record the received amount.
        if ($delivery->payment_method === 'cash_on_delivery' && empty($validated['amount_received'])) {
            return response()->json([
                'message' => 'Please record the amount received from the customer (Cash on Delivery).',
                'errors' => ['amount_received' => ['The amount received is required for COD deliveries.']],
            ], 422);
        }

        $proofPath = null;
        if (! empty($validated['photo'])) {
            $proofPath = $request->file('photo')->store('delivery-proofs', 'public');
        }

        if (! empty($validated['proof_type']) || ! empty($validated['photo']) || $delivery->payment_method === 'cash_on_delivery') {
            DeliveryProof::create([
                'delivery_id' => $delivery->id,
                'rider_id' => $delivery->rider_id,
                'type' => $validated['proof_type'] ?? ($proofPath ? 'photo' : ($delivery->payment_method === 'cash_on_delivery' ? 'signature' : 'photo')),
                'file_path' => $proofPath,
                'signature_name' => $validated['signature_name'] ?? $delivery->recipient_name,
                'otp' => $validated['otp'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'verified_at' => now(),
            ]);
        }

        $delivery->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $this->logStatus($delivery, 'delivered', 'Package delivered to customer.');

        // Record rider earnings for this delivery.
        $earned = $delivery->delivery_fee !== null
            ? (float) $delivery->delivery_fee
            : 50.00;

        RiderEarning::create([
            'rider_id' => $delivery->rider_id,
            'delivery_id' => $delivery->id,
            'type' => 'delivery',
            'amount' => $earned,
            'earned_on' => now()->toDateString(),
            'description' => "Delivery {$delivery->tracking_number}",
        ]);

        $this->markRiderAvailableIfIdle($delivery->rider);

        return response()->json([
            'message' => 'Delivery completed successfully.',
            'earned' => $earned,
            'delivery' => $this->detailPayload($delivery->load(['items', 'statusLogs', 'proof', 'failure'])),
        ]);
    }

    /**
     * Report a failed delivery.
     */
    public function failed(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDelivery($request, $delivery);

        if (! in_array($delivery->status, ['picked_up', 'out_for_delivery', 'arrived_at_customer'])) {
            return $this->invalidTransition($delivery);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $delivery->update([
            'status' => 'delivery_failed',
            'failed_at' => now(),
            'failure_reason' => $validated['reason'],
        ]);

        DeliveryFailure::create([
            'delivery_id' => $delivery->id,
            'rider_id' => $delivery->rider_id,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'reported_at' => now(),
        ]);

        $this->logStatus($delivery, 'delivery_failed', 'Delivery failed: ' . $validated['reason']);

        $this->markRiderAvailableIfIdle($delivery->rider);

        return response()->json([
            'message' => 'Failed delivery reported.',
            'delivery' => $this->detailPayload($delivery->load(['items', 'statusLogs', 'proof', 'failure'])),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function authorizeDelivery(Request $request, Delivery $delivery): void
    {
        $rider = $request->user()->rider;

        if ((int) $delivery->rider_id !== (int) $rider->id) {
            abort(403, 'This delivery is not assigned to you.');
        }
    }

    private function invalidTransition(Delivery $delivery): JsonResponse
    {
        return response()->json([
            'message' => "Cannot move delivery from '{$this->label($delivery->status)}' to the requested state.",
            'errors' => [
                'status' => ["Invalid status transition from '{$delivery->status}'."],
            ],
        ], 409);
    }

    private function logStatus(Delivery $delivery, string $status, ?string $note): void
    {
        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => $status,
            'notes' => $note,
        ]);
    }

    private function transitionNote(string $status): string
    {
        return match ($status) {
            'going_to_pickup' => 'Rider is on the way to the shop.',
            'arrived_at_shop' => 'Rider arrived at the shop.',
            'out_for_delivery' => 'Out for delivery.',
            'arrived_at_customer' => 'Rider arrived at the customer.',
            default => $this->label($status),
        };
    }

    private function markRiderDelivering(Rider $rider): void
    {
        $rider->update(['status' => 'delivering']);
    }

    private function markRiderAvailableIfIdle(Rider $rider): void
    {
        $active = Delivery::where('rider_id', $rider->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        if ($active === 0) {
            $rider->update(['status' => 'available']);
        }
    }

    private function label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    public function detailPayload(Delivery $delivery): array
    {
        $controller = app(RiderController::class);
        $base = $controller->deliveryPayload($delivery);

        $base['status_logs'] = $delivery->statusLogs->map(fn ($log) => [
            'status' => $log->status,
            'notes' => $log->notes,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values();

        return $base;
    }

    public function payload(Delivery $delivery): array
    {
        return app(RiderController::class)->deliveryPayload($delivery);
    }
}