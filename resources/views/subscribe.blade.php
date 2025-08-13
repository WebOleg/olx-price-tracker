@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Create New Subscription</h1>
        <p class="text-gray-600">Get notified when the price of an OLX listing changes</p>
    </div>

    <!-- Subscription Form -->
    <div class="bg-white rounded-lg card-shadow p-6 md:p-8">
        <form method="POST" action="{{ route('subscriptions.store') }}" class="space-y-6">
            @csrf
            
            <!-- Email Field -->
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-envelope mr-2 text-gray-400"></i>
                    Email Address
                </label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       value="{{ old('email') }}"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-colors duration-200 @error('email') border-red-300 @enderror"
                       placeholder="your@email.com"
                       required>
                
                @error('email')
                    <p class="mt-2 text-sm text-red-600">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        {{ $message }}
                    </p>
                @enderror
                
                <p class="mt-2 text-sm text-gray-500">
                    We'll send price change notifications to this email address.
                </p>
            </div>

            <!-- OLX URL Field -->
            <div>
                <label for="listing_url" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-link mr-2 text-gray-400"></i>
                    OLX Listing URL
                </label>
                <input type="url" 
                       id="listing_url" 
                       name="listing_url" 
                       value="{{ old('listing_url') }}"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-colors duration-200 @error('listing_url') border-red-300 @enderror"
                       placeholder="https://www.olx.ua/d/uk/obyavlenie/..."
                       required>
                
                @error('listing_url')
                    <p class="mt-2 text-sm text-red-600">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        {{ $message }}
                    </p>
                @enderror
                
                <p class="mt-2 text-sm text-gray-500">
                    Copy and paste the direct URL of the OLX listing you want to track.
                </p>
            </div>

            <!-- Submit Button -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-4 sm:space-y-0">
                <a href="{{ route('dashboard') }}" 
                   class="inline-flex items-center text-gray-600 hover:text-gray-800 transition-colors duration-200">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
                
                <button type="submit" 
                        class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3 gradient-bg text-white font-medium rounded-lg hover:opacity-90 transition-opacity duration-200">
                    <i class="fas fa-bell mr-2"></i>
                    Create Subscription
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
