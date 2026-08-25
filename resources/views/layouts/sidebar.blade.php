@php
    $pending = \App\Models\RiderApplication::where('status', 'pending')->count();
    $userName = Auth::user()->name ?? 'Admin';
    $userEmail = Auth::user()->email ?? '';
    $userInitial = substr($userName, 0, 1);
@endphp

{{-- Hover container: pointer over either sidebar keeps it open; leaving closes after 250ms --}}
<div x-on:mouseenter="openSidebarHover()" x-on:mouseleave="closeSidebarHover()">

{{-- Collapsed sidebar — always visible --}}
<aside class="fixed inset-y-0 left-0 z-40 w-[68px] bg-white border-r border-gray-200 flex flex-col items-center py-4 transition-all duration-200">
    {{-- Logo --}}
    <a href="{{ route('dashboard') }}" class="relative group mb-4">
        <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-9 w-9 rounded-xl object-cover">
        <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Home</span>
    </a>

    {{-- Navigation icons --}}
    <nav class="flex-1 flex flex-col items-center gap-1 w-full px-2">
        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('dashboard') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1" />
            </svg>
            @if($pending)
                <span class="absolute -top-0.5 -right-0.5 h-4 min-w-[16px] bg-secondary text-white text-[9px] font-bold rounded-full flex items-center justify-center px-1">{{ $pending }}</span>
            @endif
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Dashboard</span>
        </a>

        {{-- Deliveries --}}
        <a href="{{ route('deliveries.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('deliveries.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
            </svg>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Deliveries</span>
        </a>

        {{-- Riders --}}
        <a href="{{ route('riders.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('riders.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Riders</span>
        </a>

        {{-- Rider Applications --}}
        <a href="{{ route('rider-applications.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('rider-applications.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Applications</span>
        </a>

        <div class="w-7 border-t border-gray-100 my-2"></div>

        {{-- Tracking --}}
        <a href="{{ route('tracking.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('tracking.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Tracking</span>
        </a>

        {{-- Messages --}}
        <a href="{{ route('messages.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('messages.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            @php $unreadMsgs = \App\Models\Conversation::sum('unread_count'); @endphp
            @if($unreadMsgs)
                <span class="absolute -top-0.5 -right-0.5 h-4 min-w-[16px] bg-secondary text-white text-[9px] font-bold rounded-full flex items-center justify-center px-1">{{ $unreadMsgs }}</span>
            @endif
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Messages</span>
        </a>

        {{-- Settings --}}
        <a href="{{ route('settings.index') }}" class="relative group flex items-center justify-center w-11 h-11 rounded-xl transition-colors {{ request()->routeIs('settings.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Settings</span>
        </a>
    </nav>

    {{-- User avatar + Logout --}}
    <div class="flex flex-col items-center gap-1 mt-2">
        <div class="relative group">
            <div class="h-8 w-8 rounded-full bg-teal flex items-center justify-center text-white text-xs font-bold cursor-default">
                {{ $userInitial }}
            </div>
            <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">{{ $userName }}</span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="relative group flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                <span class="pointer-events-none absolute left-full ml-3 top-1/2 -translate-y-1/2 px-2.5 py-1 bg-gray-900 text-white text-xs font-medium rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50">Log Out</span>
            </button>
        </form>
    </div>
</aside>

