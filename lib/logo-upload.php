<?php

function validateLogoUpload(array $file): array {
    return validateImageUpload($file, 'Logo');
}

function validateImageUpload(array $file, string $label): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Select a ' . strtolower($label) . ' to upload.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 2 * 1024 * 1024) {
        throw new InvalidArgumentException($label . ' must be no larger than 2 MB.');
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    $mime = is_string($temporaryPath) && $temporaryPath !== ''
        ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath)
        : false;
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!is_string($mime)
        || !isset($extensions[$mime])
        || getimagesize($temporaryPath) === false) {
        throw new InvalidArgumentException($label . ' must be a valid PNG, JPEG, or WebP image.');
    }

    return [
        'temporary_path' => $temporaryPath,
        'mime' => $mime,
        'extension' => $extensions[$mime],
        'size' => (int)$file['size'],
    ];
}
