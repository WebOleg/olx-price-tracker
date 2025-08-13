<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'OLX Price Tracker' }}</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card-shadow {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .card-shadow-hover {
            transition: box-shadow 0.2s ease-in-out;
        }
        
        .card-shadow-hover:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .status-verified {
            @apply bg-green-100 text-green-800 border-green-200;
        }
        
        .status-unverified {
            @apply bg-yellow-100 text-yellow-800 border-yellow-200;
        }
        
        .status-inactive {
            @apply bg-red-100 text-red-800 border-red-200;
        }
        
        .price-up {
            @apply text-red-600;
        }
        
        .price-down {
            @apply text-green-600;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
    </style>
    
    @stack('styles')
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Navigation -->
    <nav class="gradient-bg shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-chart-line text-white text-2xl"></i>
                    <h1 class="text-white text-xl font-bold">OLX Price Tracker</h1>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="{{ route('dashboard') }}" 
                       class="text-white hover:text-gray-200 transition-colors duration-200 flex items-center space-x-2">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                    
                    <a href="{{ route('subscribe') }}" 
                       class="bg-white text-purple-600 px-4 py-2 rounded-md hover:bg-gray-100 transition-colors duration-200 font-medium flex items-center space-x-2">
                        <i class="fas fa-plus"></i>
                        <span>Subscribe</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Flash Messages -->
    @if(session('success') || session('error') || session('warning'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            @if(session('success'))
                <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-4" id="flash-message">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-400 mr-3"></i>
                        <p class="text-green-700">{{ session('success') }}</p>
                        <button onclick="closeFlashMessage()" class="ml-auto text-green-400 hover:text-green-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4" id="flash-message">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                        <p class="text-red-700">{{ session('error') }}</p>
                        <button onclick="closeFlashMessage()" class="ml-auto text-red-400 hover:text-red-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            @endif

            @if(session('warning'))
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4" id="flash-message">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-400 mr-3"></i>
                        <p class="text-yellow-700">{{ session('warning') }}</p>
                        <button onclick="closeFlashMessage()" class="ml-auto text-yellow-400 hover:text-yellow-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center text-gray-500">
                <div class="flex items-center justify-center space-x-2 mb-4">
                    <i class="fas fa-chart-line text-purple-600"></i>
                    <span class="font-semibold">OLX Price Tracker</span>
                </div>
                
                <p class="text-sm">
                    Track OLX listing prices and get notified when they change.
                    <br>
                    Built with Laravel & love ❤️
                </p>
                
                <div class="mt-4 flex justify-center space-x-6 text-sm">
                    <a href="#" class="text-gray-400 hover:text-gray-500">
                        <i class="fab fa-github mr-1"></i>
                        GitHub
                    </a>
                    <span class="text-gray-300">•</span>
                    <span class="text-gray-400">© {{ date('Y') }} Price Tracker</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // Close flash messages
        function closeFlashMessage() {
            const flashMessage = document.getElementById('flash-message');
            if (flashMessage) {
                flashMessage.style.opacity = '0';
                flashMessage.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    flashMessage.remove();
                }, 300);
            }
        }

        // Auto-close flash messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessage = document.getElementById('flash-message');
            if (flashMessage) {
                setTimeout(closeFlashMessage, 5000);
            }
        });

        // Loading state for forms
        function showLoading(button) {
            if (button) {
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                button.disabled = true;
                button.classList.add('loading');
            }
        }

        // Form validation helpers
        function validateOlxUrl(url) {
            const olxPattern = /^https:\/\/(www\.)?olx\.ua\/d\/[\w\-]+\/[\d]+$/;
            return olxPattern.test(url);
        }

        function validateEmail(email) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailPattern.test(email);
        }

        // Copy URL to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('URL copied to clipboard!', 'success');
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
            });
        }

        // Toast notifications
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 p-4 rounded-md shadow-lg z-50 ${
                type === 'success' ? 'bg-green-500' : 
                type === 'error' ? 'bg-red-500' : 'bg-blue-500'
            } text-white`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}-circle mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Confirm deletion
        function confirmDelete(message = 'Are you sure you want to delete this subscription?') {
            return confirm(message);
        }
    </script>

    @stack('scripts')
</body>
</html>
