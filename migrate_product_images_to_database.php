<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/product_media.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this migration from the command line.\n");
}

if (!product_image_database_storage_supported($conn)) {
    exit("Database image storage columns are missing. Run sql/add_product_image_storage_columns.sql first.\n");
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
$products = $conn->query("
    SELECT product_id, product_image
    FROM products
    WHERE product_image <> '" . $conn->real_escape_string(PRODUCT_IMAGE_DB_PLACEHOLDER) . "'
      AND (product_image_data IS NULL OR product_image_data = '')
    ORDER BY product_id ASC
");

if (!$products) {
    exit("Could not read product records for migration.\n");
}

$update = $conn->prepare('UPDATE products SET product_image = ?, product_image_mime = ?, product_image_data = ? WHERE product_id = ?');
$migratedCount = 0;
$skippedCount = 0;
$placeholderImage = PRODUCT_IMAGE_DB_PLACEHOLDER;

while ($product = $products->fetch_assoc()) {
    $productId = (int)$product['product_id'];
    $legacyFileName = (string)($product['product_image'] ?? '');
    $absolutePath = product_image_absolute_path($legacyFileName);

    if ($absolutePath === null) {
        $skippedCount++;
        echo "Skipped product {$productId}: image file not found.\n";
        continue;
    }

    $binaryData = @file_get_contents($absolutePath);
    if (!is_string($binaryData) || $binaryData === '') {
        $skippedCount++;
        echo "Skipped product {$productId}: could not read image file.\n";
        continue;
    }

    $mimeType = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)$finfo->file($absolutePath);
    }
    if ($mimeType === '') {
        $imageInfo = @getimagesize($absolutePath);
        $mimeType = (string)($imageInfo['mime'] ?? '');
    }
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        $skippedCount++;
        echo "Skipped product {$productId}: unsupported mime type.\n";
        continue;
    }

    $encodedData = base64_encode($binaryData);
    $update->bind_param('sssi', $placeholderImage, $mimeType, $encodedData, $productId);
    if (!$update->execute()) {
        $skippedCount++;
        echo "Skipped product {$productId}: database update failed.\n";
        continue;
    }

    $migratedCount++;
    echo "Migrated product {$productId}.\n";
}

echo "Migration complete. Migrated: {$migratedCount}. Skipped: {$skippedCount}.\n";
