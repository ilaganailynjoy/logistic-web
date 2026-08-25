<x-guest-layout>
    <div x-data="{
        submitting: false,
        showPassword: false,
        email: '{{ old('email') }}',
        password: '',
        emailTouched: false,
        passwordTouched: false,
        get emailError() {
            if (!this.emailTouched) return '';
            if (!this.email) return 'Please enter your email address.';
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email)) return 'Please enter a valid email address.';
            return '';
        },
        get passwordError() {
            if (!this.passwordTouched) return '';
            if (!this.password) return 'Password is required.';
            return '';
        }
    }">
        <div class="text-center mb-6">
            <h2 class="text-xl font-bold text-gray-900">Welcome back 👋</h2>
            <p class="mt-1 text-sm text-gray-500">Enter your credentials to access the dashboard</p>
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        @if (session('error'))
            <div role="alert" class="mb-4 flex items-start gap-2.5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
                <svg class="h-4 w-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        {{-- Auth errors (invalid credentials, account status, throttle) --}}
        @if ($errors->has('email') && !session('status'))
            <div role="alert" aria-live="assertive"
                 class="mb-4 flex items-start gap-2.5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
                <svg class="h-4 w-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $errors->first('email') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}"
              x-on:submit="if (!email || !password || emailError || passwordError) { $event.preventDefault(); emailTouched = true; passwordTouched = true; } else { submitting = true }"
              novalidate>
            @csrf

            <!-- Email -->
            <div>
                <label for="email" class="block font-medium text-sm text-gray-700">Email</label>
                <input id="email"
                       type="email"
                       name="email"
                       x-model="email"
                       x-on:blur="emailTouched = true"
                       class="block mt-1 w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm @error('email') border-red-400 focus:border-red-500 focus:ring-red-500 @enderror"
                       value="{{ old('email') }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="you@company.com"
                       aria-describedby="@error('email') email-error @enderror"
                       aria-invalid="@error('email') true @enderror" />
                <p x-show="emailError" x-cloak x-text="emailError" class="mt-1.5 text-sm text-red-600"></p>
            </div>

            <!-- Password -->
            <div class="mt-4">
                <label for="password" class="block font-medium text-sm text-gray-700">Password</label>

                <div class="relative mt-1">
                    <input id="password"
                           x-bind:type="showPassword ? 'text' : 'password'"
                           name="password"
                           x-model="password"
                           x-on:blur="passwordTouched = true"
                           class="block w-full pr-12 border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm @error('password') border-red-400 focus:border-red-500 focus:ring-red-500 @enderror"
                           required
                           autocomplete="current-password"
                           placeholder="••••••••"
                           aria-describedby="@error('password') password-error @enderror"
                           aria-invalid="@error('password') true @enderror" />
                    <button type="button"
                            x-on:click="showPassword = !showPassword"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-teal rounded-lg"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :aria-pressed="showPassword"
                            tabindex="0">
                        {{-- Slashed eye = password hidden (default). Click to reveal. --}}
                        <svg x-show="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M3 3l18 18" />
                        </svg>
                        {{-- Open eye = password visible. Click to hide. --}}
                        <svg x-show="showPassword" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>

                <p x-show="passwordError" x-cloak x-text="passwordError" class="mt-1.5 text-sm text-red-600"></p>
                @error('password')
                    <p id="password-error" role="alert" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Remember me -->
            <div class="mt-5">
                <label for="remember_me" class="inline-flex items-center cursor-pointer select-none">
                    <input id="remember_me"
                           type="checkbox"
                           class="rounded border-gray-300 text-teal shadow-sm focus:ring-teal focus:ring-offset-1"
                           name="remember">
                    <span class="ms-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>

            <!-- Forgot password (right-aligned below password) -->
            @if (Route::has('password.request'))
                <div class="flex justify-end mt-3">
                    <a href="{{ route('password.request') }}"
                       class="text-sm font-medium text-teal hover:text-teal-dark underline underline-offset-2 rounded focus:outline-none focus:ring-2 focus:ring-teal focus:ring-offset-1">
                        Forgot your password?
                    </a>
                </div>
            @endif

            <!-- Sign In -->
            <div class="mt-6">
                <button type="submit"
                        :disabled="submitting"
                        :class="submitting ? 'opacity-70 cursor-not-allowed' : 'hover:bg-teal-dark'"
                        class="relative w-full bg-teal text-white rounded-xl py-3 font-semibold transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-teal focus:ring-offset-2 disabled:opacity-70">
                    <span class="inline-flex items-center justify-center gap-2">
                        <svg x-show="submitting" x-cloak class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span x-text="submitting ? 'Signing in...' : 'Sign In'">Sign In</span>
                    </span>
                </button>
            </div>
        </form>
    </div>
</x-guest-layout>
