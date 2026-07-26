<?php

const PRODUCT_IMAGE_DB_PLACEHOLDER = 'placeholder.jpg';
const PRODUCT_IMAGE_MAX_BYTES = 2097152;
const PRODUCT_IMAGE_UPLOAD_DIR = __DIR__ . '/../uploads/products/';
const PRODUCT_IMAGE_UPLOAD_WEB_PATH = 'uploads/products/';
const PRODUCT_IMAGE_PLACEHOLDER_ASSET = 'assets/images/product-placeholder.svg';
const PRODUCT_IMAGE_ROUTE_PATH = 'product_image.php';

function product_image_is_placeholder(?string $fileName): bool
{
    $fileName = trim((string)$fileName);

    return $fileName === '' || $fileName === PRODUCT_IMAGE_DB_PLACEHOLDER;
}

function product_image_stored_name(?string $fileName): ?string
{
    $fileName = trim((string)$fileName);
    if (product_image_is_placeholder($fileName)) {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $fileName)) {
        return null;
    }

    return basename($fileName);
}

function product_image_fallback_dir(): string
{
    $baseDirectory = sys_get_temp_dir();
    if (!is_string($baseDirectory) || trim($baseDirectory) === '') {
        $baseDirectory = '/tmp';
    }

    return rtrim($baseDirectory, '/\\') . '/cultureconnect_products/';
}

function product_image_database_storage_supported(mysqli $conn): bool
{
    static $supported = null;

    if ($supported !== null) {
        return $supported;
    }

    $result = $conn->query("
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'products'
          AND COLUMN_NAME IN ('product_image_mime', 'product_image_data')
    ");
    $row = $result ? $result->fetch_assoc() : null;

    $supported = (int)($row['column_count'] ?? 0) === 2;

    return $supported;
}

function product_image_lookup_directories(): array
{
    return [
        PRODUCT_IMAGE_UPLOAD_DIR,
        product_image_fallback_dir(),
    ];
}

function ensure_product_directory_writable(string $directory): bool
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    @chmod($directory, 0775);
    if (!is_writable($directory)) {
        @chmod($directory, 0777);
    }

    if (!is_writable($directory)) {
        return false;
    }

    $permissionProbe = @tempnam($directory, 'cc_upload_');
    if ($permissionProbe === false) {
        return false;
    }

    @unlink($permissionProbe);

    return true;
}

function ensure_product_upload_directory(): bool
{
    return ensure_product_directory_writable(PRODUCT_IMAGE_UPLOAD_DIR);
}

function resolve_product_image_storage_directory(): ?string
{
    // Only write new uploads to the persistent project directory.
    if (ensure_product_directory_writable(PRODUCT_IMAGE_UPLOAD_DIR)) {
        return PRODUCT_IMAGE_UPLOAD_DIR;
    }

    return null;
}

function product_image_absolute_path(?string $fileName): ?string
{
    $storedName = product_image_stored_name($fileName);
    if ($storedName === null) {
        return null;
    }

    foreach (product_image_lookup_directories() as $directory) {
        $absolutePath = $directory . $storedName;
        if (is_file($absolutePath)) {
            return $absolutePath;
        }
    }

    return null;
}

function product_image_has_database_image(array $product): bool
{
    if (array_key_exists('product_image_has_data', $product)) {
        return (int)$product['product_image_has_data'] === 1;
    }

    $mimeType = trim((string)($product['product_image_mime'] ?? ''));
    $encodedData = trim((string)($product['product_image_data'] ?? ''));

    return $mimeType !== '' && $encodedData !== '';
}

function product_image_has_custom_image(array $product): bool
{
    if (product_image_has_database_image($product)) {
        return true;
    }

    return product_image_absolute_path((string)($product['product_image'] ?? '')) !== null;
}

function product_image_url($productOrFileName): string
{
    if (is_array($productOrFileName)) {
        if (product_image_has_database_image($productOrFileName)) {
            $productId = (int)($productOrFileName['product_id'] ?? 0);
            if ($productId > 0) {
                return PRODUCT_IMAGE_ROUTE_PATH . '?id=' . rawurlencode((string)$productId);
            }
        }

        $productOrFileName = $productOrFileName['product_image'] ?? null;
    }

    $storedName = product_image_stored_name(is_string($productOrFileName) ? $productOrFileName : null);
    if ($storedName === null) {
        return PRODUCT_IMAGE_PLACEHOLDER_ASSET;
    }

    if (product_image_absolute_path($storedName) === null) {
        return PRODUCT_IMAGE_PLACEHOLDER_ASSET;
    }

    return PRODUCT_IMAGE_ROUTE_PATH . '?file=' . rawurlencode($storedName);
}

