<?php

require_once __DIR__ . '/stripe.php';

function stripeBillingConfigured(): bool {
    return preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9]+$/', STRIPE_SECRET_KEY) === 1;
}

function stripeBillingEnvironment(): string {
    if (!stripeBillingConfigured()) {
        throw new RuntimeException('Stripe Billing is not configured.');
    }
    return str_contains(STRIPE_SECRET_KEY, '_live_') ? 'live' : 'test';
}

function stripeBillingRequest(string $method, string $path, array $parameters = [], ?string $key = null): array {
    // Keep subscription invoice/payment fields stable without changing the invoice integration's API version.
    return stripeRequest($method, $path, $parameters, $key, '2024-06-20');
}

function stripeSignupRecord(): ?array {
    $id = $_SESSION['stripe_signup_id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM stripe_signup_subscriptions WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    return $record === false ? null : $record;
}

function stripeHostedUrl(mixed $url, string $host): string {
    $parts = is_string($url) ? parse_url($url) : false;
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || ($parts['host'] ?? '') !== $host || isset($parts['user']) || isset($parts['pass'])
        || (isset($parts['port']) && $parts['port'] !== 443)) {
        throw new RuntimeException('Stripe did not return a valid hosted checkout URL.');
    }
    return $url;
}

function validateStripeSignupSession(array $session, array $signup): void {
    if (($session['id'] ?? null) !== $signup['checkout_session_id']
        || ($session['mode'] ?? null) !== 'subscription'
        || ($session['livemode'] ?? null) !== ($signup['environment'] === 'live')
        || ($session['client_reference_id'] ?? null) !== $signup['id']
        || ($session['metadata']['signup_id'] ?? null) !== $signup['id']) {
        throw new RuntimeException('Stripe checkout does not match this signup.');
    }
}

function validateStripeSignupPayment(array $session, array $signup): ?array {
    validateStripeSignupSession($session, $signup);
    $amount = (int)$signup['amount_cents'];
    if ($amount !== planMonthlyAmount($signup['plan'], (bool)$signup['appointments_enabled'])) {
        throw new RuntimeException('Stripe signup price does not match the selected plan.');
    }
    if (($session['status'] ?? null) !== 'complete' || ($session['payment_status'] ?? null) !== 'paid') {
        return null;
    }
    if (($session['currency'] ?? null) !== 'usd'
        || ($session['amount_total'] ?? null) !== $amount || ($session['amount_subtotal'] ?? null) !== $amount) {
        throw new RuntimeException('Stripe checkout amount or currency does not match.');
    }
    $subscription = $session['subscription'] ?? null;
    $invoice = $session['invoice'] ?? null;
    $customer = $session['customer'] ?? null;
    if (!is_array($subscription) || !is_array($invoice) || !is_string($customer)
        || !str_starts_with($customer, 'cus_')
        || !is_string($subscription['id'] ?? null) || !str_starts_with($subscription['id'], 'sub_')
        || ($subscription['customer'] ?? null) !== $customer
        || ($subscription['metadata']['signup_id'] ?? null) !== $signup['id']
        || ($subscription['status'] ?? null) !== 'active'
        || ($subscription['trial_end'] ?? null) !== null
        || !empty($subscription['discount']) || !empty($subscription['discounts'])) {
        throw new RuntimeException('Stripe subscription is not an active, full-price signup subscription.');
    }
    $items = $subscription['items']['data'] ?? [];
    $price = $items[0]['price'] ?? [];
    if (count($items) !== 1 || ($subscription['items']['has_more'] ?? false)
        || ($items[0]['quantity'] ?? null) !== 1
        || ($price['currency'] ?? null) !== 'usd' || ($price['unit_amount'] ?? null) !== $amount
        || ($price['recurring']['interval'] ?? null) !== 'month'
        || ($price['recurring']['interval_count'] ?? null) !== 1
        || ($price['recurring']['usage_type'] ?? null) !== 'licensed') {
        throw new RuntimeException('Stripe subscription monthly price does not match.');
    }
    if (($invoice['subscription'] ?? null) !== $subscription['id']
        || ($invoice['customer'] ?? null) !== $customer
        || ($invoice['billing_reason'] ?? null) !== 'subscription_create'
        || ($invoice['status'] ?? null) !== 'paid' || ($invoice['paid'] ?? null) !== true
        || ($invoice['currency'] ?? null) !== 'usd'
        || ($invoice['subtotal'] ?? null) !== $amount || ($invoice['total'] ?? null) !== $amount
        || ($invoice['amount_paid'] ?? null) !== $amount || ($invoice['amount_due'] ?? null) !== $amount
        || ($invoice['amount_remaining'] ?? null) !== 0) {
        throw new RuntimeException('Stripe first subscription invoice has not been paid in full.');
    }
    $payment = $invoice['payment_intent'] ?? [];
    $charge = $payment['latest_charge'] ?? [];
    if (($payment['status'] ?? null) !== 'succeeded' || ($payment['currency'] ?? null) !== 'usd'
        || ($payment['amount'] ?? null) !== $amount || ($payment['amount_received'] ?? null) !== $amount
        || !is_string($payment['id'] ?? null) || !str_starts_with($payment['id'], 'pi_')
        || ($charge['payment_intent'] ?? null) !== $payment['id']
        || ($charge['paid'] ?? null) !== true || ($charge['captured'] ?? null) !== true
        || ($charge['refunded'] ?? null) !== false || ($charge['amount_refunded'] ?? null) !== 0
        || !empty($charge['disputed']) || ($charge['currency'] ?? null) !== 'usd'
        || ($charge['amount'] ?? null) !== $amount) {
        throw new RuntimeException('Stripe first card payment is incomplete, refunded, or disputed.');
    }
    return ['payment_id' => $payment['id'], 'subscription_id' => $subscription['id'], 'customer_id' => $customer];
}

function verifyStripeSignupPayment(array $signup): ?string {
    if ($signup['environment'] !== stripeBillingEnvironment()) {
        throw new RuntimeException('The signup belongs to a different Stripe environment.');
    }
    $session = stripeBillingRequest('GET', '/checkout/sessions/' . rawurlencode($signup['checkout_session_id']), [
        'expand' => ['subscription', 'invoice.payment_intent.latest_charge'],
    ]);
    $payment = validateStripeSignupPayment($session, $signup);
    if ($payment === null) {
        return null;
    }
    $stmt = getDB()->prepare(
        'UPDATE stripe_signup_subscriptions SET first_payment_id = ?, subscription_id = ?, customer_id = ? WHERE id = ?'
    );
    $stmt->execute([$payment['payment_id'], $payment['subscription_id'], $payment['customer_id'], $signup['id']]);
    return $payment['payment_id'];
}
