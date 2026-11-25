<?php

require_once __DIR__ . '/logo-upload.php';
require_once dirname(__DIR__) . '/src/php/paypal.php';
require_once dirname(__DIR__) . '/src/php/stripe-billing.php';

function signupPaymentRecord(): ?array {
    $paypal = paypalSignupRecord();
    $stripe = stripeSignupRecord();
    if ($paypal !== null && $stripe !== null) {
        throw new RuntimeException('Multiple payment providers are associated with this signup.');
    }
    if ($paypal !== null) {
        return $paypal + ['provider' => 'paypal'];
    }
    return $stripe === null ? null : $stripe + ['provider' => 'stripe'];
}

function signupPaymentTable(array $signup): string {
    return match ($signup['provider']) {
        'paypal' => 'paypal_signup_subscriptions',
        'stripe' => 'stripe_signup_subscriptions',
        default => throw new RuntimeException('Unknown signup payment provider.'),
    };
}

function validateBusinessRegistrationInput(array $input): array {
    $businessName = trim($input['business_name'] ?? '');
    $slug = strtolower(trim($input['slug'] ?? ''));
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $phone = preg_replace('/\D/', '', (string)($input['phone'] ?? ''));
    $ownerName = trim($firstName . ' ' . $lastName);
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $plan = strtolower(trim($input['plan'] ?? 'growth'));
    $reservedSlugs = ['admin', 'api', 'app', 'mail', 'support', 'www'];
    $availablePlans = ['starter', 'growth', 'pro'];

    if (!in_array($plan, $availablePlans, true)) {
        throw new InvalidArgumentException('Select a valid ClientQ plan.');
    }
    $appointments = $input['appointments_enabled'] ?? false;
    if (!in_array($appointments, [false, true, 0, 1, '0', '1'], true)) {
        throw new InvalidArgumentException('Choose a valid appointment scheduling option.');
    }
    if ($businessName === '' || strlen($businessName) > 120) {
        throw new InvalidArgumentException('Enter a business name up to 120 characters.');
    }
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
        throw new InvalidArgumentException('The address must use lowercase letters, numbers, and single hyphens.');
    }
    if (in_array($slug, $reservedSlugs, true)) {
        throw new InvalidArgumentException('That ClientQ address is reserved.');
    }
    if ($firstName === '' || strlen($firstName) > 50) {
        throw new InvalidArgumentException('Enter a first name up to 50 characters.');
    }
    if ($lastName === '' || strlen($lastName) > 50) {
        throw new InvalidArgumentException('Enter a last name up to 50 characters.');
    }
    if (strlen($ownerName) > 100) {
        throw new InvalidArgumentException('First and last name must total no more than 100 characters.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (strlen($phone) !== 10) {
        throw new InvalidArgumentException('Enter a valid 10-digit phone number.');
    }
    if (strlen($password) < 8 || strlen($password) > 255) {
        throw new InvalidArgumentException('Password must be between 8 and 255 characters.');
    }

    return [
        'business_name' => $businessName,
        'slug' => $slug,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'owner_name' => $ownerName,
        'email' => $email,
        'phone' => $phone,
        'password' => $password,
        'plan' => $plan,
        'appointments_enabled' => $plan !== 'starter' || (bool)$appointments,
    ];
}

function pendingBusinessRegistration(): ?array {
    $signup = signupPaymentRecord();
    if ($signup !== null && $signup['pending_registration'] !== null) {
        return json_decode($signup['pending_registration'], true, 512, JSON_THROW_ON_ERROR);
    }
    $pending = $_SESSION['pending_business_registration'] ?? null;
    if (!is_array($pending) || (int)($pending['created_at'] ?? 0) < time() - 1800) {
        clearPendingBusinessRegistration();
        return null;
    }

    return $pending;
}

function pendingBusinessLogoDirectory(): string {
    return getenv('CLIENTQ_PENDING_UPLOAD_DIR') ?: sys_get_temp_dir() . '/clientq-pending-uploads';
}

function pendingBusinessLogoPath(array $logo): ?string {
    $filename = $logo['filename'] ?? null;
    if (!is_string($filename) || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $filename)) {
        return null;
    }

    return rtrim(pendingBusinessLogoDirectory(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $filename;
}

function cleanupExpiredPendingBusinessLogos(): void {
    $directory = pendingBusinessLogoDirectory();
    if (!is_dir($directory)) {
        return;
    }

    $cutoff = time() - 3600;
    $reserved = getDB()->query(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(pending_registration, '$.logo.filename'))
         FROM paypal_signup_subscriptions WHERE pending_registration IS NOT NULL
         UNION ALL
         SELECT JSON_UNQUOTE(JSON_EXTRACT(pending_registration, '$.logo.filename'))
         FROM stripe_signup_subscriptions WHERE pending_registration IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile()
            || !preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $file->getFilename())
            || $file->getMTime() >= $cutoff
            || in_array($file->getFilename(), $reserved, true)) {
            continue;
        }
        unlink($file->getPathname());
    }
}

function stagePendingBusinessLogo(array $file): array {
    $validated = validateLogoUpload($file);
    $directory = pendingBusinessLogoDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('The logo could not be staged.');
    }

    cleanupExpiredPendingBusinessLogos();
    $filename = bin2hex(random_bytes(16)) . '.' . $validated['extension'];
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($validated['temporary_path'], $path)) {
        throw new RuntimeException('The logo could not be staged.');
    }

    return [
        'filename' => $filename,
        'mime' => $validated['mime'],
        'extension' => $validated['extension'],
        'size' => $validated['size'],
    ];
}

function clearPendingBusinessRegistration(): void {
    $signup = signupPaymentRecord();
    if ($signup !== null && $signup['pending_registration'] !== null) {
        return;
    }
    $pending = $_SESSION['pending_business_registration'] ?? null;
    $logo = is_array($pending) ? ($pending['logo'] ?? null) : null;
    if (is_array($logo)) {
        $path = pendingBusinessLogoPath($logo);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }

    unset($_SESSION['pending_business_registration']);
}
