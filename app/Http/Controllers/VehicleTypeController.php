<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VehicleTypeController extends Controller
{
    public function index(Request $request): View
    {
        $types = VehicleType::orderBy('sort_order')->orderBy('id')
            ->withCount('riders')
            ->get();

        return view('vehicle-types.index', [
            'types' => $types,
            'editId' => (int) $request->query('edit', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => Str::slug($request->input('label'))]);

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'name' => 'required|unique:vehicle_types,name',
            'capacity_kg' => 'required|numeric|min:1|max:10000',
        ], [
            'name.unique' => 'A vehicle type like "' . $request->input('label') . '" already exists.',
        ]);

        VehicleType::create([
            'name' => $validated['name'],
            'label' => $validated['label'],
            'capacity_kg' => (float) $validated['capacity_kg'],
            'is_active' => true,
            'sort_order' => (int) VehicleType::max('sort_order') + 1,
        ]);

        return redirect()->to($validated['return'] ?? $request->input('return', route('riders.create')))
            ->with('success', "Vehicle type \"{$validated['label']}\" added successfully.");
    }

    public function update(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'capacity_kg' => 'required|numeric|min:1|max:10000',
        ]);

        $vehicleType->update([
            'label' => $validated['label'],
            'capacity_kg' => (float) $validated['capacity_kg'],
        ]);

        return redirect()->to($request->input('return', route('riders.create')))
            ->with('success', "Vehicle type updated successfully.");
    }

    public function toggle(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        $vehicleType->update(['is_active' => !$vehicleType->is_active]);

        $state = $vehicleType->is_active ? 'activated' : 'deactivated';

        return redirect()->to($request->input('return', route('riders.create')))
            ->with('success', "\"{$vehicleType->label}\" {$state}. Historical records keep their original vehicle type.");
    }
}
