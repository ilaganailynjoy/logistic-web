@php
    $returnUrl = url()->current();
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6"
     x-data="{ editId: @js(request('edit_type')), addOpen: false }">
    <div class="flex items-center justify-between mb-1">
        <h3 class="text-base font-bold text-gray-900">Vehicle Types</h3>
        <button type="button" @click="addOpen = !addOpen"
                class="text-teal hover:text-teal-dark text-sm font-semibold whitespace-nowrap">
            + Add
        </button>
    </div>
    <p class="text-xs text-gray-400 mb-4">Capacities are enforced when assigning deliveries.</p>

    {{-- Inline add form --}}
    <form action="{{ route('vehicle-types.store') }}" method="POST" x-show="addOpen" x-cloak class="mb-4 p-3 bg-gray-50 rounded-xl space-y-2" x-transition>
        @csrf
        <input type="hidden" name="return" value="{{ $returnUrl }}">
        <input type="text" name="label" required maxlength="100" placeholder="Vehicle name (e.g. E-Trike)"
               class="w-full rounded-lg border-gray-300 focus:border-teal focus:ring-teal text-sm">
        <div class="relative">
            <input type="number" step="0.01" min="1" max="10000" name="capacity_kg" required placeholder="Max capacity"
                   class="w-full rounded-lg border-gray-300 focus:border-teal focus:ring-teal text-sm pr-8">
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">kg</span>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-teal hover:bg-teal-dark text-white py-2 rounded-lg text-xs font-semibold transition">Add Type</button>
            <button type="button" @click="addOpen = false" class="px-3 bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 py-2 rounded-lg text-xs font-semibold transition">Cancel</button>
        </div>
    </form>

    <ul class="divide-y divide-gray-100">
        @forelse($vehicleTypes as $type)
            <li class="py-2.5" x-data="{ editing: false }">
                <div x-show="!editing">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate">
                                {{ $type->label }}
                                @if(!$type->is_active)
                                    <span class="ml-1 text-[10px] font-bold uppercase text-gray-400">(deactivated)</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $type->capacity_kg }} kg · {{ $type->riders_count }} rider{{ $type->riders_count === 1 ? '' : 's' }}</p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button type="button" @click="editing = true" class="text-xs font-semibold text-gray-400 hover:text-gray-700">Edit</button>
                            <form action="{{ route('vehicle-types.toggle', $type) }}" method="POST" x-data
                                  x-on:submit.prevent="if (confirm($el.dataset.msg)) $el.submit()"
                                  data-msg="{{ $type->is_active ? 'Deactivate ' . $type->label . '? Riders with this vehicle type can no longer be assigned new deliveries.' : 'Activate ' . $type->label . '?' }}">
                                @csrf
                                <input type="hidden" name="return" value="{{ $returnUrl }}">
                                <button type="submit" class="text-xs font-semibold {{ $type->is_active ? 'text-amber-600 hover:text-amber-700' : 'text-emerald-600 hover:text-emerald-700' }}">
                                    {{ $type->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div x-show="editing" x-cloak>
                    <form action="{{ route('vehicle-types.update', $type) }}" method="POST" class="space-y-2">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return" value="{{ $returnUrl }}">
                        <input type="hidden" name="label" value="{{ $type->label }}">
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" min="1" max="10000" name="capacity_kg" value="{{ old('capacity_kg', $type->capacity_kg) }}"
                                   class="flex-1 rounded-lg border-gray-300 focus:border-teal focus:ring-teal text-sm">
                            <span class="text-xs text-gray-400 flex-shrink-0">kg</span>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 bg-teal hover:bg-teal-dark text-white py-1.5 rounded-lg text-xs font-semibold transition">Save</button>
                            <button type="button" @click="editing = false" class="px-3 bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 py-1.5 rounded-lg text-xs font-semibold transition">Cancel</button>
                        </div>
                    </form>
                </div>
            </li>
        @empty
            <li class="py-3 text-sm text-gray-500">No vehicle types yet — add one above.</li>
        @endforelse
    </ul>
</div>
