<?php
require 'includes/db.php';
require 'includes/product_media.php';

function output_product_image_binary(string $binaryData, string $mimeType): void
{
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string)strlen($binaryData));
    header('Cache-Control: private, max-age=0, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $binaryData;
}

$requestedProductId = (int)($_GET['id'] ?? 0);
if ($requestedProductId > 0) {
    $sql = product_image_database_storage_supported($conn)
        ? 'SELECT product_image, product_image_mime, product_image_data FROM products WHERE product_id = ?'
        : 'SELECT product_image FROM products WHERE product_id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $requestedProductId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if ($product !== null && product_image_has_database_image($product)) {
        $binaryData = base64_decode((string)$product['product_image_data'], true);
        $mimeType = trim((string)($product['product_image_mime'] ?? ''));
        if ($binaryData !== false && $mimeType !== '') {
            output_product_image_binary($binaryData, $mimeType);
            exit;
        }
    }

    if ($product !== null) {
        $storedName = product_image_stored_name((string)($product['product_image'] ?? ''));
        $absolutePath = product_image_absolute_path($storedName);
        if ($storedName !== null && $absolutePath !== null) {
            $requestedFile = $storedName;
        }
    }
}

$requestedFile = $requestedFile ?? ($_GET['file'] ?? '');
$storedName = product_image_stored_name(is_string($requestedFile) ? $requestedFile : '');
$absolutePath = product_image_absolute_path($storedName);

if ($storedName === null || $absolutePath === null) {
    http_response_code(404);
    exit('Image not found.');
}

$mimeType = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($absolutePath);
    if ($detectedMime !== '') {
        $mimeType = $detectedMime;
    }
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($absolutePath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
