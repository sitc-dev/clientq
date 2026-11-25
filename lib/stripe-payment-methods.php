<?php

require_once __DIR__ . '/../src/php/stripe-billing.php';

function stripeCardBillingContext(array $signup): array {
    if ($signup['environment'] !== stripeBillingEnvironment()) {
        throw new RuntimeException('Subscription and Stripe environments do not match.');
    }
    $customer = stripeBillingRequest('GET', '/customers/' . rawurlencode($signup['customer_id']));
    $subscription = stripeBillingRequest('GET', '/subscriptions/' . rawurlencode($signup['subscription_id']));
    if (($customer['id'] ?? null) !== $signup['customer_id'] || !empty($customer['deleted'])
        || ($subscription['id'] ?? null) !== $signup['subscription_id']
        || ($subscription['customer'] ?? null) !== $signup['customer_id']) {
        throw new RuntimeException('Stripe billing records do not match this business.');
    }
    return [
        'customer' => $customer,
        'subscription' => $subscription,
        'default_id' => $subscription['default_payment_method'] ?? $customer['invoice_settings']['default_payment_method'] ?? null,
    ];
}

function stripeSavedCards(array $signup): array {
    $context = stripeCardBillingContext($signup);
    $cards = [];
    $after = null;
    do {
        $parameters = ['customer' => $signup['customer_id'], 'type' => 'card', 'limit' => 100];
        if ($after !== null) {
            $parameters['starting_after'] = $after;
        }
        $page = stripeBillingRequest('GET', '/payment_methods', $parameters);
        if (!is_array($page['data'] ?? null)) {
            throw new RuntimeException('Stripe returned an invalid card list.');
        }
        foreach ($page['data'] as $method) {
            $card = $method['card'] ?? null;
            if (($method['customer'] ?? null) !== $signup['customer_id'] || ($method['type'] ?? null) !== 'card'
                || !is_string($method['id'] ?? null) || !is_array($card)
                || !is_string($card['brand'] ?? null) || !is_string($card['last4'] ?? null)
                || !preg_match('/^[0-9]{4}$/', $card['last4'])
                || !is_int($card['exp_month'] ?? null) || !is_int($card['exp_year'] ?? null)) {
                throw new RuntimeException('Stripe returned invalid saved card details.');
            }
            $cards[] = ['id' => $method['id'], 'brand' => $card['brand'], 'last4' => $card['last4'],
                'exp_month' => $card['exp_month'], 'exp_year' => $card['exp_year'],
                'is_default' => $method['id'] === $context['default_id']];
        }
        $last = $page['data'] ? $page['data'][array_key_last($page['data'])]['id'] : null;
        if (!empty($page['has_more']) && ($last === null || $last === $after)) {
            throw new RuntimeException('Stripe card pagination did not advance.');
        }
        $after = $last;
    } while (!empty($page['has_more']));
    return $cards;
}

function stripeOwnedCard(array $signup, string $id): array {
    $method = stripeBillingRequest('GET', '/payment_methods/' . rawurlencode($id));
    if (($method['id'] ?? null) !== $id || ($method['customer'] ?? null) !== $signup['customer_id']
        || ($method['type'] ?? null) !== 'card') {
        throw new DomainException('This card does not belong to your business.');
    }
    return $method;
}

function validateStripeCardSetup(array $session, array $signup, int $businessId): void {
    $setup = $session['setup_intent'] ?? [];
    $method = $setup['payment_method'] ?? [];
    if (($session['mode'] ?? null) !== 'setup' || ($session['status'] ?? null) !== 'complete'
        || ($session['livemode'] ?? null) !== ($signup['environment'] === 'live')
        || ($session['customer'] ?? null) !== $signup['customer_id']
        || ($session['client_reference_id'] ?? null) !== 'clientq-business-' . $businessId
        || ($session['metadata']['business_id'] ?? null) !== (string)$businessId
        || ($setup['status'] ?? null) !== 'succeeded' || ($setup['customer'] ?? null) !== $signup['customer_id']
        || ($method['type'] ?? null) !== 'card' || ($method['customer'] ?? null) !== $signup['customer_id']) {
        throw new RuntimeException('Stripe has not verified this saved card for your business.');
    }
}
