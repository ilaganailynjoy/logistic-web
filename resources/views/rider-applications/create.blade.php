<x-guest-layout>
    <div>
        <h1 class="text-xl font-bold text-gray-900">Apply to Become a Rider</h1>
        <p class="mt-1 text-sm text-gray-500">Fill out the form below and upload your supporting documents.</p>

        @if (session('success'))
            <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded-xl text-green-700 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('rider-applications.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">Full Name</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('name') border-red-500 @enderror">
                @error('name')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">Phone Number</label>
                <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" required placeholder="09XXXXXXXXX"
                       pattern="(\+?639|09)\d{9}" title="Format: 09XXXXXXXXX or +639XXXXXXXXX"
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('phone') border-red-500 @enderror">
                <p class="mt-1 text-xs text-gray-400">Philippine mobile number — 09XXXXXXXXX or +639XXXXXXXXX</p>
                @error('phone')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="address" class="block text-sm font-medium text-gray-700 mb-1.5">Address</label>
                <textarea name="address" id="address" rows="2" required maxlength="500"
                          class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('address') border-red-500 @enderror">{{ old('address') }}</textarea>
                @error('address')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="vehicle_type" class="block text-sm font-medium text-gray-700 mb-1.5">Vehicle Type</label>
                @php $caps = \App\Models\LogisticsSetting::vehicleCapacities(); @endphp
                <select name="vehicle_type" id="vehicle_type" required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('vehicle_type') border-red-500 @enderror">
                    <option value="">Select vehicle type</option>
                    @foreach($vehicleTypes as $name => $label)
                        <option value="{{ $name }}" {{ old('vehicle_type') === $name ? 'selected' : '' }}>{{ $label }} (up to {{ $caps[$name] ?? '—' }} kg)</option>
                    @endforeach
                </select>
                @error('vehicle_type')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="license_plate" class="block text-sm font-medium text-gray-700 mb-1.5">Vehicle Plate Number</label>
                <input type="text" name="license_plate" id="license_plate" value="{{ old('license_plate') }}" required maxlength="20"
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('license_plate') border-red-500 @enderror">
                @error('license_plate')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="license_number" class="block text-sm font-medium text-gray-700 mb-1.5">Driver's License Number</label>
                <input type="text" name="license_number" id="license_number" value="{{ old('license_number') }}" required maxlength="100"
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('license_number') border-red-500 @enderror">
                @error('license_number')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="vehicle_registration" class="block text-sm font-medium text-gray-700 mb-1.5">Vehicle Registration (OR/CR) Number</label>
                <input type="text" name="vehicle_registration" id="vehicle_registration" value="{{ old('vehicle_registration') }}" required maxlength="100"
                       class="w-full rounded-xl border-gray-300 shadow-sm focus:border-teal focus:ring-teal @error('vehicle_registration') border-red-500 @enderror">
                @error('vehicle_registration')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-2 border-t border-gray-100">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Supporting Documents</p>
                <p class="text-xs text-gray-400 mb-3">Accepted: JPG, PNG, WEBP, PDF, DOC, DOCX — max 5 MB each. Documents marked * are required.</p>

                @foreach(['valid_id' => ['Valid ID', true], 'drivers_license' => ["Driver's License Photo", true], 'vehicle_registration' => ['Vehicle Registration Document', true], 'proof_of_address' => ['Proof of Address', false], 'other' => ['Other Supporting Document', false]] as $slot => [$label, $required])
                    <div class="mb-4">
                        <label for="documents_{{ $slot }}" class="block text-sm font-medium text-gray-700 mb-1.5">
                            {{ $label }} @if($required)<span class="text-red-500">*</span>@endif
                        </label>
                        <input type="file" name="documents[{{ $slot }}]" id="documents_{{ $slot }}" @if($required) required @endif
                               accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
                               class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-teal-light file:text-teal-dark hover:file:bg-teal-light/70">
                        @error('documents.' . $slot)
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <button type="submit" class="w-full bg-teal hover:bg-teal-dark text-white rounded-xl py-3 font-semibold transition">
                Submit Application
            </button>
        </form>
    </div>
</x-guest-layout>
