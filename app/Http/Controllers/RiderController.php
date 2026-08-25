<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use App\Models\Delivery;
use App\Models\Notification;
use App\Rules\PhilippinePhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50]) ? (int) $request->query('per_page') : 10;
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $sortMap = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'vehicle' => 'vehicle_type',
            'plate' => 'license_plate',
            'status' => 'status',
        ];
        $sort = $sortMap[(string) $request->query('sort', '')] ?? 'created_at';
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Rider::query();

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('vehicle_type', 'like', $like)
                  ->orWhere('license_plate', 'like', $like);
            });
        }

        if ($status !== '' && in_array($status, ['available', 'delivering', 'inactive'])) {
            $query->where('status', $status);
        }

        $riders = $query->orderBy($sort, $dir)->orderBy('id', $dir)->paginate($perPage)->withQueryString();

        return view('riders.index', [
            'riders' => $riders,
        ]);
    }

    public function show(Rider $rider): View
    {
        $rider->load(['deliveries' => function ($query) {
            $query->notArchived()->latest()->take(10);
        }]);

        $currentDelivery = $rider->activeDeliveries()->with('rider')->first();
        $application = \App\Models\RiderApplication::where('email', $rider->email)
            ->orderByDesc('id')
            ->first();

        return view('riders.show', [
            'rider' => $rider,
            'currentDelivery' => $currentDelivery,
            'application' => $application,
        ]);
    }

    public function create(): View
    {
        return view('riders.create', [
            'vehicleTypes' => \App\Models\VehicleType::query()
                ->withCount('riders')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRider($request);

        $validated['status'] = 'available';
        $validated['is_verified'] = true;
        $validated['approved_at'] = now();
        $validated['vehicle_verification'] = 'verified';
        $validated['vehicle_verified_at'] = now();

        Rider::create($validated);

        return redirect()->route('riders.index')->with('success', 'Rider created successfully.');
    }

    public function edit(Rider $rider): View
    {
        return view('riders.edit', [
            'rider' => $rider,
            'vehicleTypes' => \App\Models\VehicleType::query()
                ->withCount('riders')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function update(Request $request, Rider $rider): RedirectResponse
    {
        $validated = $this->validateRider($request, $rider);

        $oldStatus = $rider->status;
        $rider->update($validated);

        if ($oldStatus !== $validated['status']) {
            $statusLabels = ['available' => 'online', 'inactive' => 'offline', 'delivering' => 'delivering'];
            Notification::create([
                'type' => 'rider_status',
                'title' => 'Rider Status Changed',
                'message' => "Rider {$rider->name} is now " . ($statusLabels[$validated['status']] ?? $validated['status']) . ".",
                'icon' => '👤',
                'priority' => 'low',
                'link' => route('riders.show', $rider),
            ]);
        }

        return redirect()->route('riders.show', $rider)->with('success', 'Rider updated successfully.');
    }

    private function validateRider(Request $request, ?Rider $rider = null): array
    {
        $request->merge([
            'vehicle_type' => strtolower(trim((string) $request->input('vehicle_type'))),
        ]);

        $activeTypeNames = \App\Models\VehicleType::where('is_active', true)->pluck('name')->all();
        $allowedTypes = array_unique(array_merge($activeTypeNames, $rider ? [strtolower((string) $rider->vehicle_type)] : []));

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:riders,email,' . ($rider?->id ?? 'NULL'),
            'phone' => ['required', new PhilippinePhone, 'unique:riders,phone,' . ($rider?->id ?? 'NULL')],
            'vehicle_type' => 'required|in:' . implode(',', $allowedTypes),
            'license_plate' => 'required|string|max:20',
            'vehicle_capacity_kg' => 'nullable|numeric|min:1|max:5000',
            'status' => 'required|string|in:available,delivering,inactive',
            'is_verified' => 'nullable|boolean',
        ]);

        $data['phone'] = PhilippinePhone::normalize($data['phone']);
        $data['is_verified'] = $request->boolean('is_verified');

        return $data;
    }

    public function verifyVehicle(Request $request, Rider $rider): RedirectResponse
    {
        if ($rider->vehicle_verification === 'verified') {
            return back()->with('error', "Rider {$rider->name}'s vehicle is already verified.");
        }

        $rider->update([
            'vehicle_verification' => 'verified',
            'vehicle_verification_note' => null,
            'vehicle_verified_at' => now(),
            'vehicle_verified_by' => auth()->id(),
        ]);

        $this->syncAvailability($rider);

        return redirect()->back()->with('success', "Vehicle verified for {$rider->name}. The rider is now eligible for delivery assignments.");
    }

    public function rejectVehicle(Request $request, Rider $rider): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'A rejection reason is required.',
        ]);

        $rider->update([
            'vehicle_verification' => 'rejected',
            'vehicle_verification_note' => $validated['reason'],
            'vehicle_verified_at' => now(),
            'vehicle_verified_by' => auth()->id(),
            'status' => 'inactive',
        ]);

        return redirect()->back()->with('success', "Vehicle rejected for {$rider->name}. The rider has been marked inactive until documents are corrected.");
    }

    private function syncAvailability(Rider $rider): void
    {
        if ($rider->status === 'delivering') {
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

    public function destroy(Rider $rider): RedirectResponse
    {
        $rider->delete();

        return redirect()->back()->with('success', 'Rider deleted successfully.');
    }
}
