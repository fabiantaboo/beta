<?php
session_start();

$page = $_GET['page'] ?? 'home';

include_once 'config/database.php';
include_once 'includes/functions.php';

$allowed_pages = ['home', 'onboarding', 'create-aei', 'chat', 'dashboard'];
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

$page_title = match($page) {
    'onboarding' => 'Welcome to Ayuni',
    'create-aei' => 'Create Your AEI',
    'chat' => 'Chat with AEI',
    'dashboard' => 'Your AEIs',
    default => 'Ayuni Beta'
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'ayuni-aqua': '#39D2DF',
                        'ayuni-blue': '#546BEC',
                        'ayuni-dark': '#10142B',
                        'ayuni-white': '#FFFFFF'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-ayuni-dark min-h-screen text-ayuni-white">
    <?php include "pages/{$page}.php"; ?>
</body>
</html>