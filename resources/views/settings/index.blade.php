<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-1">
            <span>Home</span>
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-900 font-medium">Settings</span>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 leading-tight">Settings</h2>
        <p class="mt-1 text-sm text-gray-500">Manage your account, notifications, and delivery preferences</p>
    </x-slot>

    @php
        $notif = fn (string $key) => $settings->notificationEnabled($key) ? 'checked' : '';
        $delivery = $settings->delivery ?? [];
    @endphp

    <div class="max-w-4xl mx-auto space-y-6">

        {{-- ===================== Profile / Account ===================== --}}
        <section x-data="{ editing: false, pwOpen: false }" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Profile Information</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Your personal account details</p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-teal-light text-teal-dark text-xs font-semibold">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Logistics Staff
                </span>
            </div>

            <div class="p-6">
                <div class="flex items-center gap-5 pb-6 border-b border-gray-100">
                    @if($settings->photo_path && file_exists(public_path($settings->photo_path)))
                        <img src="{{ asset($settings->photo_path) }}" alt="Avatar" class="h-20 w-20 rounded-full object-cover ring-4 ring-teal-light">
                    @else
                        <div class="h-20 w-20 rounded-full bg-teal flex items-center justify-center text-white text-2xl font-bold ring-4 ring-teal-light">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                    @endif
                    <form method="POST" action="{{ route('settings.update-photo') }}" enctype="multipart/form-data">
                        @csrf
                        <label class="cursor-pointer inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors">
                            Change Photo
                            <input type="file" name="photo" accept=".jpg,.jpeg,.png" class="sr-only" onchange="this.closest('form').submit()">
                        </label>
                        <p class="text-xs text-gray-400 mt-1.5">JPG or PNG, max 2MB</p>
                    </form>
                </div>

                <form method="POST" action="{{ route('settings.update-profile') }}" class="pt-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                        <div class="sm:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" :disabled="!editing"
                                   class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm disabled:bg-gray-50 disabled:text-gray-500 read-only:bg-gray-50"
                                   readonly x-bind:readonly="!editing" required />
                            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}"
                                   class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm disabled:bg-gray-50 disabled:text-gray-500"
                                   x-bind:readonly="!editing" required />
                            @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" placeholder="09XXXXXXXXX"
                                   class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm disabled:bg-gray-50 disabled:text-gray-500"
                                   x-bind:readonly="!editing" />
                            @error('phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                            <div class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-500">
                                Logistics Staff
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 pt-6 border-t border-gray-100 flex flex-wrap items-center gap-3">
                        <button type="button" x-show="!editing" @click="editing = true"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Edit Profile
                        </button>
                        <button type="submit" x-show="editing" x-cloak
                                class="inline-flex items-center px-5 py-2.5 bg-teal hover:bg-teal-dark text-white text-sm font-semibold rounded-xl transition-colors duration-200">
                            Save Changes
                        </button>
                        <button type="button" x-show="editing" x-cloak @click="editing = false"
                                class="inline-flex items-center px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors">
                            Cancel
                        </button>
                        <button type="button" @click="pwOpen = true"
                                class="ml-auto inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            Change Password
                        </button>
                    </div>
                </form>
            </div>

            {{-- Change Password Modal --}}
            <div x-show="pwOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
                <div class="absolute inset-0 bg-black/40" @click="pwOpen = false"></div>
                <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h4 class="font-bold text-gray-900">Change Password</h4>
                        <button @click="pwOpen = false" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <form method="POST" action="{{ route('settings.update-password') }}">
                        @csrf
                        @method('PUT')
                        <div class="px-6 py-5 space-y-4">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                                <input id="current_password" name="current_password" type="password" required
                                       class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm" />
                                @error('current_password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                                <input id="password" name="password" type="password" required minlength="8"
                                       class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm" />
                                @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="password-confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                                <input id="password-confirm" name="password_confirmation" type="password" required minlength="8"
                                       class="block w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm" />
                            </div>
                        </div>
                        <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                            <button type="button" @click="pwOpen = false"
                                    class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-5 py-2.5 bg-teal hover:bg-teal-dark text-white text-sm font-semibold rounded-xl transition-colors duration-200">
                                Save Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        {{-- ===================== Notification Settings ===================== --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">Notification Settings</h3>
                <p class="text-sm text-gray-500 mt-0.5">Choose which in-system notifications you receive</p>
            </div>

            <form method="POST" action="{{ route('settings.update-notifications') }}">
                @csrf
                @method('PUT')

                <ul class="divide-y divide-gray-100">
                    @foreach([
                        'rider_applications' => ['New Rider Applications', 'Get notified when a rider submits an application'],
                        'delivery_requests' => ['New Delivery Requests', 'Get notified when a new delivery is created'],
                        'failed_deliveries' => ['Failed Deliveries', 'Get notified when a delivery attempt fails'],
                        'failed_pickups' => ['Failed Pickups', 'Get notified when a rider cannot pick up a package'],
                        'rider_status_updates' => ['Rider Status Updates', 'Get notified when riders go online, offline, or deliver'],
                        'delivery_completed' => ['Delivery Completed', 'Get notified when a delivery is successfully completed'],
                        'new_messages' => ['New Messages', 'Get notified about new messages from riders and sellers'],
                    ] as $key => [$title, $desc])
                        <li class="px-6 py-4 flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $title }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $desc }}</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                                <input type="hidden" name="{{ $key }}" value="0">
                                <input type="checkbox" name="{{ $key }}" value="1" {{ $notif($key) }} class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-teal after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:shadow after:transition-all peer-checked:after:translate-x-5"></div>
                            </label>
                        </li>
                    @endforeach

                    <li class="px-6 py-4 flex items-center justify-between gap-4 bg-surface-soft/50">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Email Notifications</p>
                            <p class="text-xs text-gray-500 mt-0.5">Also receive email copies of your notifications</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="hidden" name="email_notifications" value="0">
                            <input type="checkbox" name="email_notifications" value="1" {{ $settings->email_notifications ? 'checked' : '' }} class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-teal after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:shadow after:transition-all peer-checked:after:translate-x-5"></div>
                        </label>
                    </li>
                </ul>

                <div class="px-6 py-4 border-t border-gray-100 flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-6 py-2.5 bg-teal hover:bg-teal-dark text-white text-sm font-semibold rounded-xl transition-colors duration-200">
                        Save Changes
                    </button>
                </div>
            </form>
        </section>

        {{-- ===================== Delivery Preferences ===================== --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">Delivery Preferences</h3>
                <p class="text-sm text-gray-500 mt-0.5">Operational rules applied to delivery workflows</p>
            </div>

            <form method="POST" action="{{ route('settings.update-delivery') }}">
                @csrf
                @method('PUT')

                <ul class="divide-y divide-gray-100">
                    <li class="px-6 py-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Require Proof of Delivery</p>
                            <p class="text-xs text-gray-500 mt-0.5">Riders must upload photo or signature proof before completing a delivery</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="hidden" name="require_proof" value="0">
                            <input type="checkbox" name="require_proof" value="1" {{ ($delivery['require_proof'] ?? true) ? 'checked' : '' }} class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-teal after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:shadow after:transition-all peer-checked:after:translate-x-5"></div>
                        </label>
                    </li>

                    <li class="px-6 py-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Maximum Delivery Attempts</p>
                            <p class="text-xs text-gray-500 mt-0.5">Number of attempts before a delivery is marked as failed</p>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            @foreach([1, 2, 3] as $attempt)
                                <label class="cursor-pointer">
                                    <input type="radio" name="max_attempts" value="{{ $attempt }}"
                                           class="peer sr-only" {{ (int) ($delivery['max_attempts'] ?? 2) === $attempt ? 'checked' : '' }}>
                                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl text-sm font-bold bg-gray-100 text-gray-600 transition-colors peer-checked:bg-teal peer-checked:text-white hover:bg-gray-200 peer-checked:hover:bg-teal-dark">
                                        {{ $attempt }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </li>

                    <li class="px-6 py-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Allow Rider Reassignment</p>
                            <p class="text-xs text-gray-500 mt-0.5">Permit reassigning an unaccepted delivery to another available rider</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="hidden" name="allow_reassignment" value="0">
                            <input type="checkbox" name="allow_reassignment" value="1" {{ ($delivery['allow_reassignment'] ?? true) ? 'checked' : '' }} class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-teal after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:shadow after:transition-all peer-checked:after:translate-x-5"></div>
                        </label>
                    </li>
                </ul>

                <div class="px-6 py-4 border-t border-gray-100 flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-6 py-2.5 bg-teal hover:bg-teal-dark text-white text-sm font-semibold rounded-xl transition-colors duration-200">
                        Save Changes
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>