{{-- Expanded sidebar — overlays the collapsed strip; rows and icons align 1:1 with the strip --}}
<aside x-show="sidebarVisible" x-cloak
       x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 -translate-x-4"
       x-transition:enter-end="opacity-100 translate-x-0"
       x-transition:leave="transition ease-in duration-150"
       x-transition:leave-start="opacity-100 translate-x-0"
       x-transition:leave-end="opacity-0 -translate-x-4"
       class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 flex flex-col shadow-xl">

    {{-- Header: 16px pad + 36px logo + 16px = 68px, same as the strip's logo block --}}
    <div class="flex items-center gap-2.5 h-[68px] px-4 border-b border-gray-100 flex-shrink-0">
        <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-9 w-9 rounded-xl object-cover flex-shrink-0">
        <span class="text-lg font-extrabold tracking-tight text-gray-900">LOGISTICS</span>
    </div>

    {{-- Navigation: same rhythm as strip — h-11 rows, gap-1, my-2 divider; icons centered at x=34px (pl-6 + 20px icon) --}}
    <nav class="flex-1 overflow-y-auto flex flex-col gap-1 px-3">
        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 h-11 pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1" />
            </svg>
            Dashboard
            @if($pending)
                <span class="ml-auto bg-teal text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ $pending }}</span>
            @endif
        </a>

        {{-- Deliveries --}}
        <div x-data="{ open: {{ request()->routeIs('deliveries.*') ? 'true' : 'false' }} }">
            <button @click="open = !open" class="flex items-center gap-3 h-11 w-full pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('deliveries.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                </svg>
                Deliveries
                <svg class="ml-auto h-4 w-4 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <div x-show="open" x-collapse class="mt-0.5 ml-3 pl-4 border-l border-gray-100 space-y-0.5">
                <a href="{{ route('deliveries.index') }}" class="block px-3 py-2 rounded-lg text-sm {{ request()->routeIs('deliveries.index') && !request('status') ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">All Deliveries</a>
                <a href="{{ route('deliveries.index', ['status' => 'waiting_for_rider']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'waiting_for_rider' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Waiting for Rider</a>
                <a href="{{ route('deliveries.index', ['status' => 'assigned']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'assigned' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Assigned</a>
                <a href="{{ route('deliveries.index', ['status' => 'picked_up']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'picked_up' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Picked Up</a>
                <a href="{{ route('deliveries.index', ['status' => 'out_for_delivery']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'out_for_delivery' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Out for Delivery</a>
                <a href="{{ route('deliveries.index', ['status' => 'delivered']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'delivered' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Delivered</a>
            </div>
        </div>

        {{-- Riders --}}
        <div x-data="{ open: {{ request()->routeIs('riders.*') ? 'true' : 'false' }} }">
            <button @click="open = !open" class="flex items-center gap-3 h-11 w-full pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('riders.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Riders
                <svg class="ml-auto h-4 w-4 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <div x-show="open" x-collapse class="mt-0.5 ml-3 pl-4 border-l border-gray-100 space-y-0.5">
                <a href="{{ route('riders.index') }}" class="block px-3 py-2 rounded-lg text-sm {{ request()->routeIs('riders.index') && !request('status') ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">All Riders</a>
                <a href="{{ route('riders.index', ['status' => 'available']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'available' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Available</a>
                <a href="{{ route('riders.index', ['status' => 'delivering']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'delivering' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Delivering</a>
            </div>
        </div>

        {{-- Rider Applications --}}
        <div x-data="{ open: {{ request()->routeIs('rider-applications.*') ? 'true' : 'false' }} }">
            <button @click="open = !open" class="flex items-center gap-3 h-11 w-full pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('rider-applications.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Rider Applications
                <svg class="ml-auto h-4 w-4 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <div x-show="open" x-collapse class="mt-0.5 ml-3 pl-4 border-l border-gray-100 space-y-0.5">
                <a href="{{ route('rider-applications.index') }}" class="block px-3 py-2 rounded-lg text-sm {{ request()->routeIs('rider-applications.index') && !request('status') ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">All Applications</a>
                <a href="{{ route('rider-applications.index', ['status' => 'pending']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'pending' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Pending</a>
                <a href="{{ route('rider-applications.index', ['status' => 'approved']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'approved' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Approved</a>
                <a href="{{ route('rider-applications.index', ['status' => 'rejected']) }}" class="block px-3 py-2 rounded-lg text-sm {{ request('status') == 'rejected' ? 'text-teal-dark font-semibold' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">Rejected</a>
            </div>
        </div>

        <div class="border-t border-gray-100 my-2"></div>

        {{-- Tracking --}}
        <a href="{{ route('tracking.index') }}" class="flex items-center gap-3 h-11 pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('tracking.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Tracking
        </a>

        {{-- Messages --}}
        <a href="{{ route('messages.index') }}" class="flex items-center gap-3 h-11 pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('messages.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            Messages
            @if($unreadMsgs)
                <span class="ml-auto bg-secondary text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ $unreadMsgs }}</span>
            @endif
        </a>

        {{-- Settings --}}
        <a href="{{ route('settings.index') }}" class="flex items-center gap-3 h-11 pl-6 pr-3 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('settings.*') ? 'bg-teal-light text-teal-dark' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Settings
        </a>
    </nav>

    {{-- User info + Logout --}}
    <div class="px-3 py-4 border-t border-gray-100 space-y-3">
        <div class="flex items-center gap-3 px-2">
            <div class="h-9 w-9 rounded-full bg-teal flex items-center justify-center text-white text-sm font-bold">
                {{ $userInitial }}
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate">{{ $userName }}</p>
                <p class="text-xs text-gray-400 truncate">{{ $userEmail }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 transition-colors">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                Log Out
            </button>
        </form>
    </div>
</aside>

</div>
