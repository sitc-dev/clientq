<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/php/paypal.php';
require_once dirname(__DIR__) . '/src/php/config.php';
require_once dirname(__DIR__) . '/lib/business-registration.php';

final class PayPalSubscriptionTest extends TestCase {
    private array $originalEnvironment = [];

    private function configure(array $settings): void {
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $this->originalEnvironment)) {
                $this->originalEnvironment[$key] = getenv($key);
            }
            putenv($value === null ? $key : $key . '=' . $value);
        }
    }

    protected function tearDown(): void {
        foreach ($this->originalEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testSelectsMatchingCredentialsAndNamedPlansForEachEnvironment(): void {
        foreach (['sandbox' => 'A', 'live' => 'B'] as $environment => $character) {
            $prefix = 'CLIENTQ_PAYPAL_' . strtoupper($environment) . '_';
            $this->configure([
                $prefix . 'CLIENT_ID' => $environment . '-test-client',
                $prefix . 'CLIENT_SECRET' => $environment . '-test-secret',
                $prefix . 'PLAN_ESSENTIALS' => 'P-' . str_repeat($character, 24),
                $prefix . 'PLAN_ESSENTIALS_APPOINTMENTS' => 'P-' . str_repeat($character, 23) . 'E',
                $prefix . 'PLAN_SELECT' => 'P-' . str_repeat($character, 23) . 'C',
                $prefix . 'PLAN_PREMIER' => 'P-' . str_repeat($character, 23) . 'D',
            ]);
        }
        foreach (['sandbox' => 'A', 'live' => 'B'] as $environment => $character) {
            $this->configure(['CLIENTQ_PAYPAL_ENVIRONMENT' => $environment]);
            self::assertSame($environment . '-test-client', paypalSetting('CLIENT_ID'));
            self::assertSame($environment . '-test-secret', paypalSetting('CLIENT_SECRET'));
            self::assertTrue(paypalConfigured('starter', true));
            self::assertSame(['id' => 'P-' . str_repeat($character, 23) . 'E', 'amount_cents' => 4400], paypalPlan('starter', true));
            foreach (['starter' => [3900, $character], 'growth' => [6400, 'C'], 'pro' => [9900, 'D']] as $plan => [$price, $last]) {
                self::assertTrue(paypalConfigured($plan));
                self::assertSame(['id' => 'P-' . str_repeat($character, 23) . $last, 'amount_cents' => $price], paypalPlan($plan));
                if ($plan !== 'starter') {
                    self::assertSame(paypalPlan($plan), paypalPlan($plan, true));
                }
            }
        }
        $this->configure(['CLIENTQ_PAYPAL_ENVIRONMENT' => '']);
        self::assertSame('sandbox', paypalEnvironment());
        self::assertSame('sandbox-test-client', paypalSetting('CLIENT_ID'));
    }

    public function testMissingSelectedSettingsCannotUseOtherEnvironmentOrLegacyValues(): void {
        $this->configure([
            'CLIENTQ_PAYPAL_ENVIRONMENT' => 'sandbox',
            'CLIENTQ_PAYPAL_SANDBOX_CLIENT_ID' => '',
            'CLIENTQ_PAYPAL_SANDBOX_CLIENT_SECRET' => '',
            'CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS' => '',
            'CLIENTQ_PAYPAL_LIVE_CLIENT_ID' => 'live-test-client',
            'CLIENTQ_PAYPAL_LIVE_CLIENT_SECRET' => 'live-test-secret',
            'CLIENTQ_PAYPAL_LIVE_PLAN_ESSENTIALS' => 'P-' . str_repeat('L', 24),
            'CLIENTQ_PAYPAL_CLIENT_ID' => 'legacy-test-client',
            'CLIENTQ_PAYPAL_CLIENT_SECRET' => 'legacy-test-secret',
            'CLIENTQ_PAYPAL_PLAN_STARTER' => 'P-' . str_repeat('Z', 24),
        ]);
        self::assertFalse(paypalConfigured('starter'));
        self::assertSame('', paypalSetting('CLIENT_ID'));
        $this->expectException(RuntimeException::class);
        paypalPlan('starter');
    }

    public function testInvalidEnvironmentCannotEnableCheckout(): void {
        $this->configure(['CLIENTQ_PAYPAL_ENVIRONMENT' => 'production']);
        self::assertFalse(paypalConfigured('starter'));
        $this->expectException(RuntimeException::class);
        paypalSetting('CLIENT_ID');
    }

    public function testMissingAddOnPlanCannotFallBackToWaitlistOnlyPrice(): void {
        $this->configure([
            'CLIENTQ_PAYPAL_ENVIRONMENT' => 'sandbox',
            'CLIENTQ_PAYPAL_SANDBOX_CLIENT_ID' => 'test-client',
            'CLIENTQ_PAYPAL_SANDBOX_CLIENT_SECRET' => 'test-secret',
            'CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS' => 'P-' . str_repeat('A', 24),
            'CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS_APPOINTMENTS' => '',
        ]);
        self::assertTrue(paypalConfigured('starter'));
        self::assertFalse(paypalConfigured('starter', true));
        $this->expectException(RuntimeException::class);
        paypalPlan('starter', true);
    }

    public function testRegistrationNormalizesAppointmentAccessWithoutAffectingHigherTierPrices(): void {
        $input = ['business_name' => 'Add On Test', 'slug' => 'add-on-test', 'first_name' => 'Add',
            'last_name' => 'On', 'phone' => '2025550123', 'email' => 'addon@example.com',
            'password' => 'test-password', 'plan' => 'starter'];
        foreach (['0' => false, '1' => true] as $choice => $expected) {
            $registration = validateBusinessRegistrationInput($input + ['appointments_enabled' => (string)$choice]);
            self::assertSame($expected, $registration['appointments_enabled']);
            self::assertSame($expected, appointmentsEnabled($registration));
        }
        self::assertFalse(appointmentsEnabled(validateBusinessRegistrationInput($input)));
        foreach (['growth', 'pro'] as $plan) {
            $registration = validateBusinessRegistrationInput(array_replace($input, ['plan' => $plan, 'appointments_enabled' => '0']));
            self::assertTrue(appointmentsEnabled($registration));
        }
        $this->expectException(InvalidArgumentException::class);
        validateBusinessRegistrationInput($input + ['appointments_enabled' => 'free']);
    }

    public function testMonthlyPlanAndExactDecimalMoney(): void {
        validatePayPalMonthlyPlan(self::plan(), 'P-' . str_repeat('A', 24), 3900);
        self::assertTrue(paypalMoneyMatches(['currency_code' => 'USD', 'value' => '39.0'], 3900));
        self::assertFalse(paypalMoneyMatches(['currency_code' => 'USD', 'value' => '39.001'], 3900));
        self::assertFalse(paypalMoneyMatches(['currency_code' => 'USD', 'value' => '3.9e1'], 3900));
    }

    private static function plan(): array {
        return [
            'id' => 'P-' . str_repeat('A', 24), 'status' => 'ACTIVE',
            'billing_cycles' => [[
                'tenure_type' => 'REGULAR', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'total_cycles' => 0, 'pricing_scheme' => ['fixed_price' => ['currency_code' => 'USD', 'value' => '39.00']],
            ]],
        ];
    }

    public static function wrongPlans(): array {
        $plan = self::plan();
        return [
            'wrong price' => [array_replace_recursive($plan, ['billing_cycles' => [0 => ['pricing_scheme' => ['fixed_price' => ['value' => '64.00']]]]])],
            'wrong currency' => [array_replace_recursive($plan, ['billing_cycles' => [0 => ['pricing_scheme' => ['fixed_price' => ['currency_code' => 'EUR']]]]])],
            'yearly renewal' => [array_replace_recursive($plan, ['billing_cycles' => [0 => ['frequency' => ['interval_unit' => 'YEAR']]]])],
            'finite renewal' => [array_replace_recursive($plan, ['billing_cycles' => [0 => ['total_cycles' => 1]]])],
            'trial' => [array_replace_recursive($plan, ['billing_cycles' => [0 => ['tenure_type' => 'TRIAL']]])],
            'extra cycle' => [array_replace($plan, ['billing_cycles' => array_merge($plan['billing_cycles'], $plan['billing_cycles'])])],
            'setup fee' => [array_replace($plan, ['payment_preferences' => ['setup_fee' => ['currency_code' => 'USD', 'value' => '1.00']]])],
            'tax surcharge' => [array_replace($plan, ['taxes' => ['percentage' => '5']])],
            'inactive' => [array_replace($plan, ['status' => 'INACTIVE'])],
        ];
    }

    #[DataProvider('wrongPlans')]
    public function testRejectsDifferentBillingTerms(array $plan): void {
        $this->expectException(RuntimeException::class);
        validatePayPalMonthlyPlan($plan, 'P-' . str_repeat('A', 24), 3900);
    }

    public function testApprovalPendingRefundedAndWrongAmountsAreNotSuccessfulPayments(): void {
        $transaction = [
            'id' => 'PAYMENT1', 'status' => 'COMPLETED',
            'amount_with_breakdown' => ['gross_amount' => ['currency_code' => 'USD', 'value' => '39.00']],
        ];
        self::assertNull(paypalCompletedPayment(['transactions' => []], 3900));
        foreach (['PENDING', 'REFUNDED', 'PARTIALLY_REFUNDED', 'FAILED'] as $status) {
            self::assertNull(paypalCompletedPayment(['transactions' => [array_replace($transaction, ['status' => $status])]], 3900));
        }
        self::assertNull(paypalCompletedPayment(['transactions' => [$transaction]], 6400));
        self::assertSame('PAYMENT1', paypalCompletedPayment(['transactions' => [$transaction]], 3900));
    }

    public function testSubscriptionCannotBeBorrowedOrOverridden(): void {
        $signup = ['id' => 'signup-one', 'subscription_id' => 'I-ONE', 'plan_id' => 'P-ONE'];
        $subscription = ['id' => 'I-ONE', 'plan_id' => 'P-ONE', 'custom_id' => 'signup-one'];
        validatePayPalSubscription($subscription, $signup);
        $this->expectException(RuntimeException::class);
        validatePayPalSubscription(array_replace($subscription, ['custom_id' => 'signup-two']), $signup);
    }

    public function testSubscriptionPriceOverridesAreRejected(): void {
        $this->expectException(RuntimeException::class);
        validatePayPalSubscription(
            ['id' => 'I-ONE', 'plan_id' => 'P-ONE', 'custom_id' => 'signup-one', 'plan_overridden' => true],
            ['id' => 'signup-one', 'subscription_id' => 'I-ONE', 'plan_id' => 'P-ONE']
        );
    }

    public function testApprovalRedirectMustStayOnPayPal(): void {
        $original = getenv('CLIENTQ_PAYPAL_ENVIRONMENT');
        putenv('CLIENTQ_PAYPAL_ENVIRONMENT=sandbox');
        self::assertNull(paypalApprovalUrl(['links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com.attacker.test/']]]));
        self::assertNull(paypalApprovalUrl(['links' => [['rel' => 'approve', 'href' => 'http://www.sandbox.paypal.com/']]]));
        self::assertSame('https://www.sandbox.paypal.com/checkoutnow?token=I-ONE',
            paypalApprovalUrl(['links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=I-ONE']]]));
        putenv($original === false ? 'CLIENTQ_PAYPAL_ENVIRONMENT' : 'CLIENTQ_PAYPAL_ENVIRONMENT=' . $original);
    }
}
