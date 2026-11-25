<?php

final class PayPalSignupTestServer {
    public string $baseUrl;
    public string $signupIp;
    private string $stateFile;
    private array $processes = [];
    private array $logs = [];
    private bool $phonePoolSeeded = false;

    public function __construct(bool $withStripe = false) {
        $this->signupIp = '192.0.2.' . random_int(1, 254);
        $this->stateFile = tempnam(sys_get_temp_dir(), 'clientq-paypal-fixture-');
        $this->writeState(['subscriptions' => [], 'transactions' => [], 'requests' => [], 'idempotency' => [],
            'stripe_sessions' => [], 'stripe_requests' => [], 'stripe_idempotency' => []]);
        try {
            if (DB_HOST !== 'db' || getenv('CLIENTQ_RUN_INTEGRATION_TESTS') !== '1') {
                throw new RuntimeException('Signup fixtures require the local integration database.');
            }
            if ((int)getDB()->query('SELECT COUNT(*) FROM phone_groups WHERE phone_number IS NOT NULL')->fetchColumn() === 0) {
                $seed = getDB()->prepare("UPDATE phone_groups SET phone_number = ? WHERE group_code = 'A' AND phone_number IS NULL");
                $seed->execute(['+12025550199']);
                if ($seed->rowCount() !== 1) {
                    throw new RuntimeException('Could not configure the local fixture phone pool.');
                }
                $this->phonePoolSeeded = true;
            }
            $fixture = $this->start(dirname(__DIR__) . '/fixtures/paypal-router.php', [
                'CLIENTQ_TEST_PAYPAL_STATE' => $this->stateFile,
            ]);
            $environment = [
                'CLIENTQ_RUN_INTEGRATION_TESTS' => '1', 'CLIENTQ_TEST_PAYPAL_API_BASE' => $fixture,
                'CLIENTQ_PAYPAL_ENVIRONMENT' => 'sandbox', 'CLIENTQ_PAYPAL_SANDBOX_CLIENT_ID' => 'fixture-client',
                'CLIENTQ_PAYPAL_SANDBOX_CLIENT_SECRET' => 'fixture-secret',
                'CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS' => 'P-' . str_repeat('A', 24),
                'CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS_APPOINTMENTS' => 'P-' . str_repeat('D', 24),
                'CLIENTQ_PAYPAL_SANDBOX_PLAN_SELECT' => 'P-' . str_repeat('B', 24),
                'CLIENTQ_PAYPAL_SANDBOX_PLAN_PREMIER' => 'P-' . str_repeat('C', 24),
                'CLIENTQ_TEST_SIGNUP_IP' => $this->signupIp,
                'CLIENTQ_SIGNUPS_PER_HOUR' => '100',
                'CLIENTQ_STRIPE_SECRET_KEY' => '',
                'CLIENTQ_STRIPE_WEBHOOK_SECRET' => '',
                'CLIENTQ_TWILIO_ACCOUNT_SID' => '',
                'CLIENTQ_TWILIO_AUTH_TOKEN' => '',
                'CLIENTQ_SMTP_HOST' => '',
                'CLIENTQ_SMTP_USER' => '',
                'CLIENTQ_SMTP_PASS' => '',
            ];
            if ($withStripe) {
                $stripeFixture = $this->start(dirname(__DIR__) . '/fixtures/stripe-billing-router.php', [
                    'CLIENTQ_TEST_STRIPE_STATE' => $this->stateFile,
                ]);
                $environment['CLIENTQ_TEST_STRIPE_API_BASE'] = $stripeFixture . '/v1';
                $environment['CLIENTQ_STRIPE_SECRET_KEY'] = 'sk_test_fixture';
                $environment['CLIENTQ_STRIPE_WEBHOOK_SECRET'] = 'whsec_fixture';
            }
            $this->baseUrl = $this->start(dirname(__DIR__) . '/fixtures/app-router.php', $environment);
        } catch (Throwable $e) {
            $this->stop();
            throw $e;
        }
    }

    private function start(string $router, array $environment): string {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not allocate fixture port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $log = tempnam(sys_get_temp_dir(), 'clientq-paypal-server-log-');
        $this->logs[] = $log;
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a'],
        ], $pipes, dirname(__DIR__, 2), array_replace(getenv(), $environment));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start fixture server.');
        }
        $this->processes[] = $process;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return 'http://' . $address;
            }
            usleep(20000);
        }
        throw new RuntimeException('Fixture server failed to start: ' . file_get_contents($log));
    }

    public function state(): array {
        return json_decode(file_get_contents($this->stateFile), true, 512, JSON_THROW_ON_ERROR);
    }

    public function writeState(array $state): void {
        if (file_put_contents($this->stateFile, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Could not write fixture state.');
        }
    }

    public function approve(string $subscriptionId, string $status = 'COMPLETED', string $amount = '39.00'): void {
        $state = $this->state();
        $state['subscriptions'][$subscriptionId]['status'] = 'ACTIVE';
        $state['transactions'][$subscriptionId] = [[
            'id' => 'PAY-' . bin2hex(random_bytes(6)), 'status' => $status, 'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'amount_with_breakdown' => ['gross_amount' => ['currency_code' => 'USD', 'value' => $amount]],
        ]];
        $this->writeState($state);
    }

    public function stop(): void {
        foreach (array_reverse($this->processes) as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->processes = [];
        foreach (array_merge($this->logs, [$this->stateFile]) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->phonePoolSeeded) {
            $restore = getDB()->prepare("UPDATE phone_groups SET phone_number = NULL WHERE group_code = 'A' AND phone_number = ?");
            $restore->execute(['+12025550199']);
            $this->phonePoolSeeded = false;
        }
    }

    public function approveCard(string $sessionId): void {
        $state = $this->state();
        $session = &$state['stripe_sessions'][$sessionId];
        $amount = $session['amount_total'];
        $id = substr($sessionId, strlen('cs_test_'));
        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['customer'] = 'cus_' . $id;
        $session['subscription'] = [
            'id' => 'sub_' . $id, 'customer' => 'cus_' . $id, 'status' => 'active',
            'metadata' => $session['metadata'], 'trial_end' => null,
            'items' => ['has_more' => false, 'data' => [[
                'quantity' => 1, 'price' => ['unit_amount' => $amount, 'currency' => 'usd',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']],
            ]]],
        ];
        $session['invoice'] = [
            'id' => 'in_' . $id, 'subscription' => 'sub_' . $id, 'customer' => 'cus_' . $id,
            'billing_reason' => 'subscription_create', 'status' => 'paid', 'paid' => true,
            'currency' => 'usd', 'subtotal' => $amount, 'total' => $amount, 'amount_paid' => $amount,
            'amount_due' => $amount, 'amount_remaining' => 0,
            'payment_intent' => [
                'id' => 'pi_' . $id, 'status' => 'succeeded', 'currency' => 'usd',
                'amount' => $amount, 'amount_received' => $amount, 'latest_charge' => [
                    'payment_intent' => 'pi_' . $id, 'paid' => true, 'captured' => true,
                    'refunded' => false, 'amount_refunded' => 0, 'disputed' => false,
                    'currency' => 'usd', 'amount' => $amount,
                ],
            ],
        ];
        $this->writeState($state);
    }
}
