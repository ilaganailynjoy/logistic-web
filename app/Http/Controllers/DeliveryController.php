<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsSetting;
use App\Models\Rider;
use App\Models\Notification;
use App\Models\LogisticsCenter;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Rules\PhilippinePhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    private function perPage(Request $request): int
    {
        return in_array((int) $request->query('per_page'), [10, 25, 50]) ? (int) $request->query('per_page') : 10;
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        $query->where(function ($w) use ($like, $search) {
            $w->where('tracking_number', 'like', $like)
              ->orWhere('sender_name', 'like', $like)
              ->orWhere('recipient_name', 'like', $like)
              ->orWhere('sender_phone', 'like', $like)
              ->orWhere('recipient_phone', 'like', $like)
              ->orWhere('sender_address', 'like', $like)
              ->orWhere('recipient_address', 'like', $like)
              ->orWhere('notes', 'like', $like)
              ->orWhereHas('rider', fn ($r) => $r->where('name', 'like', $like));

            if (ctype_digit($search)) {
                $w->orWhere('id', (int) $search)
                  ->orWhere('order_id', (int) $search);
            }
        });
    }

    public function index(Request $request): View
    {
        $perPage = $this->perPage($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $riderId = (int) $request->query('rider_id', 0);
        $vehicle = trim((string) $request->query('vehicle_type', ''));
        $centerId = (int) $request->query('center_id', 0);
        $serviceAreaId = (int) $request->query('service_area_id', 0);
        $priority = trim((string) $request->query('priority', ''));

        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Delivery::with(['rider', 'logisticsCenter', 'destinationCenter', 'serviceArea', 'transaction'])->notArchived();

        $user = Auth::user();
        $staffCenterId = null;

        // Staff are always restricted to their assigned logistics center. This
        // is enforced at the query level so a query parameter can never bypass it.
        if ($user->isStaff() && $user->center_id) {
            $staffCenterId = (int) $user->center_id;
            $query->where('center_id', $staffCenterId);
        }

        $this->applySearch($query, $search);

        if ($status !== '' && in_array($status, ['waiting_for_rider', 'assigned', 'picked_up', 'out_for_delivery', 'delivered', 'failed', 'cancelled'])) {
            $query->where('status', $status);
        }

        if ($dateFrom !== '' && strtotime($dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== '' && strtotime($dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($riderId > 0) {
            $query->where('rider_id', $riderId);
        }

        if ($vehicle !== '') {
            $query->whereHas('rider', fn ($r) => $r->whereRaw('LOWER(vehicle_type) = ?', [strtolower($vehicle)]));
        }

        // Center filter is only applied for admins; staff are already scoped above.
        if ($centerId > 0 && $user->isAdmin()) {
            $query->where('center_id', $centerId);
        }

        if ($serviceAreaId > 0) {
            $query->where('service_area_id', $serviceAreaId);
        }

        if ($priority !== '' && in_array($priority, ['normal', 'high', 'urgent'])) {
            $query->where('priority', $priority);
        }

        $this->applySort($query, (string) $request->query('sort', ''), $dir);

        $deliveries = $query->paginate($perPage)->withQueryString();

        return view('deliveries.index', [
            'deliveries' => $deliveries,
            'archivedCount' => Delivery::whereNotNull('archived_at')->count(),
            'filterRiders' => Rider::orderBy('name')->get(['id', 'name']),
            'vehicleTypes' => array_keys(config('logistics.vehicle_capacities')),
            'filterCenters' => LogisticsCenter::orderBy('name')->get(['id', 'name']),
            'filterServiceAreas' => ServiceArea::orderBy('name')->get(['id', 'name', 'logistics_center_id']),
            'filterPriorities' => ['normal', 'high', 'urgent'],
            'staffCenterId' => $staffCenterId,
        ]);
    }

    /**
     * Apply the requested ordering. Column-based sorts use a whitelist; relation
     * sorts use a single LEFT JOIN (each is 1:1 on deliveries, so no rows are
     * duplicated) followed by a select('deliveries.*') to keep only delivery data.
     */
    private function applySort($query, string $sortKey, string $dir): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        switch ($sortKey) {
            case 'rider':
                $query->leftJoin('riders', 'riders.id', '=', 'deliveries.rider_id')
                      ->orderByRaw('CASE WHEN riders.name IS NULL THEN 1 ELSE 0 END')
                      ->orderBy('riders.name', $dir)
                      ->select('deliveries.*');
                break;

            case 'center':
                $query->leftJoin('logistics_centers', 'logistics_centers.id', '=', 'deliveries.center_id')
                      ->orderByRaw('CASE WHEN logistics_centers.name IS NULL THEN 1 ELSE 0 END')
                      ->orderBy('logistics_centers.name', $dir)
                      ->select('deliveries.*');
                break;

            case 'destination':
                $query->leftJoin('logistics_centers as dest_centers', 'dest_centers.id', '=', 'deliveries.destination_center_id')
                      ->orderByRaw('CASE WHEN dest_centers.name IS NULL THEN 1 ELSE 0 END')
                      ->orderBy('dest_centers.name', $dir)
                      ->select('deliveries.*');
                break;

            case 'service_area':
                $query->leftJoin('service_areas', 'service_areas.id', '=', 'deliveries.service_area_id')
                      ->orderByRaw('CASE WHEN service_areas.name IS NULL THEN 1 ELSE 0 END')
                      ->orderBy('service_areas.name', $dir)
                      ->select('deliveries.*');
                break;

            case 'amount':
                // Delivery->transaction is 1:1 (unique constraint on
                // transactions.delivery_id), so this join cannot duplicate rows.
                $query->leftJoin('transactions', 'transactions.delivery_id', '=', 'deliveries.id')
                      ->orderByRaw('CASE WHEN transactions.amount IS NULL THEN 1 ELSE 0 END')
                      ->orderBy('transactions.amount', $dir)
                      ->select('deliveries.*');
                break;

            default:
                $column = match ($sortKey) {
                    'tracking_number' => 'tracking_number',
                    'sender' => 'sender_name',
                    'recipient' => 'recipient_name',
                    'status' => 'status',
                    'date' => 'created_at',
                    'priority' => 'priority',
                    default => 'created_at',
                };
                $query->orderBy($column, $dir)->orderBy('id', $dir);
                break;
        }
    }

    public function show(Delivery $delivery): View
    {
        $delivery->load([
            'statusLogs.changer',
            'proofs.rider',
            'rider',
            'creator',
        ]);

        $weight = (float) ($delivery->weight ?? 0);

        $riderEligibility = Rider::query()
            ->get()
            ->map(function (Rider $rider) use ($weight) {
                $reason = null;

                if (!$rider->isEligibilityApproved()) {
                    $reason = 'Rider application not approved';
                } elseif ($rider->status === 'inactive') {
                    $reason = 'Account inactive';
                } elseif ($rider->vehicle_verification !== 'verified') {
                    $reason = 'Vehicle not verified';
                } elseif (!$rider->vehicleTypeIsActive()) {
                    $reason = 'Vehicle type deactivated';
                } elseif ($rider->deliveries()->whereIn('status', Delivery::ACTIVE_STATUSES)->exists()) {
                    $reason = 'Currently delivering';
                } else {
                    $capacity = $rider->capacityLimit();
                    if ($capacity > 0 && $weight > $capacity) {
                        $reason = "Vehicle capacity insufficient ({$capacity} kg)";
                    }
                }

                return [
                    'rider' => $rider,
                    'eligible' => $reason === null,
                    'reason' => $reason,
                    'capacity' => $rider->capacityLimit(),
                ];
            })
            ->sortBy([['eligible', 'desc'], ['rider.name', 'asc']])
            ->values();

        return view('deliveries.show', [
            'delivery' => $delivery,
            'riderEligibility' => $riderEligibility,
            'failureReasons' => config('logistics.failure_reasons'),
            'cancellationReasons' => config('logistics.cancellation_reasons'),
            'transitions' => config('logistics.transitions'),
            'centers' => LogisticsCenter::orderBy('name')->get(),
            'serviceAreas' => ServiceArea::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('deliveries.create');
    }

    private function validateDelivery(Request $request): array
    {
        return $request->validate([
            'sender_name' => 'required|string|max:255',
            'sender_phone' => ['required', new PhilippinePhone],
            'sender_address' => 'required|string|max:500',
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => ['required', new PhilippinePhone],
            'recipient_address' => 'required|string|max:500',
            'package_type' => 'nullable|in:Document,Parcel,Fragile,Electronics,Groceries,Other',
            'package_description' => 'nullable|string|max:500',
            'weight' => 'nullable|numeric|min:0.01|max:5000',
            'notes' => 'nullable|string|max:1000',
            'estimated_delivery_at' => 'nullable|date|after_or_equal:today',
            'priority' => 'nullable|in:normal,high,urgent',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateDelivery($request);

        $validated['status'] = 'waiting_for_rider';
        $validated['priority'] = $validated['priority'] ?? 'normal';
        $validated['created_by'] = Auth::id();

        $delivery = DB::transaction(function () use ($validated) {
            $delivery = Delivery::create($validated);

            DeliveryStatusLog::create([
                'delivery_id' => $delivery->id,
                'status' => 'waiting_for_rider',
                'notes' => 'Delivery created.',
                'changed_by' => Auth::id(),
            ]);

            return $delivery;
        });

        Notification::create([
            'type' => 'new_delivery_request',
            'title' => 'New Delivery Request',
            'message' => "Order #{$delivery->id} is ready for pickup and needs a rider.",
            'icon' => '📦',
            'priority' => 'high',
            'link' => route('deliveries.show', $delivery),
        ]);

        return redirect()->route('deliveries.show', $delivery)->with('success', 'Delivery created successfully.');
    }

    public function edit(Delivery $delivery): View
    {
        return view('deliveries.edit', [
            'delivery' => $delivery,
        ]);
    }

    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $this->validateDelivery($request);

        $delivery->update($validated);

        return redirect()->route('deliveries.show', $delivery)->with('success', 'Delivery updated successfully.');
    }

    public function assignRider(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'rider_id' => 'required|exists:riders,id',
        ]);

        $rider = Rider::findOrFail($validated['rider_id']);

        if (!$rider->isEligibilityApproved()) {
            return back()->withErrors(['rider_id' => 'Cannot assign delivery. Rider application is not approved.']);
        }

        if ($rider->status === 'inactive') {
            return back()->withErrors(['rider_id' => 'Cannot assign delivery. Rider account is inactive.']);
        }

        if ($rider->vehicle_verification !== 'verified') {
            return back()->withErrors(['rider_id' => 'Cannot assign delivery. Rider vehicle has not been verified.']);
        }

        if (!$rider->vehicleTypeIsActive()) {
            return back()->withErrors(['rider_id' => 'Cannot assign delivery. The rider\u2019s vehicle type has been deactivated.']);
        }

        if ($rider->deliveries()->whereIn('status', Delivery::ACTIVE_STATUSES)->exists()) {
            return back()->withErrors(['rider_id' => 'Cannot assign delivery. Rider is currently delivering.']);
        }

        $weight = (float) ($delivery->weight ?? 0);
        $capacity = $rider->capacityLimit();

        if ($weight > 0 && $capacity > 0 && $weight > $capacity) {
            return back()->withErrors(['rider_id' => 'Vehicle capacity exceeded. Please assign a suitable vehicle.']);
        }

        $delivery->rider_id = $rider->id;
        $delivery->status = 'assigned';

        if (!$delivery->assigned_at) {
            $delivery->assigned_at = now();
        }

        $delivery->save();

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'assigned',
            'notes' => "Assigned to rider {$rider->name} (" . ucfirst($rider->vehicle_type) . ', plate ' . $rider->license_plate . ').',
            'changed_by' => Auth::id(),
        ]);

        if ($rider->status !== 'inactive') {
            $rider->update(['status' => 'delivering']);
        }

        Notification::create([
            'type' => 'rider_accepted_delivery',
            'title' => 'Rider Assigned to Delivery',
            'message' => "Rider {$rider->name} was assigned to Order #{$delivery->id}.",
            'icon' => '🚴',
            'priority' => 'normal',
            'link' => route('deliveries.show', $delivery),
        ]);

        return redirect()->back()->with('success', "Rider {$rider->name} assigned successfully.");
    }

    public function updateStatus(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:waiting_for_rider,assigned,picked_up,out_for_delivery,delivered,failed,cancelled',
            'reason' => 'nullable|string',
            'other_reason' => 'nullable|string|min:3|max:500',
            'override_reason' => 'nullable|required_if:override,1|min:3|max:500',
            'override' => 'nullable|boolean',
        ]);

        $target = $validated['status'];
        $current = $delivery->status;
        $isOverride = $request->boolean('override');

        if ($target === $current) {
            return back()->with('error', 'The delivery is already in this status.');
        }

        $allowed = config("logistics.transitions.$current", []);
        $isValidTransition = in_array($target, $allowed);

        if (!$isValidTransition && !$isOverride) {
            return back()->with('error', "Invalid status transition: cannot change from \"{$current}\" to \"{$target}\". Use the override option with a reason if you are sure.");
        }

        $reasonText = null;

        if ($target === 'failed') {
            $reasons = config('logistics.failure_reasons');
            $request->validate(['reason' => 'required|in:' . implode(',', $reasons)]);
            $reasonText = $validated['reason'];
            if ($reasonText === 'Other') {
                $request->validate(['other_reason' => 'required|min:3|max:500']);
                $reasonText .= ': ' . $validated['other_reason'];
            }
        }

        if ($target === 'cancelled') {
            $reasons = config('logistics.cancellation_reasons');
            $request->validate(['reason' => 'required|in:' . implode(',', $reasons)]);
            $reasonText = $validated['reason'];
            if ($reasonText === 'Other') {
                $request->validate(['other_reason' => 'required|min:3|max:500']);
                $reasonText .= ': ' . $validated['other_reason'];
            }
        }

        if ($isOverride) {
            $reasonText = trim('Status override: ' . ($validated['override_reason'] ?? ''));
        }

        if ($target === 'delivered') {
            $requireProof = LogisticsSetting::forUser(Auth::id())->delivery['require_proof'] ?? true;

            if ($requireProof && !$delivery->proofs()->exists()) {
                return back()->with('error', 'Proof of delivery is required before marking this delivery as delivered. Upload a photo or signature confirmation first.');
            }
        }

        $rider = $delivery->rider;
        $previousStatus = $current;

        DB::transaction(function () use ($delivery, $target, $reasonText, $isOverride, $previousStatus) {
            $delivery->status = $target;

            $now = now();
            match ($target) {
                'assigned' => $delivery->assigned_at = $delivery->assigned_at ?? $now,
                'picked_up' => $delivery->picked_up_at = $now,
                'delivered' => $delivery->delivered_at = $now,
                'failed' => $delivery->failed_at = $now,
                'cancelled' => $delivery->cancelled_at = $now,
                default => null,
            };

            if ($target === 'failed') {
                $delivery->failure_reason = $reasonText;
            } elseif ($target === 'cancelled') {
                $delivery->cancellation_reason = $reasonText;
            }

            $delivery->save();

            DeliveryStatusLog::create([
                'delivery_id' => $delivery->id,
                'status' => $target,
                'notes' => $isOverride
                    ? ($reasonText . " (overridden from \"{$previousStatus}\" by administrator)")
                    : $reasonText,
                'changed_by' => Auth::id(),
            ]);
        });

        $this->syncRiderStatus($rider);

        if ($target === 'delivered') {
            app(TransactionController::class)->storeForDelivery($delivery);
        }

        $riderName = $rider->name ?? 'Rider';
        $statusMessages = [
            'picked_up' => ['Rider Picked Up Order', "{$riderName} has picked up Order #{$delivery->id} from sender.", '📍', 'normal'],
            'out_for_delivery' => ['Out for Delivery', "Order #{$delivery->id} is now out for delivery.", '🚚', 'normal'],
            'delivered' => ['Delivery Completed', "Order #{$delivery->id} was successfully delivered.", '✅', 'normal'],
            'failed' => ['Delivery Failed', "Order #{$delivery->id} failed. Reason: {$reasonText}", '⚠️', 'high'],
            'cancelled' => ['Delivery Cancelled', "Order #{$delivery->id} was cancelled. Reason: {$reasonText}", '🚫', 'high'],
        ];

        if (isset($statusMessages[$target])) {
            [$title, $message, $icon, $priority] = $statusMessages[$target];
            Notification::create([
                'type' => 'delivery_' . $target,
                'title' => $title,
                'message' => $message,
                'icon' => $icon,
                'priority' => $priority,
                'link' => route('deliveries.show', $delivery),
            ]);
        }

        $label = str_replace('_', ' ', $target);

        return redirect()->back()->with('success', ucfirst($label) . ($isOverride ? ' (override applied).' : '.'));
    }

    private function syncRiderStatus(?Rider $rider): void
    {
        if (!$rider || $rider->status === 'inactive') {
            return;
        }

        $hasActive = $rider->deliveries()
            ->whereIn('status', Delivery::ACTIVE_STATUSES)
            ->exists();

        $eligible = $rider->vehicle_verification === 'verified'
            && $rider->vehicleTypeIsActive()
            && $rider->approved_at !== null;

        $rider->update(['status' => ($eligible && !$hasActive) ? 'available' : 'inactive']);
    }

    public function archive(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only administrators can archive deliveries.');
        }

        $validated = $request->validate([
            'archive_note' => 'nullable|string|max:255',
        ]);

        if ($delivery->archived_at) {
            return back()->with('error', 'This delivery is already archived.');
        }

        $delivery->update([
            'archived_at' => now(),
            'archived_by' => Auth::id(),
            'archive_note' => $validated['archive_note'] ?? null,
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'archived',
            'notes' => 'Delivery archived. ' . ($validated['archive_note'] ?? ''),
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('deliveries.index')->with('success', 'Delivery archived successfully.');
    }

    public function restore(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only administrators can restore deliveries.');
        }

        if (!$delivery->archived_at) {
            return back()->with('error', 'This delivery is not archived.');
        }

        $delivery->update([
            'archived_at' => null,
            'archived_by' => null,
            'archive_note' => null,
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'restored',
            'notes' => 'Delivery restored from archive.',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->route('deliveries.archived')->with('success', 'Delivery restored successfully.');
    }

    public function destroy(Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->can('permanentlyDelete')) {
            abort(403, 'Only administrators can permanently delete deliveries. Archive it instead.');
        }

        $delivery->delete();

        return redirect()->route('deliveries.archived')->with('success', 'Delivery permanently deleted.');
    }

    public function receive(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'center_id' => 'required|exists:logistics_centers,id',
        ]);

        if ($delivery->parcel_status !== 'pending_arrival') {
            return back()->with('error', 'This parcel has already been received.');
        }

        $user = Auth::user();
        if ($user->isStaff() && $user->center_id != $validated['center_id']) {
            abort(403, 'You can only receive parcels at your assigned logistics center.');
        }

        $delivery->update([
            'center_id' => $validated['center_id'],
            'parcel_status' => 'received',
            'received_at' => now(),
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'received',
            'notes' => 'Parcel received at logistics center.',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Parcel received and logged.');
    }

    public function scan(Delivery $delivery): RedirectResponse
    {
        if ($delivery->parcel_status !== 'received') {
            return back()->with('error', 'Parcel must be received before scanning.');
        }

        $user = Auth::user();
        if ($user->isStaff() && $user->center_id && $delivery->center_id != $user->center_id) {
            abort(403, 'You can only scan parcels at your assigned logistics center.');
        }

        $delivery->update([
            'parcel_status' => 'scanned',
            'scanned_at' => now(),
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'scanned',
            'notes' => 'Parcel scanned and verified.',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Parcel scanned successfully.');
    }

    public function sort(Request $request, Delivery $delivery): RedirectResponse
    {
        $validated = $request->validate([
            'destination_center_id' => 'required|exists:logistics_centers,id',
            'service_area_id' => 'required|exists:service_areas,id',
        ]);

        if (!in_array($delivery->parcel_status, ['received', 'scanned'])) {
            return back()->with('error', 'Parcel must be received and scanned before sorting.');
        }

        $user = Auth::user();
        if ($user->isStaff() && $user->center_id && $delivery->center_id != $user->center_id) {
            abort(403, 'You can only sort parcels at your assigned logistics center.');
        }

        $delivery->update([
            'destination_center_id' => $validated['destination_center_id'],
            'service_area_id' => $validated['service_area_id'],
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => 'sorted',
            'notes' => 'Parcel sorted. Destination: ' . LogisticsCenter::find($validated['destination_center_id'])->name . '.',
            'changed_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Parcel sorted successfully.');
    }

    public function archived(Request $request): View
    {
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only administrators can access archived deliveries.');
        }

        $perPage = $this->perPage($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Delivery::with(['rider', 'archiver'])->whereNotNull('archived_at');

        $this->applySearch($query, $search);

        if ($status !== '') {
            $query->where('status', $status);
        }

        $deliveries = $query->orderByDesc('archived_at')->paginate($perPage)->withQueryString();

        return view('deliveries.archived', [
            'deliveries' => $deliveries,
        ]);
    }
}
