<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50]) ? (int) $request->query('per_page') : 10;
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $serviceAreaId = (int) $request->query('service_area_id', 0);
        $centerId = (int) $request->query('center_id', 0);

        $query = Rider::query()->with(['logisticsCenter', 'serviceArea']);

        $user = Auth::user();

        // Staff are always restricted to their assigned logistics center. This is
        // enforced at the query level so a query parameter can never bypass it.
        if ($user->isStaff() && $user->center_id) {
            $query->where('center_id', $user->center_id);
        } elseif ($centerId > 0) {
            // Only admins (or staff without a center) may pick an arbitrary center.
            $query->where('center_id', $centerId);
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('vehicle_type', 'like', $like)
                  ->orWhere('license_plate', 'like', $like);
            });
        }

        if ($status !== '' && in_array($status, ['available', 'delivering', 'inactive'])) {
            $query->where('status', $status);
        }

        if ($serviceAreaId > 0) {
            $query->where('service_area_id', $serviceAreaId);
        }

        $riders = $query->orderBy('name')->paginate($perPage)->withQueryString();

        return view('riders.index', [
            'riders' => $riders,
            'filterServiceAreas' => ServiceArea::orderBy('name')->get(['id', 'name']),
            'filterCenters' => LogisticsCenter::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Rider $rider): View
    {
        $rider->load(['logisticsCenter', 'serviceArea']);
        $rider->load(['deliveries' => function ($query) {
            $query->notArchived()->latest()->take(10);
        }]);

        $currentDelivery = $rider->activeDeliveries()->with('rider')->first();

        return view('riders.show', [
            'rider' => $rider,
            'currentDelivery' => $currentDelivery,
        ]);
    }
}
