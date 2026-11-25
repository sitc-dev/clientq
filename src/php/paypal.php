<?php

function paypalEnvironment(): string {
    $environment = getenv('CLIENTQ_PAYPAL_ENVIRONMENT') ?: 'sandbox';
    if (!in_array($environment, ['sandbox', 'live'], true)) {
        throw new RuntimeException('Invalid PayPal environment.');
    }
    return $environment;
}

function paypalSetting(string $name): string {
    return getenv('CLIENTQ_PAYPAL_' . strtoupper(paypalEnvironment()) . '_' . $name) ?: '';
}

function paypalPlanId(string $plan, bool $appointments = false): string {
    $name = match ($plan) {
        'starter' => 'ESSENTIALS',
        'growth' => 'SELECT',
        'pro' => 'PREMIER',
        default => throw new InvalidArgumentException('Select a valid ClientQ plan.'),
    };
    return paypalSetting('PLAN_' . $name . ($plan === 'starter' && $appointments ? '_APPOINTMENTS' : ''));
}

function paypalPlan(string $plan, bool $appointments = false): array {
    $id = paypalPlanId($plan, $appointments);
    if (!preg_match('/^P-[A-Z0-9]{24}$/', $id)) {
        throw new RuntimeException('The PayPal subscription plan is not configured.');
    }
    return ['id' => $id, 'amount_cents' => planMonthlyAmount($plan, $appointments)];
}

function paypalConfigured(string $plan, bool $appointments = false): bool {
    if (!in_array(getenv('CLIENTQ_PAYPAL_ENVIRONMENT') ?: 'sandbox', ['sandbox', 'live'], true)) {
        return false;
    }
    return paypalSetting('CLIENT_ID') !== ''
        && paypalSetting('CLIENT_SECRET') !== ''
        && preg_match('/^P-[A-Z0-9]{24}$/', paypalPlanId($plan, $appointments)) === 1;
}

function paypalHttp(string $method, string $endpoint, array $headers, ?string $body = null): array {
    $base = paypalEnvironment() === 'live'
        ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    // Only local CLI integration tests may replace PayPal with a fixture server.
    $testBase = getenv('CLIENTQ_TEST_PAYPAL_API_BASE');
    if (PHP_SAPI === 'cli-server' && getenv('CLIENTQ_RUN_INTEGRATION_TESTS') === '1'
        && is_string($testBase) && preg_match('#^http://127\.0\.0\.1:\d+$#', $testBase)) {
        $base = $testBase;
    }
    $handle = curl_init($base . $endpoint);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($responseBody === false || $error !== '') {
        throw new RuntimeException('Could not connect to PayPal.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('PayPal rejected the request (HTTP ' . $status . ').');
    }
    if ($status === 204) {
        return [];
    }
    $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response)) {
        throw new RuntimeException('PayPal returned an invalid response.');
    }
    return $response;
}

function paypalRequest(string $method, string $endpoint, ?array $parameters = null, ?string $requestId = null): array {
    static $tokens = [];
    $clientId = paypalSetting('CLIENT_ID');
    $secret = paypalSetting('CLIENT_SECRET');
    if ($clientId === '' || $secret === '') {
        throw new RuntimeException('PayPal is not configured.');
    }
    $tokenKey = hash('sha256', paypalEnvironment() . ':' . $clientId . ':' . $secret);
    if (!isset($tokens[$tokenKey])) {
        $auth = paypalHttp('POST', '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
            'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        $token = $auth['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('PayPal did not return an access token.');
        }
        $tokens[$tokenKey] = $token;
    }
    $headers = ['Authorization: Bearer ' . $tokens[$tokenKey], 'Content-Type: application/json', 'Prefer: return=representation'];
    if ($requestId !== null) {
        $headers[] = 'PayPal-Request-Id: ' . $requestId;
    }
    return paypalHttp($method, $endpoint, $headers,
        $parameters === null ? null : json_encode($parameters, JSON_THROW_ON_ERROR));
}

function paypalMoneyMatches(mixed $money, int $cents): bool {
    if (!is_array($money) || ($money['currency_code'] ?? '') !== 'USD') {
        return false;
    }
    $value = $money['value'] ?? null;
    if (!is_string($value) || !preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $value, $match)) {
        return false;
    }
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0') === $cents;
}

