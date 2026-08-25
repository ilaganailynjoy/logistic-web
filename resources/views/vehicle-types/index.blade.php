<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Vehicle Types</h1>
                <p class="mt-1 text-sm text-gray-500">Centralized vehicle configuration — capacities apply to all delivery assignments</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        {{-- Add new --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 class="text-base font-bold text-gray-900 mb-4">Add Vehicle Type</h3>
            <form action="{{ route('vehicle-types.store') }}" method="POST" class="flex flex-col sm:flex-row items-end gap-3">
                @csrf
                <div class="flex-1 w-full">
                    <label for="label" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="label" id="label" value="{{ old('label') }}" required placeholder="e.g. Motorcycle"
                           class="w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal shadow-sm text-sm">
                </div>
                <div class="w-full sm:w-44">
                    <label for="capacity_kg" class="block text-sm font-medium text-gray-700 mb-1">Max Capacity (kg)</label>
                    <input type="number" step="0.01" min="1" max="10000" name="capacity_kg" value="{{ old('capacity_kg') }}" required placeholder="30"
                           class="w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal shadow-sm text-sm">
                </div>
                <button type="submit" class="bg-teal hover:bg-teal-dark text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition whitespace-nowrap">
                    Add Type
                </button>
            </form>
        </section>

        {{-- List --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Vehicle</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Max Capacity</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Riders Using</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($types as $type)
                        @php $isEditing = $editId === $type->id; @endphp
                        <tr class="{{ $isEditing ? 'bg-teal-light/40' : 'hover:bg-gray-50' }} transition">
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $type->label }}</td>
                            <td class="px-6 py-4">
                                @if($isEditing)
                                    <form action="{{ route('vehicle-types.update', $type) }}" method="POST" id="edit-form-{{ $type->id }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="label" value="{{ old('label', $type->label) }}">
                                        <input type="number" step="0.01" min="1" max="10000" name="capacity_kg" value="{{ old('capacity_kg', $type->capacity_kg) }}"
                                               class="w-28 rounded-lg border-gray-300 focus:border-teal focus:ring-teal text-sm">
                                        <span class="text-xs text-gray-400">kg</span>
                                    </form>
                                @else
                                    <span class="text-sm text-gray-900">{{ $type->capacity_kg }} kg</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <a href="{{ route('riders.index', ['search' => $type->name]) }}" class="text-sm text-gray-600 hover:text-teal-dark">
                                    {{ $type->riders_count }} rider{{ $type->riders_count === 1 ? '' : 's' }}
                                </a>
                            </td>
                            <td class="px-6 py-4">
                                @if($type->is_active)
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-500 ring-1 ring-inset ring-gray-200">Deactivated</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap space-x-3">
                                @if($isEditing)
                                    <button type="submit" form="edit-form-{{ $type->id }}" class="text-sm font-semibold text-teal hover:text-teal-dark">Save</button>
                                    <a href="{{ route('vehicle-types.index') }}" class="text-sm font-semibold text-gray-400 hover:text-gray-600">Cancel</a>
                                @else
                                    <a href="{{ route('vehicle-types.index', ['edit' => $type->id]) }}"
                                       class="text-sm font-semibold text-gray-500 hover:text-gray-800">Edit</a>
                                    <form action="{{ route('vehicle-types.toggle', $type) }}" method="POST" class="inline" x-data
                                          x-on:submit.prevent="if (confirm($el.dataset.msg)) $el.submit()"
                                          data-msg="{{ $type->is_active ? 'Deactivate ' . $type->label . '? Riders with this vehicle type can no longer be assigned new deliveries.' : 'Activate ' . $type->label . '?' }}">
                                        @csrf
                                        <button type="submit" class="text-sm font-semibold {{ $type->is_active ? 'text-amber-600 hover:text-amber-700' : 'text-emerald-600 hover:text-emerald-700' }}">
                                            {{ $type->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No vehicle types configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-6 py-4 bg-gray-50/50 border-t border-gray-100">
                <p class="text-xs text-gray-500">Deactivating a type never deletes history — riders and deliveries keep displaying their original vehicle type.</p>
            </div>
        </section>
    </div>
</x-app-layout>
