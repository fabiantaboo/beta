<?php
if (!getUserSession()) {
    $userId = generateId();
    setUserSession($userId);
    
    $stmt = $pdo->prepare("INSERT INTO users (id) VALUES (?)");
    $stmt->execute([$userId]);
}

$stmt = $pdo->prepare("SELECT is_onboarded FROM users WHERE id = ?");
$stmt->execute([getUserSession()]);
$user = $stmt->fetch();

if (!$user['is_onboarded']) {
    redirectTo('onboarding');
}

redirectTo('dashboard');
?>