function validatePayPalMonthlyPlan(array $plan, string $expectedId, int $cents): void {
    $cycles = $plan['billing_cycles'] ?? [];
    $cycle = is_array($cycles) && count($cycles) === 1 ? reset($cycles) : [];
    if (($plan['id'] ?? '') !== $expectedId || ($plan['status'] ?? '') !== 'ACTIVE'
        || !is_array($cycle) || ($cycle['tenure_type'] ?? '') !== 'REGULAR'
        || ($cycle['frequency']['interval_unit'] ?? '') !== 'MONTH'
        || ($cycle['frequency']['interval_count'] ?? null) !== 1
        || ($cycle['total_cycles'] ?? null) !== 0
        || !paypalMoneyMatches($cycle['pricing_scheme']['fixed_price'] ?? null, $cents)
        || (isset($plan['payment_preferences']['setup_fee'])
            && !paypalMoneyMatches($plan['payment_preferences']['setup_fee'], 0))
        || (isset($plan['taxes']['percentage'])
            && preg_match('/^0(?:\.0+)?$/', (string)$plan['taxes']['percentage']) !== 1)) {
        throw new RuntimeException('The PayPal plan does not match the advertised monthly price and terms.');
    }
}

function validatePayPalSubscription(array $subscription, array $signup): void {
    if (($subscription['id'] ?? '') !== $signup['subscription_id']
        || ($subscription['plan_id'] ?? '') !== $signup['plan_id']
        || ($subscription['custom_id'] ?? '') !== $signup['id']
        || ($subscription['plan_overridden'] ?? false) !== false
        || ($subscription['quantity'] ?? '1') !== '1'
        || (isset($subscription['shipping_amount'])
            && !paypalMoneyMatches($subscription['shipping_amount'], 0))) {
        throw new RuntimeException('PayPal subscription does not match this signup.');
    }
}

function paypalCompletedPayment(array $transactions, int $cents): ?string {
    foreach ($transactions['transactions'] ?? [] as $transaction) {
        if (($transaction['status'] ?? '') === 'COMPLETED'
            && is_string($transaction['id'] ?? null) && $transaction['id'] !== ''
            && paypalMoneyMatches($transaction['amount_with_breakdown']['gross_amount'] ?? null, $cents)) {
            return $transaction['id'];
        }
    }
    return null;
}

function paypalSignupRecord(): ?array {
    $id = $_SESSION['paypal_signup_id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM paypal_signup_subscriptions WHERE id = ?');
    $stmt->execute([$id]);
    $signup = $stmt->fetch();
    return $signup ?: null;
}

function paypalApprovalUrl(array $subscription): ?string {
    $host = paypalEnvironment() === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
    foreach ($subscription['links'] ?? [] as $link) {
        $url = $link['href'] ?? '';
        if (($link['rel'] ?? '') === 'approve' && is_string($url)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === $host
            && parse_url($url, PHP_URL_USER) === null) {
            return $url;
        }
    }
    return null;
}

function verifyPayPalSignupPayment(array $signup): ?string {
    $subscription = paypalRequest('GET', '/v1/billing/subscriptions/' . rawurlencode($signup['subscription_id']));
    validatePayPalSubscription($subscription, $signup);
    if (($subscription['status'] ?? '') !== 'ACTIVE') {
        return null;
    }
    $plan = paypalRequest('GET', '/v1/billing/plans/' . rawurlencode($signup['plan_id']));
    validatePayPalMonthlyPlan($plan, $signup['plan_id'], (int)$signup['amount_cents']);
    $start = new DateTimeImmutable($subscription['create_time']);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // PayPal limits transaction lookups to a 180-day range.
    if ($start < $now->modify('-179 days')) {
        throw new RuntimeException('The signup payment requires manual reconciliation.');
    }
    $transactions = paypalRequest('GET', '/v1/billing/subscriptions/'
        . rawurlencode($signup['subscription_id']) . '/transactions?' . http_build_query([
            'start_time' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $now->format('Y-m-d\TH:i:s\Z'),
        ]));
    return paypalCompletedPayment($transactions, (int)$signup['amount_cents']);
}
