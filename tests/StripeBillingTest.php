<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/php/config.php';
require_once dirname(__DIR__) . '/src/php/stripe-billing.php';

final class StripeBillingTest extends TestCase {
    private static function signup(): array {
        return ['id' => 'signup-fixture', 'checkout_session_id' => 'cs_test_fixture',
            'plan' => 'starter', 'appointments_enabled' => 1, 'amount_cents' => 4400, 'environment' => 'test'];
    }

    public static function paidSession(): array {
        return [
            'id' => 'cs_test_fixture', 'mode' => 'subscription', 'livemode' => false,
            'client_reference_id' => 'signup-fixture', 'metadata' => ['signup_id' => 'signup-fixture'],
            'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'usd',
            'amount_total' => 4400, 'amount_subtotal' => 4400, 'customer' => 'cus_fixture',
            'subscription' => [
                'id' => 'sub_fixture', 'customer' => 'cus_fixture', 'status' => 'active',
                'metadata' => ['signup_id' => 'signup-fixture'], 'trial_end' => null,
                'items' => ['has_more' => false, 'data' => [[
                    'quantity' => 1, 'price' => ['unit_amount' => 4400, 'currency' => 'usd',
                        'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']],
                ]]],
            ],
            'invoice' => [
                'subscription' => 'sub_fixture', 'customer' => 'cus_fixture', 'billing_reason' => 'subscription_create',
                'status' => 'paid', 'paid' => true, 'currency' => 'usd', 'subtotal' => 4400,
                'total' => 4400, 'amount_paid' => 4400, 'amount_due' => 4400, 'amount_remaining' => 0,
                'payment_intent' => [
                    'id' => 'pi_fixture', 'status' => 'succeeded', 'currency' => 'usd',
                    'amount' => 4400, 'amount_received' => 4400, 'latest_charge' => [
                        'payment_intent' => 'pi_fixture', 'paid' => true, 'captured' => true,
                        'refunded' => false, 'amount_refunded' => 0, 'disputed' => false,
                        'currency' => 'usd', 'amount' => 4400,
                    ],
                ],
            ],
        ];
    }

    public function testOnlyVerifiedFullFirstMonthCardPaymentAllowsProvisioning(): void {
        self::assertSame(['payment_id' => 'pi_fixture', 'subscription_id' => 'sub_fixture', 'customer_id' => 'cus_fixture'],
            validateStripeSignupPayment(self::paidSession(), self::signup()));
        foreach ([['status' => 'open'], ['payment_status' => 'unpaid']] as $change) {
            self::assertNull(validateStripeSignupPayment(array_replace(self::paidSession(), $change), self::signup()));
        }
    }

    public static function invalidPayments(): array {
        return [
            'wrong signup' => [['client_reference_id'], 'other-signup'],
            'wrong session' => [['id'], 'cs_test_other'],
            'wrong environment' => [['livemode'], true],
            'underpayment' => [['amount_total'], 3900],
            'wrong currency' => [['currency'], 'eur'],
            'wrong subscription owner' => [['subscription', 'metadata', 'signup_id'], 'other-signup'],
            'wrong recurring price' => [['subscription', 'items', 'data', 0, 'price', 'unit_amount'], 3900],
            'wrong interval' => [['subscription', 'items', 'data', 0, 'price', 'recurring', 'interval'], 'year'],
            'trial' => [['subscription', 'trial_end'], time() + 86400],
            'renewal invoice' => [['invoice', 'billing_reason'], 'subscription_cycle'],
            'wrong invoice owner' => [['invoice', 'customer'], 'cus_other'],
            'invoice not paid' => [['invoice', 'paid'], false],
            'card payment pending' => [['invoice', 'payment_intent', 'status'], 'processing'],
            'refunded card payment' => [['invoice', 'payment_intent', 'latest_charge', 'amount_refunded'], 100],
            'disputed card payment' => [['invoice', 'payment_intent', 'latest_charge', 'disputed'], true],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function testRejectsMismatchedIncompleteOrReversedPayments(array $path, mixed $value): void {
        $session = self::paidSession();
        $target = &$session;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $value;
        $this->expectException(RuntimeException::class);
        validateStripeSignupPayment($session, self::signup());
    }

    public function testHostedCheckoutCannotRedirectToAnUntrustedHost(): void {
        self::assertSame('https://checkout.stripe.com/c/pay/fixture',
            stripeHostedUrl('https://checkout.stripe.com/c/pay/fixture', 'checkout.stripe.com'));
        $this->expectException(RuntimeException::class);
        stripeHostedUrl('https://checkout.stripe.com.evil.example/c/pay/fixture', 'checkout.stripe.com');
    }
}