function delete_product_image_file(?string $fileName): void
{
    $storedName = product_image_stored_name($fileName);
    if ($storedName === null) {
        return;
    }

    foreach (product_image_lookup_directories() as $directory) {
        $absolutePath = $directory . $storedName;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

function store_uploaded_product_image(array $file, ?string &$errorMessage = null): ?string
{
    $errorMessage = null;
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return PRODUCT_IMAGE_DB_PLACEHOLDER;
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $errorMessage = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large. Please upload an image smaller than 2MB.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by a server extension.',
            default => 'Image upload failed. Please try again.',
        };

        return null;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errorMessage = 'Please choose a valid image file to upload.';
        return null;
    }

    $fileSize = (int)($file['size'] ?? 0);
    if ($fileSize <= 0) {
        $errorMessage = 'Please choose a valid image file to upload.';
        return null;
    }
    if ($fileSize > PRODUCT_IMAGE_MAX_BYTES) {
        $errorMessage = 'File too large. Please upload an image smaller than 2MB.';
        return null;
    }

    $originalExtension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($originalExtension, $allowedExtensions, true)) {
        $errorMessage = 'Only JPEG, PNG, and GIF images are allowed.';
        return null;
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        $errorMessage = 'Please upload a genuine image file (JPEG, PNG, or GIF).';
        return null;
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

    $detectedMime = (string)($imageInfo['mime'] ?? '');
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpName);
    }

    if (!isset($allowedMimeTypes[$detectedMime])) {
        $errorMessage = 'Please upload a genuine image file (JPEG, PNG, or GIF).';
        return null;
    }

    $storageDirectory = resolve_product_image_storage_directory();
    if ($storageDirectory === null) {
        $errorMessage = 'The product image folder is not writable. Please make uploads/products writable for the web server and try again.';
        return null;
    }

    $storedName = uniqid('product_', true) . '.' . $allowedMimeTypes[$detectedMime];
    $destination = $storageDirectory . $storedName;

    if (!move_uploaded_file($tmpName, $destination)) {
        $errorMessage = 'Image upload failed while saving the file. Please try again.';
        return null;
    }

    @chmod($destination, 0644);

    return $storedName;
}

function prepare_uploaded_product_image_data(array $file, ?string &$errorMessage = null): ?array
{
    $errorMessage = null;
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        $errorMessage = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large. Please upload an image smaller than 2MB.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a valid image file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by a server extension.',
            default => 'Image upload failed. Please try again.',
        };
        return null;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $fileSize = (int)($file['size'] ?? 0);
    $originalExtension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errorMessage = 'Please choose a valid image file to upload.';
        return null;
    }
    if ($fileSize <= 0) {
        $errorMessage = 'Please choose a valid image file to upload.';
        return null;
    }
    if ($fileSize > PRODUCT_IMAGE_MAX_BYTES) {
        $errorMessage = 'File too large. Please upload an image smaller than 2MB.';
        return null;
    }
    if (!in_array($originalExtension, $allowedExtensions, true)) {
        $errorMessage = 'Only JPEG, PNG, and GIF images are allowed.';
        return null;
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        $errorMessage = 'Please upload a genuine image file (JPEG, PNG, or GIF).';
        return null;
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

    $detectedMime = (string)($imageInfo['mime'] ?? '');
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpName);
    }

    if (!isset($allowedMimeTypes[$detectedMime])) {
        $errorMessage = 'Please upload a genuine image file (JPEG, PNG, or GIF).';
        return null;
    }

    $binaryData = @file_get_contents($tmpName);
    if (!is_string($binaryData) || $binaryData === '') {
        $errorMessage = 'Image upload failed while reading the file. Please try again.';
        return null;
    }

    return [
        'mime' => $detectedMime,
        'data' => base64_encode($binaryData),
    ];
}
