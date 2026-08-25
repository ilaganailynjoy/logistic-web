<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('riders.index') }}" class="p-2 rounded-xl bg-white border border-gray-100 text-gray-500 hover:text-teal hover:border-teal transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Edit Rider</h1>
                <p class="mt-1 text-sm text-gray-500">Update rider details and status</p>
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8">
            <form action="{{ route('riders.update', $rider) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $rider->name) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('name') border-red-500 @enderror">
                    @error('name')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $rider->email) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('email') border-red-500 @enderror">
                    @error('email')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone', $rider->phone) }}" required placeholder="09XXXXXXXXX"
                           pattern="(\+?639|09)\d{9}" title="Format: 09XXXXXXXXX or +639XXXXXXXXX"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('phone') border-red-500 @enderror">
                    <p class="mt-1 text-xs text-gray-400">Philippine mobile number — 09XXXXXXXXX or +639XXXXXXXXX</p>
                    @error('phone')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="vehicle_type" class="block text-sm font-medium text-gray-700 mb-1.5">Vehicle Type</label>
                    <select name="vehicle_type" id="vehicle_type" required
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('vehicle_type') border-red-500 @enderror">
                        @php
                            $currentType = strtolower(trim((string) $rider->vehicle_type));
                            $options = $vehicleTypes->filter(function ($t) use ($currentType) {
                                return $t->is_active || strtolower($t->name) === $currentType;
                            });
                        @endphp
                        @foreach($options as $type)
                            <option value="{{ $type->name }}" {{ old('vehicle_type', $currentType) === $type->name ? 'selected' : '' }}>
                                {{ $type->label }} (up to {{ $type->capacity_kg }} kg){{ $type->is_active ? '' : ' — deactivated' }}
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="vehicle_capacity_kg" class="block text-sm font-medium text-gray-700 mb-1.5">Vehicle Capacity Override (kg) <span class="text-gray-400 font-normal">optional</span></label>
                    <input type="number" step="0.01" min="1" max="5000" name="vehicle_capacity_kg" id="vehicle_capacity_kg" value="{{ old('vehicle_capacity_kg', $rider->vehicle_capacity_kg) }}"
                           placeholder="Leave empty to use the vehicle type default"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('vehicle_capacity_kg') border-red-500 @enderror">
                    @error('vehicle_capacity_kg')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="license_plate" class="block text-sm font-medium text-gray-700 mb-1.5">License Plate</label>
                    <input type="text" name="license_plate" id="license_plate" value="{{ old('license_plate', $rider->license_plate) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('license_plate') border-red-500 @enderror">
                    @error('license_plate')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_verified" value="0">
                        <input type="checkbox" name="is_verified" value="1" {{ old('is_verified', $rider->is_verified) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-teal focus:ring-teal">
                        <span class="text-sm font-medium text-gray-700">Verified rider</span>
                    </label>
                </div>

                <div class="mb-6">
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1.5">Status</label>
                    <select name="status" id="status"
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('status') border-red-500 @enderror">
                        <option value="available" {{ old('status', $rider->status) === 'available' ? 'selected' : '' }}>Available</option>
                        <option value="delivering" {{ old('status', $rider->status) === 'delivering' ? 'selected' : '' }}>Delivering</option>
                        <option value="inactive" {{ old('status', $rider->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    @error('status')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-4 pt-6 border-t border-gray-100">
                    <a href="{{ route('riders.index') }}" class="text-sm font-semibold text-gray-500 hover:text-gray-700">Cancel</a>
                    <button type="submit" class="bg-teal hover:bg-teal-dark text-white px-6 py-2.5 rounded-xl text-sm font-semibold transition">
                        Update
                    </button>
                </div>
            </form>
        </div>

        @include('riders.partials.vehicle-types-panel')
    </div>
</x-app-layout>