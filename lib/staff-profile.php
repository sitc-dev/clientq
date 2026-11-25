<?php

const STAFF_STATE_CODES = [
    'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
    'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
    'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
    'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
    'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC',
];

function staffProfilePhotoUrl(array $staff, string $tenantSlug): ?string {
    $path = $staff['profile_pic'] ?? null;
    $prefix = 'uploads/' . $tenantSlug . '/profile-' . (int)$staff['id'] . '-';
    return is_string($path) && preg_match('~^' . preg_quote($prefix, '~') . '[a-f0-9]{24}\.(jpg|png|webp)$~D', $path)
        ? '/' . $path : null;
}

/** @return array{first_name: string, last_name: string} */
function staffNameParts(array $staff): array {
    $name = trim($staff['name']);
    $first = trim($staff['first_name'] ?? '');
    $last = trim($staff['last_name'] ?? '');
    if ($first !== '' && trim($first . ' ' . $last) === $name) {
        return ['first_name' => $first, 'last_name' => $last];
    }

    $parts = preg_split('/\s+/', $name, 2);
    return ['first_name' => $parts[0], 'last_name' => $parts[1] ?? ''];
}
