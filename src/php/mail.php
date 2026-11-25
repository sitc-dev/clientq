<?php
/**
 * Email helper using PHPMailer with Gmail SMTP
 */

require_once dirname(__DIR__, 2) . '/lib/PHPMailer-6.9.3/src/Exception.php';
require_once dirname(__DIR__, 2) . '/lib/PHPMailer-6.9.3/src/PHPMailer.php';
require_once dirname(__DIR__, 2) . '/lib/PHPMailer-6.9.3/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// SMTP Configuration
define('SMTP_HOST', getenv('CLIENTQ_SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('CLIENTQ_SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('CLIENTQ_SMTP_USER') ?: '');
define('SMTP_PASS', getenv('CLIENTQ_SMTP_PASS') ?: '');
define('SMTP_FROM_NAME', BUSINESS_NAME);

/**
 * Send an email via Gmail SMTP
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Plain text body
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendEmail(string $to, string $subject, string $body): array {
    if (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
        return ['success' => false, 'error' => 'SMTP is not configured'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}
