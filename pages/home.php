<?php
if (isLoggedIn()) {
    redirectTo('dashboard');
}
?>

<div class="min-h-screen bg-gradient-to-br from-gray-50 to-white dark:from-ayuni-dark dark:to-gray-900">
    <!-- Navigation -->
    <nav class="bg-white/80 dark:bg-ayuni-dark/80 backdrop-blur-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <h1 class="text-2xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent">
                        Ayuni
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="?page=login" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
                        Sign In
                    </a>
                    <a href="?page=register" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white px-4 py-2 rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 transform hover:scale-105">
                        Join Beta
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24">
        <div class="text-center">
            <h1 class="text-5xl md:text-6xl font-bold text-gray-900 dark:text-white mb-6">
                The Future of
                <span class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent">
                    Artificial Emotional Intelligence
                </span>
            </h1>
            <p class="text-xl text-gray-600 dark:text-gray-400 mb-8 max-w-3xl mx-auto">
                Experience meaningful connections with AI companions that understand, learn, and respond with genuine emotional intelligence.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="?page=register" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white px-8 py-4 rounded-xl font-semibold text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 transform hover:scale-105 shadow-lg">
                    <i class="fas fa-rocket mr-2"></i>
                    Join Beta Program
                </a>
                <a href="?page=login" class="bg-white dark:bg-gray-800 text-gray-900 dark:text-white border border-gray-300 dark:border-gray-600 px-8 py-4 rounded-xl font-semibold text-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-all duration-200">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Sign In
                </a>
            </div>
        </div>

        <!-- Features Grid -->
        <div class="mt-24 grid md:grid-cols-3 gap-8">
            <div class="text-center p-8 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700">
                <div class="w-16 h-16 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-brain text-2xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Emotional Understanding</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Advanced AI that recognizes and responds to emotional nuances in conversation.
                </p>
            </div>
            
            <div class="text-center p-8 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700">
                <div class="w-16 h-16 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-user-friends text-2xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Personalized Companions</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Create unique AI companions with distinct personalities and characteristics.
                </p>
            </div>
            
            <div class="text-center p-8 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700">
                <div class="w-16 h-16 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-shield-alt text-2xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Privacy First</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Your conversations and data are protected with enterprise-grade security.
                </p>
            </div>
        </div>

        <!-- Beta Notice -->
        <div class="mt-16 text-center">
            <div class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-ayuni-aqua/10 to-ayuni-blue/10 border border-ayuni-aqua/20 dark:border-ayuni-blue/20 rounded-full">
                <i class="fas fa-flask text-ayuni-blue mr-2"></i>
                <span class="text-ayuni-blue font-medium">Currently in Beta - Limited Access Available</span>
            </div>
        </div>
    </div>
</div>