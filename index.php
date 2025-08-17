<?php
// Configure session for VERY long lifetime (30 days) - MUST be before session_start()
ini_set('session.gc_maxlifetime', 2592000); // 30 days
ini_set('session.cookie_lifetime', 2592000); // 30 days
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Force session storage method that doesn't rely on server cleanup
ini_set('session.save_handler', 'files');

// Set session cookie parameters BEFORE starting session
session_set_cookie_params([
    'lifetime' => 2592000, // 30 days
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'  // Lax is better for login flows
]);

session_start();

include_once 'config/database.php';
include_once 'includes/functions.php';
include_once 'includes/router.php';
include_once 'includes/anthropic_api.php';
include_once 'includes/template_engine.php';
include_once 'includes/admin_layout.php';

// Handle special actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        session_destroy();
        header("Location: /");
        exit;
    }
}

$allowed_pages = ['home', 'onboarding', 'create-aei', 'choose-avatar', 'chat', 'dashboard', 'profile', 'admin', 'admin-api', 'admin-prompts', 'admin-users', 'admin-beta', 'admin-emotions', 'admin-social', 'admin-proactive', 'admin-feedback', 'admin-logs', 'admin-decay', 'admin-migration', 'admin-replicate', 'admin-avatar-batch', 'memory-setup'];
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

// Execute page logic FIRST (before any HTML output) to allow redirects
ob_start(); // Start output buffering to capture page content
include "pages/{$page}.php";
$page_content = ob_get_clean(); // Get the page content

