<?php
/**
 * PHPMailer wrapper for transactional email (included from config.php).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('send_password_reset_email')) {
    function send_password_reset_email(string $to_email, string $to_name, string $reset_url): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to_email, $to_name);

            $mail->isHTML(true);
            $mail->Subject = APP_NAME . ' - Reset your password';
            $mail->Body = '<p>Hello ' . htmlspecialchars($to_name) . ',</p>'
                . '<p>We received a request to reset your ' . htmlspecialchars(APP_NAME) . ' password.</p>'
                . '<p><a href="' . htmlspecialchars($reset_url) . '">Click here to choose a new password</a></p>'
                . '<p>This link expires in ' . (int) PASSWORD_RESET_TOKEN_TTL_MINUTES . ' minutes. If you did not request this, you can ignore this email.</p>';
            $mail->AltBody = "Reset your password: {$reset_url}\nThis link expires in " . (int) PASSWORD_RESET_TOKEN_TTL_MINUTES . ' minutes.';

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Password reset email failed: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
