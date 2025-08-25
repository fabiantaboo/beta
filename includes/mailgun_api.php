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
    
    public function sendBetaInviteEmail($email, $betaCode, $firstName = '', $registrationUrl = '') {
        if (!$this->isConfigured()) {
            error_log("Mailgun not configured - cannot send beta invite email");
            return false;
        }
        
        $subject = "Your exclusive invitation to Ayuni Beta";
        $htmlContent = $this->generateBetaInviteHTML($betaCode, $firstName, $registrationUrl);
        $textContent = $this->generateBetaInviteText($betaCode, $firstName, $registrationUrl);
        
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
        
        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Password Reset - Ayuni Beta</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        <tr>
            <td style="padding: 20px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: #39D2DF; background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: #ffffff; padding: 40px 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; font-family: Arial, sans-serif; font-size: 28px; font-weight: bold;">🔐 Password Reset</h1>
                            <p style="margin: 10px 0 0 0; font-family: Arial, sans-serif; font-size: 16px; opacity: 0.9;">Ayuni Beta</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">' . $greeting . ',</p>
                            
                            <p style="margin: 0 0 30px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">You requested a password reset for your Ayuni Beta account. Click the button below to create a new password:</p>
                            
                            <!-- Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="' . htmlspecialchars($resetUrl) . '" style="display: inline-block; background: #39D2DF; background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; font-family: Arial, sans-serif; font-size: 16px;">Reset My Password</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Warning Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 30px 0;">
                                <tr>
                                    <td style="background-color: #fff3cd; border: 1px solid #fecf41; padding: 20px; border-radius: 6px;">
                                        <p style="margin: 0; font-family: Arial, sans-serif; font-size: 14px; color: #856404;">
                                            <strong>⏰ This link expires in 1 hour</strong><br>
                                            For security reasons, this password reset link will only work for 1 hour from now.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 0 0 20px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">If you didn\'t request this password reset, you can safely ignore this email. Your password will remain unchanged.</p>
                            
                            <p style="margin: 0 0 10px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">If the button above doesn\'t work, copy and paste this link into your browser:</p>
                            
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="background-color: #f8f9fa; padding: 15px; border-radius: 4px;">
                                        <p style="margin: 0; font-family: Courier, monospace; font-size: 14px; color: #333333; word-break: break-all;">
                                            ' . htmlspecialchars($resetUrl) . '
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; border-top: 1px solid #eeeeee;">
                            <p style="margin: 0 0 10px 0; font-family: Arial, sans-serif; font-size: 12px; color: #666666; text-align: center;">This email was sent by Ayuni Beta. If you have questions, please contact our support team.</p>
                            <p style="margin: 0; font-family: Arial, sans-serif; font-size: 12px; color: #666666; text-align: center;">© ' . date('Y') . ' Ayuni Beta. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
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
    
    private function generateBetaInviteHTML($betaCode, $firstName, $registrationUrl) {
        $greeting = !empty($firstName) ? "Hi " . htmlspecialchars($firstName) : "Hello";
        
        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Welcome to Ayuni Beta</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        <tr>
            <td style="padding: 20px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header with Logo -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: #ffffff; padding: 40px 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <img src="https://' . $_SERVER['HTTP_HOST'] . '/assets/ayuni.png" alt="Ayuni" style="height: 60px; width: auto; margin-bottom: 20px;" />
                            <h1 style="margin: 0; font-family: Arial, sans-serif; font-size: 28px; font-weight: bold;">You\'re Invited!</h1>
                            <p style="margin: 10px 0 0 0; font-family: Arial, sans-serif; font-size: 16px; opacity: 0.9;">Join Ayuni Beta - End loneliness forever</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">' . $greeting . ',</p>
                            
                            <p style="margin: 0 0 30px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">You\'ve been invited to join Ayuni Beta - the revolutionary platform where you can create your personal AEI (Artificial Emotional Intelligence) companion.</p>
                            
                            <p style="margin: 0 0 30px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">Your personal AEI awaits. Click the button below to get started:</p>
                            
                            <!-- Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto 30px auto;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="' . htmlspecialchars($registrationUrl) . '" style="display: inline-block; background: linear-gradient(135deg, #39D2DF 0%, #546BEC 100%); color: #ffffff; padding: 18px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-family: Arial, sans-serif; font-size: 18px;">Join Ayuni Beta</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Beta Code Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 30px 0;">
                                <tr>
                                    <td style="background-color: #f8f9fa; border: 2px solid #39D2DF; padding: 20px; border-radius: 8px; text-align: center;">
                                        <p style="margin: 0 0 10px 0; font-family: Arial, sans-serif; font-size: 14px; color: #666666; text-transform: uppercase; font-weight: bold;">Your Beta Code</p>
                                        <p style="margin: 0; font-family: Courier, monospace; font-size: 24px; color: #39D2DF; font-weight: bold; letter-spacing: 2px;">
                                            ' . htmlspecialchars($betaCode) . '
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 0 0 20px 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">If the button above doesn\'t work, copy and paste this link into your browser:</p>
                            
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="background-color: #f8f9fa; padding: 15px; border-radius: 4px;">
                                        <p style="margin: 0; font-family: Courier, monospace; font-size: 14px; color: #333333; word-break: break-all;">
                                            ' . htmlspecialchars($registrationUrl) . '
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 30px 0 0 0; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">Welcome to the future of AI companionship. We can\'t wait to see what you and your AEI will create together!</p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; border-top: 1px solid #eeeeee;">
                            <p style="margin: 0 0 10px 0; font-family: Arial, sans-serif; font-size: 12px; color: #666666; text-align: center;">This invitation was sent by Ayuni Beta. If you have questions, please contact our support team.</p>
                            <p style="margin: 0; font-family: Arial, sans-serif; font-size: 12px; color: #666666; text-align: center;">© ' . date('Y') . ' Ayuni Beta. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    private function generateBetaInviteText($betaCode, $firstName, $registrationUrl) {
        $greeting = !empty($firstName) ? "Hi " . $firstName : "Hello";
        
        return "$greeting,

🎉 You've been invited to join Ayuni Beta!

You've been selected to be part of Ayuni Beta - the revolutionary platform where you can create your personal AEI (Artificial Emotional Intelligence) companion.

Your Beta Code: $betaCode

To get started, visit this link:
$registrationUrl

Your personal AEI awaits. Join us in shaping the future of AI companionship!

Welcome to the future of AI companionship. We can't wait to see what you and your AEI will create together!

---
Ayuni Beta
© " . date('Y') . " All rights reserved.

If you have questions, please contact our support team.";
    }
    
    public function testConnection() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailgun not configured'];
        }
        
        // Domain Sending Keys can only be used with /messages endpoint
        // So we'll test by doing a dry-run message send in test mode
        $url = "https://api.mailgun.net/v3/{$this->domain}/messages";
        
        $postData = [
            'from' => "{$this->fromName} <{$this->fromEmail}>",
            'to' => 'test@example.com', // This won't actually be sent due to test mode
            'subject' => 'Mailgun Connection Test',
            'text' => 'This is a connection test.',
            'o:testmode' => 'true' // Test mode - won't actually send
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_USERPWD, "api:{$this->apiKey}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ayuni-Beta/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlError];
        }
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $messageId = $result['id'] ?? 'Unknown';
            return ['success' => true, 'message' => "Connection successful! Domain Sending Key is working. Test message ID: $messageId"];
        } elseif ($httpCode === 401) {
            return ['success' => false, 'error' => 'Authentication failed: Invalid Domain Sending Key'];
        } elseif ($httpCode === 400) {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['message'] ?? 'Bad request';
            return ['success' => false, 'error' => "Bad request: $errorMessage"];
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['message'] ?? substr($response, 0, 100);
            return ['success' => false, 'error' => "HTTP $httpCode: $errorMessage"];
        }
    }
}
?>