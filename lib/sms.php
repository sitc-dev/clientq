<?php

require_once __DIR__ . '/../src/php/exceptions/ClientQSmsException.php';

function sendTenantSms(array $tenant, string $phone, string $message): void {
    $accountSid = getenv('CLIENTQ_TWILIO_ACCOUNT_SID') ?: '';
    $authToken = getenv('CLIENTQ_TWILIO_AUTH_TOKEN') ?: '';
    if ($accountSid === '' || $authToken === '') {
        throw new ClientQSmsException('Twilio credentials are not configured.');
    }
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        throw new ClientQSmsException('Invalid SMS recipient phone number.');
    }

    $sender = getDB()->prepare(
        'SELECT g.phone_number
         FROM business_phone_groups m
         JOIN phone_groups g ON g.group_code = m.group_code
         WHERE m.business_id = :business_id'
    );
    $sender->execute([':business_id' => $tenant['id']]);
    $from = $sender->fetchColumn();
    if (!is_string($from) || !preg_match('/^[+][1-9][0-9]{1,14}$/', $from)) {
        throw new ClientQSmsException('No SMS phone number is assigned to this business.');
    }

    $base = rtrim(getenv('CLIENTQ_TWILIO_API_BASE') ?: 'https://api.twilio.com/2010-04-01', '/');
    $handle = curl_init($base . '/Accounts/' . rawurlencode($accountSid) . '/Messages.json');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => $from,
            'To' => '+1' . $phone,
            'Body' => $message,
        ]),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new ClientQSmsException('Could not connect to Twilio.');
    }
    $response = json_decode($body, true);
    if (!is_array($response) || $status < 200 || $status >= 300
        || !is_string($response['sid'] ?? null) || $response['sid'] === ''
        || !in_array($response['status'] ?? null, ['accepted', 'queued', 'sending', 'sent', 'delivered'], true)) {
        $code = is_array($response) && is_numeric($response['code'] ?? null)
            ? (int)$response['code'] : 0;
        throw new ClientQSmsException("Twilio rejected the SMS (HTTP $status, error $code).");
    }
}
