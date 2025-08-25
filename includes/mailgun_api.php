<?php
class MailgunAPI {
    private $apiKey;
    private $domain;
    private $fromEmail;
    private $fromName;
    
    public function __construct() {
        global $pdo;
        
        // Load Mailgun settings from admin_settings
        try {
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_category = 'mailgun'");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->apiKey = $settings['mailgun_api_key'] ?? null;
            $this->domain = $settings['mailgun_domain'] ?? null;
            $this->fromEmail = $settings['mailgun_from_email'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $this->fromName = $settings['mailgun_from_name'] ?? 'Ayuni Beta';
        } catch (PDOException $e) {
            error_log("Failed to load Mailgun settings: " . $e->getMessage());
        }
    }
    
    public function isConfigured() {
        return !empty($this->apiKey) && !empty($this->domain);
    }
    
    public function sendPasswordResetEmail($email, $resetUrl, $firstName = '') {
        if (!$this->isConfigured()) {
            error_log("Mailgun not configured - cannot send password reset email");
            return false;
        }
        
        $subject = "Password Reset - Ayuni Beta";
        $htmlContent = $this->generatePasswordResetHTML($resetUrl, $firstName);
        $textContent = $this->generatePasswordResetText($resetUrl, $firstName);
        
        return $this->sendEmail($email, $subject, $htmlContent, $textContent);
    }
    
    private function sendEmail($to, $subject, $htmlContent, $textContent = null) {
        $url = "https://api.mailgun.net/v3/{$this->domain}/messages";
        
        $postData = [
            'from' => "{$this->fromName} <{$this->fromEmail}>",
            'to' => $to,
            'subject' => $subject,
            'html' => $htmlContent
        ];
        
        if ($textContent) {
            $postData['text'] = $textContent;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_USERPWD, "api:{$this->apiKey}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ayuni-Beta/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Mailgun cURL error: " . $curlError);
            return false;
        }
        
        if ($httpCode !== 200) {
            error_log("Mailgun API error (HTTP $httpCode): " . $response);
            return false;
        }
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['id'])) {
            error_log("Mailgun unexpected response: " . $response);
            return false;
        }
        
        // Log successful email send
        error_log("Password reset email sent successfully to $to (Message ID: {$result['id']})");
        return true;
    }
    
    private function generatePasswordResetHTML($resetUrl, $firstName) {
        $greeting = !empty($firstName) ? "Hi " . htmlspecialchars($firstName) : "Hello";
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - Ayuni Beta</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: white; padding: 30px; border: 1px solid #e1e5e9; border-top: none; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }
        .footer { color: #666; font-size: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
        .warning { background: #fef3cd; border: 1px solid #fecf41; padding: 15px; border-radius: 6px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0; font-size: 28px;">🔐 Password Reset</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Ayuni Beta</p>
        </div>
        <div class="content">
            <p>' . $greeting . ',</p>
            
            <p>You requested a password reset for your Ayuni Beta account. Click the button below to create a new password:</p>
            
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($resetUrl) . '" class="button">Reset My Password</a>
            </div>
            
            <div class="warning">
                <strong>⏰ This link expires in 1 hour</strong><br>
                For security reasons, this password reset link will only work for 1 hour from now.
            </div>
            
            <p>If you didn\'t request this password reset, you can safely ignore this email. Your password will remain unchanged.</p>
            
            <p>If the button above doesn\'t work, copy and paste this link into your browser:</p>
            <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 14px;">
                ' . htmlspecialchars($resetUrl) . '
            </p>
        </div>
        <div class="footer">
            <p>This email was sent by Ayuni Beta. If you have questions, please contact our support team.</p>
            <p>© ' . date('Y') . ' Ayuni Beta. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }
    
    private function generatePasswordResetText($resetUrl, $firstName) {
        $greeting = !empty($firstName) ? "Hi " . $firstName : "Hello";
        
        return "$greeting,

You requested a password reset for your Ayuni Beta account.

To reset your password, please visit this link:
$resetUrl

⏰ IMPORTANT: This link expires in 1 hour for security reasons.

If you didn't request this password reset, you can safely ignore this email. Your password will remain unchanged.

---
Ayuni Beta
© " . date('Y') . " All rights reserved.

If you have questions, please contact our support team.";
    }
    
    public function testConnection() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailgun not configured'];
        }
        
        // Use the correct Mailgun API v4 domains endpoint to get domain info
        $url = "https://api.mailgun.net/v4/domains/{$this->domain}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "api:{$this->apiKey}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ayuni-Beta/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlError];
        }
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $domainName = $result['domain']['name'] ?? 'Unknown';
            $domainState = $result['domain']['state'] ?? 'Unknown';
            return ['success' => true, 'message' => "Connection successful! Domain: {$domainName} (Status: {$domainState})"];
        } elseif ($httpCode === 401) {
            return ['success' => false, 'error' => 'Authentication failed: Invalid API key'];
        } elseif ($httpCode === 404) {
            return ['success' => false, 'error' => 'Domain not found: ' . $this->domain];
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['message'] ?? substr($response, 0, 100);
            return ['success' => false, 'error' => "HTTP $httpCode: $errorMessage"];
        }
    }
}
?>