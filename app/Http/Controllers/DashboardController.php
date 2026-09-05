<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Human-readable labels for the delivery statuses actually used by the
     * Logistics System. Kept here so the dashboard reads as plain language.
     */
    private const STATUS_LABELS = [
        'waiting_for_rider' => 'Waiting for Rider',
        'assigned'          => 'Assigned',
        'accepted'          => 'Accepted',
        'going_to_pickup'   => 'Going to Pickup',
        'arrived_at_shop'   => 'Arrived at Shop',
        'picked_up'         => 'Picked Up',
        'out_for_delivery'  => 'Out for Delivery',
        'arrived_at_customer' => 'Arrived at Customer',
        'delivered'         => 'Delivered',
        'delivery_failed'   => 'Delivery Failed',
        'cancelled'         => 'Cancelled',
    ];

    public function index(Request $request): View
    {
        $user = Auth::user();
        $staffCenterId = $user->isStaff() && $user->center_id ? (int) $user->center_id : null;

        // ── Delivery status counts (single grouped query) ──────────────
        $statusCounts = $this->deliveryQuery($staffCenterId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $deliveries = [
            'total' => (int) $statusCounts->sum(),
            'waiting_for_rider' => (int) ($statusCounts['waiting_for_rider'] ?? 0),
            'assigned' => (int) ($statusCounts['assigned'] ?? 0),
            'out_for_delivery' => (int) ($statusCounts['out_for_delivery'] ?? 0),
            'delivered' => (int) ($statusCounts['delivered'] ?? 0),
            'failed' => (int) ($statusCounts['delivery_failed'] ?? 0),
        ];

        // ── Needs attention ────────────────────────────────────────────
        // Unassigned deliveries waiting for a rider.
        $needsAttention = [
            'waitingForRider' => $deliveries['waiting_for_rider'],
            'failed' => $deliveries['failed'],
            // Parcels received at a center but still being processed (not yet dispatched).
            'processing' => (int) $this->deliveryQuery($staffCenterId)
                ->whereIn('parcel_status', ['received', 'scanned', 'sorted'])
                ->where('status', 'waiting_for_rider')
                ->count(),
        ];

        // ── Riders: online/offline availability ────────────────────────
        $riderQuery = Rider::query();
        if ($staffCenterId) {
            $riderQuery->where('center_id', $staffCenterId);
        }

        $riderCounts = (clone $riderQuery)
            ->selectRaw('COUNT(CASE WHEN is_online = 1 THEN 1 END) as online, COUNT(CASE WHEN is_online = 0 THEN 1 END) as offline, COUNT(*) as total')
            ->first();

        $riders = [
            'total' => (int) $riderCounts->total,
            'online' => (int) $riderCounts->online,
            'offline' => (int) $riderCounts->offline,
        ];

        // ── Rider availability + workload: online riders / rider list ──
        $onlineRiders = (clone $riderQuery)
            ->where('is_online', true)
            ->withCount(['activeDeliveries as active_deliveries'])
            ->with('serviceArea')
            ->orderBy('name')
            ->limit(8)
            ->get();

        $riderWorkload = (clone $riderQuery)
            ->withCount(['activeDeliveries as active_deliveries'])
            ->with('serviceArea')
            ->orderByDesc('is_online')
            ->orderByDesc('active_deliveries')
            ->orderBy('name')
            ->limit(10)
            ->get();

        // ── Financial summary for today (uses stored transaction values) ─
        $today = now()->toDateString();
        $financialQuery = Transaction::query();
        if ($staffCenterId) {
            $financialQuery->where('logistics_center_id', $staffCenterId);
        }
        $financial = $financialQuery->whereDate('created_at', $today)
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as amount,
                COALESCE(SUM(rider_fee), 0) as rider_fee,
                COALESCE(SUM(admin_commission), 0) as commission
            ')->first();

        $financialToday = [
            'period' => 'Today',
            'count' => (int) ($financial->count ?? 0),
            'amount' => (float) ($financial->amount ?? 0),
            'rider_fee' => (float) ($financial->rider_fee ?? 0),
            'commission' => (float) ($financial->commission ?? 0),
        ];

        // ── Service Area overview (total + active deliveries per area) ──
        $areaTotals = $this->deliveryQuery($staffCenterId)
            ->select('service_area_id')
            ->selectRaw('count(*) as total')
            ->groupBy('service_area_id')
            ->pluck('total', 'service_area_id');

        $areaActive = $this->deliveryQuery($staffCenterId)
            ->whereIn('status', Delivery::ACTIVE_STATUSES)
            ->select('service_area_id')
            ->selectRaw('count(*) as active')
            ->groupBy('service_area_id')
            ->pluck('active', 'service_area_id');

        if ($areaTotals->isNotEmpty()) {
            $areaNames = ServiceArea::whereIn('id', $areaTotals->keys())->pluck('name', 'id');
        } else {
            $areaNames = collect();
        }

        $serviceAreas = $areaTotals->map(function ($total, $id) use ($areaActive, $areaNames) {
            return [
                'id' => (int) $id,
                'name' => $areaNames[$id] ?? 'Unassigned Area',
                'total' => (int) $total,
                'active' => (int) ($areaActive[$id] ?? 0),
            ];
        })->values()
            ->sortByDesc('active')
            ->values()
            ->take(8);

        // ── Recent logistics activity (real status-log events) ──────────
        $activityQuery = DeliveryStatusLog::query()
            ->select('delivery_status_logs.*')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_status_logs.delivery_id')
            ->whereNull('deliveries.archived_at')
            ->with(['delivery:id,tracking_number,recipient_name', 'changer:id,name']);

        if ($staffCenterId) {
            $activityQuery->where('deliveries.center_id', $staffCenterId);
        }

        $activities = $activityQuery
            ->orderByDesc('delivery_status_logs.created_at')
            ->limit(8)
            ->get()
            ->map(function ($log) {
                return [
                    'tracking' => $log->delivery?->tracking_number ?? '',
                    'recipient' => $log->delivery?->recipient_name ?? '',
                    'message' => $this->activityMessage($log->status),
                    'status' => $log->status,
                    'created_at' => $log->created_at,
                    'by' => $log->changer?->name,
                ];
            });

        return view('dashboard', [
            'deliveries' => $deliveries,
            'riders' => $riders,
            'onlineRiders' => $onlineRiders,
            'riderWorkload' => $riderWorkload,
            'financialToday' => $financialToday,
            'status_counts' => $statusCounts,
            'status_labels' => self::STATUS_LABELS,
            'needsAttention' => $needsAttention,
            'serviceAreas' => $serviceAreas,
            'activities' => $activities,
            'staffCenterId' => $staffCenterId,
            'activeStatuses' => Delivery::ACTIVE_STATUSES,
            'recent_deliveries' => $this->deliveryQuery($staffCenterId)
                ->latest('updated_at')
                ->with(['rider', 'serviceArea'])
                ->take(6)
                ->get(),
        ]);
    }

    /**
     * Base delivery query with the existing staff center-scoping applied at the
     * query level (never bypassable via a query parameter).
     */
    private function deliveryQuery(?int $staffCenterId)
    {
        $query = Delivery::notArchived();
        if ($staffCenterId) {
            $query->where('center_id', $staffCenterId);
        }
        return $query;
    }

    private function activityMessage(?string $status): string
    {
        return match ($status) {
            'waiting_for_rider' => 'registered, waiting for rider',
            'assigned' => 'assigned to a rider',
            'accepted' => 'accepted by rider',
            'going_to_pickup' => 'rider en route to pickup',
            'arrived_at_shop' => 'rider arrived at shop',
            'picked_up' => 'parcel picked up',
            'out_for_delivery' => 'marked out for delivery',
            'arrived_at_customer' => 'rider arrived at customer',
            'delivered' => 'delivered successfully',
            'delivery_failed' => 'failed delivery',
            'cancelled' => 'cancelled',
            default => 'status updated',
        };
    }
}
