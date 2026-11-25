<?php

define('STRIPE_SECRET_KEY', getenv('CLIENTQ_STRIPE_SECRET_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('CLIENTQ_STRIPE_WEBHOOK_SECRET') ?: '');
define('STRIPE_API_BASE', rtrim(getenv('CLIENTQ_STRIPE_API_BASE') ?: 'https://api.stripe.com/v1', '/'));

function stripeRequest(
    string $method,
    string $endpoint,
    array $parameters = [],
    ?string $idempotencyKey = null,
    ?string $apiVersion = null
): array {
    if (STRIPE_SECRET_KEY === '') {
        throw new RuntimeException('Stripe is not configured.');
    }

    $base = STRIPE_API_BASE;
    $testBase = getenv('CLIENTQ_TEST_STRIPE_API_BASE');
    if (PHP_SAPI === 'cli-server' && getenv('CLIENTQ_RUN_INTEGRATION_TESTS') === '1'
        && is_string($testBase) && preg_match('#^http://127\.0\.0\.1:\d+/v1$#', $testBase)) {
        $base = $testBase;
    }
    if ($method === 'GET' && $parameters) {
        $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($parameters);
    }
    $handle = curl_init($base . $endpoint);
    $headers = [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    if ($apiVersion !== null) {
        $headers[] = 'Stripe-Version: ' . $apiVersion;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($method !== 'GET' && $parameters) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($parameters);
    }
    curl_setopt_array($handle, $options);

    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Could not connect to Stripe.');
    }

    $response = json_decode($body, true);
    if (!is_array($response) || $status < 200 || $status >= 300) {
        $message = $response['error']['message'] ?? 'Stripe rejected the payment request.';
        throw new RuntimeException($message);
    }

    return $response;
}

function verifyStripeSignature(string $payload, string $signatureHeader): bool {
    if (STRIPE_WEBHOOK_SECRET === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
        if ($key === 't') {
            $timestamp = ctype_digit((string)$value) ? (int)$value : null;
        } elseif ($key === 'v1' && is_string($value)) {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || abs(time() - $timestamp) > 300 || !$signatures) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
