<x-guest-layout>
    <div x-data="{ submitting: false, email: '' }">
        <div class="text-center mb-6">
            <h2 class="text-xl font-bold text-gray-900">Forgot your password?</h2>
            <p class="mt-1 text-sm text-gray-500">No problem. Enter your email and we'll send you a reset link.</p>
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        @if ($errors->has('email'))
            <div id="email-error" role="alert" aria-live="assertive"
                 class="mb-4 flex items-start gap-2.5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
                <svg class="h-4 w-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $errors->first('email') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}"
              x-on:submit="submitting = true"
              novalidate>
            @csrf

            <div>
                <label for="email" class="block font-medium text-sm text-gray-700">Email</label>
                <input id="email"
                       type="email"
                       name="email"
                       x-model="email"
                       class="block mt-1 w-full border-gray-300 focus:border-teal focus:ring-teal rounded-xl shadow-sm @error('email') border-red-400 focus:border-red-500 focus:ring-red-500 @enderror"
                       value="{{ old('email') }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="you@company.com"
                       aria-describedby="email-error"
                       aria-invalid="@error('email') true @enderror" />
            </div>

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
                        <span x-text="submitting ? 'Sending...' : 'Email Password Reset Link'">Email Password Reset Link</span>
                    </span>
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            <a href="{{ route('login') }}"
               class="font-semibold text-teal-dark hover:text-teal underline underline-offset-2 rounded focus:outline-none focus:ring-2 focus:ring-teal focus:ring-offset-1">
                Back to Sign In
            </a>
        </p>
    </div>
</x-guest-layout>
