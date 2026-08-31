<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Rider;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $statusCounts = Delivery::notArchived()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total_riders = Rider::count();
        $available_riders = Rider::where('status', 'available')->count();

        return view('dashboard', [
            'total_deliveries' => (int) $statusCounts->sum(),
            'waiting_for_rider' => (int) ($statusCounts['waiting_for_rider'] ?? 0),
            'out_for_delivery' => (int) ($statusCounts['out_for_delivery'] ?? 0),
            'delivered' => (int) ($statusCounts['delivered'] ?? 0),
            'failed' => (int) ($statusCounts['failed'] ?? 0),
            'status_counts' => $statusCounts,
            'total_riders' => $total_riders,
            'available_riders' => $available_riders,
            'recent_deliveries' => Delivery::latest()->notArchived()->with('rider')->take(5)->get(),
        ]);
    }
}
