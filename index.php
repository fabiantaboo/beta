<?php
session_start();

include_once 'config/database.php';
include_once 'includes/functions.php';
include_once 'includes/router.php';

// Handle special actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        session_destroy();
        header("Location: /");
        exit;
    }
}

$allowed_pages = ['home', 'login', 'onboarding', 'create-aei', 'chat', 'dashboard', 'profile', 'admin'];
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

// Execute page logic FIRST (before any HTML output) to allow redirects
ob_start(); // Start output buffering to capture page content
include "pages/{$page}.php";
$page_content = ob_get_clean(); // Get the page content

$page_title = match($page) {
    'login' => 'Admin Login - Ayuni Beta',
    'onboarding' => 'Welcome to Ayuni',
    'create-aei' => 'Create Your AEI',
    'chat' => 'Chat with AEI',
    'dashboard' => 'Dashboard',
    'profile' => 'Profile Settings',
    'admin' => 'Admin Panel',
    default => 'Ayuni Beta'
};
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
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
</body>
</html>