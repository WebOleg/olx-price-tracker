@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
            <p class="mt-1 text-gray-600">Manage your OLX price tracking subscriptions</p>
        </div>
        
        <div class="mt-4 sm:mt-0">
            <a href="{{ route('subscribe') }}" 
               class="inline-flex items-center px-6 py-3 gradient-bg text-white font-medium rounded-lg hover:opacity-90 transition-opacity duration-200">
                <i class="fas fa-plus mr-2"></i>
                New Subscription
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg p-6 card-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <i class="fas fa-list text-blue-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Subscriptions</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $stats['total'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg p-6 card-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Verified</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $stats['verified'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg p-6 card-shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Active Tracking</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $stats['active'] }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Subscriptions List -->
    <div class="bg-white rounded-lg card-shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Your Subscriptions</h2>
        </div>

        @if($subscriptions->count() > 0)
            <div class="divide-y divide-gray-200">
                @foreach($subscriptions as $subscription)
                    <div class="p-6 hover:bg-gray-50 transition-colors duration-200">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                            <!-- Subscription Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0">
                                        @if($subscription->is_verified)
                                            @if($subscription->priceHistory->isNotEmpty() && $subscription->priceHistory->first()->is_available)
                                                <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                            @else
                                                <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                                            @endif
                                        @else
                                            <div class="w-3 h-3 bg-gray-400 rounded-full"></div>
                                        @endif
                                    </div>
                                    
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="text-sm font-medium text-gray-900 truncate">
                                                {{ $subscription->listing_title ?: 'OLX Listing' }}
                                            </h3>
                                            
                                            <!-- Status Badge -->
                                            @if($subscription->is_verified)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium status-verified">
                                                    <i class="fas fa-check mr-1"></i>
                                                    Verified
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium status-unverified">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    Pending
                                                </span>
                                            @endif
                                        </div>
                                        
                                        <p class="text-sm text-gray-600 mb-2">{{ $subscription->email }}</p>
                                        
                                        <div class="flex items-center space-x-4 text-xs text-gray-500">
                                            <span>
                                                <i class="fas fa-calendar mr-1"></i>
                                                Created {{ $subscription->created_at->diffForHumans() }}
                                            </span>
                                            
                                            @if($subscription->verified_at)
                                                <span>
                                                    <i class="fas fa-check mr-1"></i>
                                                    Verified {{ $subscription->verified_at->diffForHumans() }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Price Info -->
                            <div class="flex flex-col sm:flex-row sm:items-center space-y-2 sm:space-y-0 sm:space-x-6">
                                @if($subscription->priceHistory->isNotEmpty())
                                    @php
                                        $latestPrice = $subscription->priceHistory->first();
                                    @endphp
                                    
                                    <div class="text-center sm:text-left">
                                        <p class="text-sm text-gray-600">Current Price</p>
                                        <p class="text-lg font-bold text-gray-900">
                                            @if($latestPrice->is_available)
                                                ₴{{ number_format($latestPrice->price, 0, '.', ' ') }}
                                            @else
                                                <span class="text-red-600">Unavailable</span>
                                            @endif
                                        </p>
                                        
                                        @if($latestPrice->previous_price && $latestPrice->is_available)
                                            @php
                                                $change = $latestPrice->price - $latestPrice->previous_price;
                                                $changePercent = ($change / $latestPrice->previous_price) * 100;
                                            @endphp
                                            
                                            <p class="text-xs {{ $change > 0 ? 'price-up' : 'price-down' }}">
                                                <i class="fas fa-{{ $change > 0 ? 'arrow-up' : 'arrow-down' }} mr-1"></i>
                                                {{ $change > 0 ? '+' : '' }}₴{{ number_format(abs($change), 0, '.', ' ') }}
                                                ({{ $change > 0 ? '+' : '' }}{{ number_format($changePercent, 1) }}%)
                                            </p>
                                        @endif
                                        
                                        <p class="text-xs text-gray-500">
                                            Last checked {{ $latestPrice->checked_at->diffForHumans() }}
                                        </p>
                                    </div>
                                @else
                                    <div class="text-center sm:text-left">
                                        <p class="text-sm text-gray-600">Price</p>
                                        <p class="text-lg font-medium text-gray-500">Not checked yet</p>
                                    </div>
                                @endif

                                <!-- Actions -->
                                <div class="flex items-center space-x-2">
                                    <button onclick="copyToClipboard('{{ $subscription->listing_url }}')"
                                            class="p-2 text-gray-400 hover:text-gray-600 transition-colors duration-200"
                                            title="Copy URL">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    
                                    <a href="{{ $subscription->listing_url }}" 
                                       target="_blank"
                                       class="p-2 text-gray-400 hover:text-blue-600 transition-colors duration-200"
                                       title="Open listing">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                    
                                    @if(!$subscription->is_verified)
                                        <form method="POST" action="{{ route('subscriptions.resend-verification', $subscription) }}" class="inline">
                                            @csrf
                                            <button type="submit" 
                                                    class="p-2 text-gray-400 hover:text-green-600 transition-colors duration-200"
                                                    title="Resend verification email"
                                                    onclick="showLoading(this)">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </form>
                                    @endif
                                    
                                    <form method="POST" action="{{ route('subscriptions.destroy', $subscription) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" 
                                                class="p-2 text-gray-400 hover:text-red-600 transition-colors duration-200"
                                                title="Delete subscription"
                                                onclick="return confirmDelete('Are you sure you want to delete this subscription?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- URL Display -->
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <p class="text-xs text-gray-500 break-all">
                                <i class="fas fa-link mr-1"></i>
                                {{ $subscription->listing_url }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            @if($subscriptions->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $subscriptions->links() }}
                </div>
            @endif
        @else
            <!-- Empty State -->
            <div class="text-center py-12">
                <i class="fas fa-inbox text-gray-300 text-5xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No subscriptions yet</h3>
                <p class="text-gray-600 mb-6">Start tracking OLX listing prices by creating your first subscription.</p>
                <a href="{{ route('subscribe') }}" 
                   class="inline-flex items-center px-6 py-3 gradient-bg text-white font-medium rounded-lg hover:opacity-90 transition-opacity duration-200">
                    <i class="fas fa-plus mr-2"></i>
                    Create First Subscription
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