$page_title = match($page) {
    'onboarding' => 'Welcome to Ayuni',
    'create-aei' => 'Create Your AEI',
    'choose-avatar' => 'Choose Avatar',
    'chat' => 'Chat with AEI',
    'dashboard' => 'Dashboard',
    'profile' => 'Profile Settings',
    'admin' => 'Admin Panel',
    'admin-api' => 'Admin - API Settings',
    'admin-prompts' => 'Admin - System Prompts',
    'admin-users' => 'Admin - User Management',
    'admin-beta' => 'Admin - Beta Codes',
    'admin-emotions' => 'Admin - Emotion Monitoring',
    'admin-social' => 'Admin - Social System',
    'admin-proactive' => 'Admin - Proactive Messaging',
    'admin-feedback' => 'Admin - User Feedback',
    'admin-logs' => 'Admin - Error Logs',
    'admin-decay' => 'Admin - Emotional Decay',
    'admin-migration' => 'Admin - Migration Tools',
    'admin-replicate' => 'Admin - Replicate AI',
    'admin-avatar-batch' => 'Admin - Avatar Batch Generation',
    'memory-setup' => 'Admin - Memory System Setup',
    default => 'Ayuni Beta'
};
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#39D2DF">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Ayuni">
    <meta name="application-name" content="Ayuni">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/assets/favicon.ico">
    
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'ayuni': {
                            'aqua': '#39D2DF',
                            'blue': '#546BEC', 
                            'dark': '#10142B',
                            'white': '#FFFFFF'
                        }
                    },
                    fontFamily: {
                        'sans': ['DM Sans', 'system-ui', '-apple-system', 'sans-serif'],
                        'display': ['Outfit', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Outfit:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        // Theme detection and management
        function getStoredTheme() {
            return localStorage.getItem('theme');
        }
        
        function setStoredTheme(theme) {
            localStorage.setItem('theme', theme);
        }
        
        function getSystemTheme() {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        
        function updateTheme() {
            const storedTheme = getStoredTheme();
            const theme = storedTheme || getSystemTheme();
            
            // Apply theme to document
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
            
            // Update toggle button icons
            updateThemeIcons(theme);
        }
        
        function updateThemeIcons(theme) {
            const sunIcon = document.querySelector('#theme-toggle .sun-icon');
            const moonIcon = document.querySelector('#theme-toggle .moon-icon');
            
            if (sunIcon && moonIcon) {
                if (theme === 'dark') {
                    // In dark mode, show sun icon (to switch to light)
                    sunIcon.classList.remove('hidden');
                    moonIcon.classList.add('hidden');
                } else {
                    // In light mode, show moon icon (to switch to dark)
                    sunIcon.classList.add('hidden');
                    moonIcon.classList.remove('hidden');
                }
            }
        }
        
        function toggleTheme() {
            const currentTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setStoredTheme(newTheme);
            updateTheme();
        }
        
        // Initialize theme immediately
        (function() {
            const storedTheme = localStorage.getItem('theme');
            const theme = storedTheme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
        
        // Listen for system theme changes if no stored preference
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (!getStoredTheme()) {
                updateTheme();
            }
        });
        
        // Update theme icons after page load
        document.addEventListener('DOMContentLoaded', function() {
            updateTheme();
        });

        // Pull-to-refresh functionality for PWA
        document.addEventListener('DOMContentLoaded', function() {
            let startY = 0;
            let isRefreshing = false;
            let pullDistance = 0;
            const maxPullDistance = 220;
            const refreshThreshold = 180; // Increased from 120 to 180 for later trigger
            let pullStarted = false;

            function createRefreshIndicator() {
                // Remove existing indicator first
                const existing = document.getElementById('refresh-indicator');
                if (existing) existing.remove();
                
                const indicator = document.createElement('div');
                indicator.id = 'refresh-indicator';
                indicator.className = 'fixed top-0 left-0 right-0 h-16 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue flex items-center justify-center text-white font-semibold z-50 transform -translate-y-full transition-transform duration-300';
                indicator.innerHTML = '<i class="fas fa-arrow-down mr-2"></i> Pull to refresh';
                document.body.appendChild(indicator);
                return indicator;
            }

            function updateRefreshIndicator(distance) {
                const indicator = document.getElementById('refresh-indicator');
                if (!indicator) return;

                const progress = Math.min(distance / refreshThreshold, 1);
                const translateY = Math.min(distance - 64, 0);
                
                indicator.style.transform = `translateY(${translateY}px)`;
                
                if (progress >= 1) {
                    indicator.innerHTML = '<i class="fas fa-sync-alt mr-2 animate-spin"></i> Release to refresh';
                    indicator.style.background = 'linear-gradient(to right, #10b981, #059669)';
                } else {
                    indicator.innerHTML = '<i class="fas fa-arrow-down mr-2"></i> Pull to refresh';
                    indicator.style.background = 'linear-gradient(to right, #39D2DF, #546BEC)';
                }
            }

            function hideRefreshIndicator() {
                const indicator = document.getElementById('refresh-indicator');
                if (indicator) {
                    indicator.style.transform = 'translateY(-100%)';
                    setTimeout(() => indicator.remove(), 300);
                }
            }

            function handleRefresh() {
                if (isRefreshing) return;
                isRefreshing = true;
                
                const indicator = document.getElementById('refresh-indicator');
                if (indicator) {
                    indicator.innerHTML = '<i class="fas fa-sync-alt mr-2 animate-spin"></i> Refreshing...';
                    indicator.style.transform = 'translateY(0)';
                }

                setTimeout(() => window.location.reload(), 500);
            }

            // Touch events for mobile
            document.addEventListener('touchstart', function(e) {
                // Check if we're touching within the messages container in chat
                const isInChat = window.location.pathname.includes('/chat/');
                const chatContainer = document.getElementById('messages-container');
                
                if (isInChat && chatContainer) {
                    // Only allow pull-to-refresh if chat container is at the very top
                    const isChatAtTop = chatContainer.scrollTop <= 5;
                    if (isChatAtTop && !isRefreshing) {
                        startY = e.touches[0].clientY;
                        pullStarted = true;
                        console.log('Pull started in chat at top');
                    }
                } else {
                    // For other pages, check if window is at top
                    const isAtTop = window.scrollY <= 5;
                    if (isAtTop && !isRefreshing) {
                        startY = e.touches[0].clientY;
                        pullStarted = true;
                        console.log('Pull started at page top');
                    }
                }
            }, { passive: true });

            document.addEventListener('touchmove', function(e) {
                if (!pullStarted || isRefreshing) return;
                
                // Check if we should continue pull-to-refresh
                const isInChat = window.location.pathname.includes('/chat/');
                const chatContainer = document.getElementById('messages-container');
                
                if (isInChat && chatContainer) {
                    // In chat: only continue if chat container is still at top
                    if (chatContainer.scrollTop > 5) {
                        pullStarted = false;
                        hideRefreshIndicator();
                        return;
                    }
                } else {
                    // Other pages: only continue if window is still at top
                    if (window.scrollY > 5) {
                        pullStarted = false;
                        hideRefreshIndicator();
                        return;
                    }
                }

                const currentY = e.touches[0].clientY;
                pullDistance = Math.max(0, currentY - startY);
                
                console.log('Pull distance:', pullDistance);
                
                if (pullDistance > 15) { // Increased from 5 to 15
                    if (!document.getElementById('refresh-indicator')) {
                        createRefreshIndicator();
                    }
                    e.preventDefault();
                    updateRefreshIndicator(pullDistance);
                }
            }, { passive: false });

            document.addEventListener('touchend', function(e) {
                console.log('Touch end, distance:', pullDistance);
                if (!pullStarted || isRefreshing) return;

                if (pullDistance >= refreshThreshold) {
                    handleRefresh();
                } else {
                    hideRefreshIndicator();
                }

                pullStarted = false;
                startY = 0;
                pullDistance = 0;
            }, { passive: true });
        });
    </script>
</head>
<?php
// Session maintenance (after session is active)
if (session_status() === PHP_SESSION_ACTIVE) {
    // Regenerate session ID periodically for security (but not too often to allow multi-device)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 604800) { // 7 days instead of 24 hours
        // Only regenerate if user is logged in and it's been a week
        if (isset($_SESSION['user_id'])) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}
?>
<body class="min-h-screen bg-white dark:bg-ayuni-dark text-gray-900 dark:text-white font-sans antialiased">
    <?php echo $page_content; ?>
    
    <!-- PWA Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('Ayuni PWA: ServiceWorker registered successfully', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('Ayuni PWA: ServiceWorker registration failed', error);
                    });
            });
        }

        // PWA Install Prompt - Smart Detection
        let deferredPrompt;
        
        function isMobile() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }
        
        function isPWA() {
            return (window.matchMedia('(display-mode: standalone)').matches) || 
                   (window.navigator.standalone === true) || 
                   document.referrer.includes('android-app://');
        }
        
        function hasBeenDismissed() {
            return localStorage.getItem('ayuni-pwa-dismissed') === 'true';
        }
        
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('Ayuni PWA: Install prompt triggered');
            e.preventDefault();
            deferredPrompt = e;
            
            // Only show banner on mobile, not in PWA, and not if dismissed
            if (isMobile() && !isPWA() && !hasBeenDismissed()) {
                showInstallBanner();
            }
        });
        
        // Also check on page load for mobile users
        window.addEventListener('load', () => {
            setTimeout(() => {
                if (isMobile() && !isPWA() && !hasBeenDismissed() && !deferredPrompt) {
                    showFallbackInstallBanner();
                }
            }, 3000); // Show after 3 seconds if no install prompt
        });
        
        function showInstallBanner() {
            const installBanner = document.createElement('div');
            installBanner.id = 'ayuni-install-banner';
            installBanner.innerHTML = `
                <div style="position: fixed; bottom: 20px; left: 20px; right: 20px; background: linear-gradient(135deg, #39D2DF, #546BEC); color: white; padding: 16px 20px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); z-index: 9999; font-family: Inter, sans-serif; animation: slideUp 0.3s ease-out;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                            <span style="font-size: 24px;">🤖</span>
                            <div>
                                <div style="font-weight: 600; font-size: 16px; margin-bottom: 2px;">Install Ayuni App</div>
                                <div style="font-size: 13px; opacity: 0.9;">Get the full app experience</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button onclick="installPWA()" style="background: white; color: #39D2DF; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.1s;">📱 Install App</button>
                            <button onclick="dismissInstallBanner()" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: white; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 13px;">Later</button>
                        </div>
                    </div>
                </div>
                <style>
                    @keyframes slideUp {
                        from { transform: translateY(100%); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                </style>
            `;
            document.body.appendChild(installBanner);
        }
        
        function showFallbackInstallBanner() {
            // Fallback banner for browsers that don't trigger beforeinstallprompt
            const installBanner = document.createElement('div');
            installBanner.id = 'ayuni-install-banner';
            installBanner.innerHTML = `
                <div style="position: fixed; bottom: 20px; left: 20px; right: 20px; background: linear-gradient(135deg, #39D2DF, #546BEC); color: white; padding: 16px 20px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); z-index: 9999; font-family: Inter, sans-serif; animation: slideUp 0.3s ease-out;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                            <span style="font-size: 24px;">📱</span>
                            <div>
                                <div style="font-weight: 600; font-size: 16px; margin-bottom: 2px;">Add to Home Screen</div>
                                <div style="font-size: 13px; opacity: 0.9;">Tap Share → Add to Home Screen</div>
                            </div>
                        </div>
                        <button onclick="dismissInstallBanner()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer; padding: 8px; opacity: 0.7;">×</button>
                    </div>
                </div>
                <style>
                    @keyframes slideUp {
                        from { transform: translateY(100%); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                </style>
            `;
            document.body.appendChild(installBanner);
        }
        
        function dismissInstallBanner() {
            const banner = document.getElementById('ayuni-install-banner');
            if (banner) {
                banner.style.animation = 'slideUp 0.3s ease-out reverse';
                setTimeout(() => banner.remove(), 300);
            }
            // Remember dismissal for 7 days
            localStorage.setItem('ayuni-pwa-dismissed', 'true');
            setTimeout(() => {
                localStorage.removeItem('ayuni-pwa-dismissed');
            }, 7 * 24 * 60 * 60 * 1000);
        }
        
        function installPWA() {
            if (deferredPrompt) {
                // Hide our banner first
                dismissInstallBanner();
                
                // Trigger the native browser install prompt
                deferredPrompt.prompt();
                
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('Ayuni PWA: User accepted the install prompt');
                        // App will be installed - no need to show banner again
                        localStorage.setItem('ayuni-pwa-installed', 'true');
                    } else {
                        console.log('Ayuni PWA: User dismissed the install prompt');
                        // Maybe show a tip for manual installation
                        setTimeout(showManualInstallTip, 1000);
                    }
                    deferredPrompt = null;
                });
            } else {
                // Fallback if no native prompt available
                showManualInstallTip();
            }
        }
        
        function showManualInstallTip() {
            const tip = document.createElement('div');
            tip.innerHTML = `
                <div style="position: fixed; bottom: 20px; left: 20px; right: 20px; background: #546BEC; color: white; padding: 12px 16px; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); z-index: 9999; font-family: Inter, sans-serif; animation: slideUp 0.3s ease-out;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="font-size: 14px;">
                            💡 <strong>Tip:</strong> Tap your browser's menu → "Add to Home Screen"
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 16px; cursor: pointer; opacity: 0.8;">×</button>
                    </div>
                </div>
            `;
            document.body.appendChild(tip);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (tip.parentElement) {
                    tip.remove();
                }
            }, 5000);
        }
    </script>
    
    <!-- Timezone Search JavaScript -->
    <script>
    function setupTimezoneSearch(selectId) {
        const searchInput = document.getElementById(selectId + '_search');
        const hiddenSelect = document.getElementById(selectId);
        const dropdown = document.getElementById(selectId + '_dropdown');
        const options = dropdown.querySelectorAll('.timezone-option');
        
        let isOpen = false;
        let selectedValue = hiddenSelect.value;
        
        // Set initial display value
        if (selectedValue) {
            const selectedOption = Array.from(hiddenSelect.options).find(opt => opt.value === selectedValue);
            if (selectedOption) {
                searchInput.value = selectedOption.textContent;
            }
        }
        
        // Auto-detect timezone
        if (!selectedValue || selectedValue === '') {
            try {
                const detectedTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                const matchingOption = Array.from(hiddenSelect.options).find(opt => opt.value === detectedTz);
                if (matchingOption) {
                    hiddenSelect.value = detectedTz;
                    searchInput.value = matchingOption.textContent;
                    searchInput.style.fontStyle = 'italic';
                    searchInput.style.color = '#059669';
                    setTimeout(() => {
                        searchInput.style.fontStyle = '';
                        searchInput.style.color = '';
                    }, 2000);
                }
            } catch (e) {
                // Fallback if timezone detection fails
                console.log('Timezone auto-detection not supported');
            }
        }
        
        // Show/hide dropdown
        function toggleDropdown() {
            isOpen = !isOpen;
            dropdown.classList.toggle('hidden', !isOpen);
            if (isOpen) {
                filterOptions('');
                dropdown.scrollTop = 0;
            }
        }
        
        // Filter options based on search
        function filterOptions(searchTerm) {
            const lowerSearch = searchTerm.toLowerCase();
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                const matches = text.includes(lowerSearch);
                option.style.display = matches ? 'block' : 'none';
            });
        }
        
        // Handle search input
        searchInput.addEventListener('input', function() {
            if (!isOpen) toggleDropdown();
            filterOptions(this.value);
        });
        
        searchInput.addEventListener('focus', function() {
            if (!isOpen) toggleDropdown();
        });
        
        // Handle option selection
        options.forEach(option => {
            option.addEventListener('click', function() {
                const value = this.dataset.value;
                const text = this.textContent;
                
                searchInput.value = text;
                hiddenSelect.value = value;
                selectedValue = value;
                toggleDropdown();
                
                // Trigger change event
                hiddenSelect.dispatchEvent(new Event('change'));
            });
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                if (isOpen) toggleDropdown();
            }
        });
        
        // Handle keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) toggleDropdown();
                const visibleOptions = Array.from(options).filter(opt => opt.style.display !== 'none');
                if (visibleOptions.length > 0) {
                    visibleOptions[0].focus();
                }
            } else if (e.key === 'Escape') {
                if (isOpen) toggleDropdown();
            }
        });
        
        // Keyboard navigation for options
        options.forEach((option, index) => {
            option.setAttribute('tabindex', '0');
            option.addEventListener('keydown', function(e) {
                const visibleOptions = Array.from(options).filter(opt => opt.style.display !== 'none');
                const currentIndex = visibleOptions.indexOf(this);
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (currentIndex + 1) % visibleOptions.length;
                    visibleOptions[nextIndex].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = currentIndex === 0 ? visibleOptions.length - 1 : currentIndex - 1;
                    visibleOptions[prevIndex].focus();
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                } else if (e.key === 'Escape') {
                    toggleDropdown();
                    searchInput.focus();
                }
            });
        });
    }
    </script>
</body>
</html>