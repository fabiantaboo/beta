<?php
// Configure session for longer lifetime
ini_set('session.gc_maxlifetime', 86400); // 24 hours
ini_set('session.cookie_lifetime', 86400); // 24 hours
session_set_cookie_params(86400); // 24 hours
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

$allowed_pages = ['home', 'onboarding', 'create-aei', 'chat', 'dashboard', 'profile', 'admin', 'admin-api', 'admin-prompts', 'admin-users', 'admin-beta', 'admin-emotions', 'admin-social', 'admin-proactive', 'admin-feedback', 'admin-logs', 'admin-decay', 'admin-migration', 'memory-setup'];
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
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" href="/assets/ayuni.png">
    <link rel="apple-touch-icon" href="/assets/ayuni.png">
    
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
                        'sans': ['Inter', 'system-ui', '-apple-system', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    </script>
</head>
<?php
// Set secure session cookies
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
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

        // PWA Install Prompt
        let deferredPrompt;
        
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('Ayuni PWA: Install prompt triggered');
            e.preventDefault();
            deferredPrompt = e;
            
            // Show install button/banner if desired
            showInstallBanner();
        });
        
        function showInstallBanner() {
            // Optional: Add install banner to UI
            const installBanner = document.createElement('div');
            installBanner.innerHTML = `
                <div style="position: fixed; bottom: 20px; right: 20px; background: #39D2DF; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; font-family: Inter, sans-serif;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span>📱 Install Ayuni App</span>
                        <button onclick="installPWA()" style="background: white; color: #39D2DF; border: none; padding: 6px 12px; border-radius: 4px; font-weight: 600; cursor: pointer;">Install</button>
                        <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 0 4px;">×</button>
                    </div>
                </div>
            `;
            document.body.appendChild(installBanner);
        }
        
        function installPWA() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('Ayuni PWA: User accepted the install prompt');
                    } else {
                        console.log('Ayuni PWA: User dismissed the install prompt');
                    }
                    deferredPrompt = null;
                });
            }
        }
    </script>
</body>
</html>