<x-app-layout>
    @php
        $totalUnread = \App\Models\Conversation::sum('unread_count');
        $hasFilters = trim((string) request('search')) !== '' || request('filter', 'all') !== 'all';

        $ago = function ($date) {
            if (!$date) return '';
            $diff = now()->diffInSeconds($date);
            if ($diff < 60) return 'Just now';
            if ($diff < 3600) return floor($diff / 60) . ' min ago';
            if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
            if ($diff < 604800) return floor($diff / 86400) . ' d ago';
            return $date->format('M j, Y');
        };

        $convoUrl = fn ($c) => request()->fullUrlWithQuery(['conversation' => $c->id]);
        $chipUrl = fn ($f) => request()->fullUrlWithQuery(['filter' => $f, 'page' => null]);
        $currentFilter = request('filter', 'all');
    @endphp

    <div class="h-[calc(100vh-7rem)] flex bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

        {{-- ================= Conversation List Panel ================= --}}
        <div class="w-full sm:w-80 lg:w-96 border-r border-gray-200 flex-col bg-gray-50/50 flex-shrink-0 {{ $activeConversation ? 'hidden sm:flex' : 'flex' }}">

            {{-- Header --}}
            <div class="px-4 py-4 border-b border-gray-200 bg-white">
                <div class="flex items-center justify-between mb-3">
                    <h1 class="text-lg font-bold text-gray-900">Messages</h1>
                    @if($totalUnread > 0)
                        <span class="bg-secondary text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ $totalUnread }}</span>
                    @endif
                </div>

                {{-- Search --}}
                <form method="GET" action="{{ route('messages.index') }}" class="relative mb-3">
                    @if(request('filter') && request('filter') !== 'all')
                        <input type="hidden" name="filter" value="{{ request('filter') }}">
                    @endif
                    @if(request('per_page'))
                        <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                    @endif
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search messages or people..." autocomplete="off"
                           class="w-full pl-9 pr-9 py-2 bg-gray-100 border-0 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-teal focus:bg-white transition">
                    @if(trim((string) request('search')) !== '')
                        <a href="{{ route('messages.index') }}" class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600" title="Clear search">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </a>
                    @endif
                </form>

                {{-- Filters --}}
                <div class="flex gap-1.5">
                    @foreach(['all' => 'All', 'rider' => 'Riders', 'seller' => 'Sellers'] as $key => $label)
                        <a href="{{ $chipUrl($key) }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors {{ $currentFilter === $key ? 'bg-teal text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Conversation List --}}
            <div class="flex-1 overflow-y-auto">
                @forelse($conversations as $conv)
                    <a href="{{ $convoUrl($conv) }}"
                       class="block px-4 py-3.5 border-b border-gray-100 transition-colors hover:bg-white {{ $activeConversation && $activeConversation->id === $conv->id ? 'bg-white shadow-sm' : '' }}">
                        <div class="flex items-start gap-3">
                            <div class="relative flex-shrink-0">
                                <div class="h-11 w-11 rounded-full flex items-center justify-center text-sm font-bold {{ $conv->participant_type === 'rider' ? 'bg-teal-light text-teal-dark' : 'bg-amber-100 text-amber-700' }}">
                                    {{ strtoupper(substr($conv->participant_name, 0, 1)) }}
                                </div>
                                @if($conv->participant_type === 'rider')
                                    <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-green-400"></span>
                                @endif
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="text-sm font-semibold {{ $conv->unread_count > 0 ? 'text-gray-900' : 'text-gray-700' }} truncate">{{ $conv->participant_name }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide flex-shrink-0 {{ $conv->participant_type === 'rider' ? 'bg-teal-light text-teal-dark' : 'bg-amber-50 text-amber-700' }}">
                                            {{ ucfirst($conv->participant_type) }}
                                        </span>
                                    </div>
                                    <span class="text-[11px] text-gray-400 whitespace-nowrap flex-shrink-0">{{ $ago($conv->last_message_at) }}</span>
                                </div>

                                @if($conv->order_id)
                                    <div class="text-xs text-teal font-medium mb-0.5">Order #{{ $conv->order_id }}</div>
                                @endif

                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs {{ $conv->unread_count > 0 ? 'text-gray-800 font-medium' : 'text-gray-500' }} truncate">
                                        {{ $conv->last_message_preview ?? 'No messages yet' }}
                                    </p>
                                    @if($conv->unread_count > 0)
                                        <span class="inline-flex items-center justify-center h-5 min-w-[20px] px-1.5 rounded-full bg-secondary text-white text-[10px] font-bold flex-shrink-0">{{ $conv->unread_count }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="flex flex-col items-center justify-center py-12 px-4 text-center">
                        <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                            <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-700">No messages found.</p>
                        @if($hasFilters)
                            <p class="text-xs text-gray-500 mt-1">Nothing matches your current search or filters.</p>
                            <a href="{{ route('messages.index') }}" class="mt-3 inline-flex items-center px-4 py-2 bg-white border border-gray-200 hover:border-teal hover:text-teal-dark rounded-xl text-sm font-semibold text-gray-600 transition">
                                Clear Search
                            </a>
                        @endif
                    </div>
                @endforelse
            </div>

            {{-- List footer: count + per-page --}}
            @if($conversations->count() > 0)
                <div class="px-4 py-2.5 border-t border-gray-200 bg-white flex items-center justify-between gap-2 flex-wrap">
                    <p class="text-[11px] text-gray-500">
                        Showing {{ $conversations->firstItem() }}–{{ $conversations->lastItem() }} of {{ $conversations->total() }} conversations
                    </p>
                    <x-per-page route="messages.index" align=""/>
                </div>
                <div class="border-t border-gray-100 bg-gray-50 px-2 py-1.5">
                    {{ $conversations->onEachSide(1)->links() }}
                </div>
            @endif
        </div>

        {{-- ================= Chat Panel ================= --}}
        @if($activeConversation)
            @php
                $isRider = $activeConversation->participant_type === 'rider';
            @endphp
            <div class="flex-1 flex flex-col min-w-0">

                {{-- Chat Header --}}
                <div class="px-5 py-3.5 border-b border-gray-200 bg-white flex items-center gap-3 flex-shrink-0">
                    <a href="{{ route('messages.index') }}" class="sm:hidden p-1.5 -ml-1.5 rounded-lg hover:bg-gray-100 text-gray-500" title="Back">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>

                    <div class="relative flex-shrink-0">
                        <div class="h-10 w-10 rounded-full flex items-center justify-center text-sm font-bold {{ $isRider ? 'bg-teal-light text-teal-dark' : 'bg-amber-100 text-amber-700' }}">
                            {{ strtoupper(substr($activeConversation->participant_name, 0, 1)) }}
                        </div>
                        @if($isRider)
                            <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-green-400"></span>
                        @endif
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-bold text-gray-900 truncate">{{ $activeConversation->participant_name }}</h2>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide {{ $isRider ? 'bg-teal-light text-teal-dark' : 'bg-amber-50 text-amber-700' }}">
                                {{ ucfirst($activeConversation->participant_type) }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500">
                            Order #{{ $activeConversation->order_id }}@if($activeConversation->delivery) · {{ $activeConversation->delivery->tracking_number }}@endif
                        </p>
                    </div>

                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 rounded-full {{ $isRider ? 'bg-green-400' : 'bg-gray-300' }}"></div>
                        <span class="text-xs text-gray-400">{{ $isRider ? 'Online' : 'Offline' }}</span>
                    </div>
                </div>

                {{-- Messages --}}
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-1"
                     style="background: linear-gradient(180deg, #F7F6F2 0%, #FFFFFF 100%);">

                    @forelse(array_reverse($activeConversation->messages->take(-200)->values()->all()) as $msg)
                        @php
                            $mine = $msg->sender_type === 'logistics';
                            $showDate = !isset($prevDate) || !$prevDate || !$msg->created_at->isSameDay($prevDate);
                            $prevDate = $msg->created_at;
                        @endphp

                        @if($showDate)
                            <div class="flex items-center justify-center py-3">
                                <span class="px-3 py-1 bg-gray-200/70 rounded-full text-[11px] font-medium text-gray-500">{{ $msg->created_at->isToday() ? 'Today' : ($msg->created_at->isYesterday() ? 'Yesterday' : $msg->created_at->format('F j, Y')) }}</span>
                            </div>
                        @endif

                        <div class="flex mb-2 {{ $mine ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[75%] sm:max-w-[60%]">
                                <div class="px-4 py-2.5 rounded-2xl text-sm leading-relaxed {{ $mine ? 'bg-teal text-white rounded-br-md' : 'bg-white border border-gray-200 text-gray-900 rounded-bl-md shadow-sm' }}">
                                    {{ $msg->body }}
                                </div>
                                <div class="flex items-center gap-1.5 mt-1 px-1 {{ $mine ? 'justify-end' : 'justify-start' }}">
                                    <span class="text-[10px] text-gray-400">{{ $msg->created_at->format('g:i A') }}</span>
                                    @if($mine)
                                        @if($msg->is_read)
                                            <svg class="h-3.5 w-3.5 text-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        @else
                                            <svg class="h-3.5 w-3.5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center h-full text-gray-400">
                            <p class="text-sm">No messages yet. Say hello!</p>
                        </div>
                    @endforelse
                </div>

                {{-- Message Input --}}
                <div class="px-5 py-4 border-t border-gray-200 bg-white flex-shrink-0">
                    <form method="POST" action="{{ route('messages.store') }}" class="flex items-end gap-3">
                        @csrf
                        <input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}">
                        <textarea name="body" required maxlength="2000" placeholder="Type a message..." rows="1"
                                  class="flex-1 px-4 py-2.5 bg-gray-100 border-0 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-teal focus:bg-white resize-none transition"
                                  style="min-height: 42px;"></textarea>
                        <button type="submit"
                                class="flex items-center justify-center h-[42px] w-[42px] rounded-xl bg-teal text-white hover:bg-teal-dark shadow-sm transition-all flex-shrink-0">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        @else
            {{-- Empty State --}}
            <div class="flex-1 hidden sm:flex flex-col items-center justify-center text-center px-6">
                <div class="w-16 h-16 rounded-2xl bg-teal-light flex items-center justify-center mb-4">
                    <svg class="h-8 w-8 text-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Select a conversation</h3>
                <p class="text-sm text-gray-500 max-w-sm">Choose a conversation from the list to start messaging with riders or sellers about deliveries.</p>
            </div>
        @endif
    </div>
</x-app-layout>
