<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'TCPDF' . DIRECTORY_SEPARATOR . 'qrcode.php';

requireMethod('GET');

try {
    $payload = publicVisitorService()->qrPayloadForToken((string) ($_GET['token'] ?? ''));
    $qr = new QRcode($payload, 'M');
    $matrix = $qr->getBarcodeArray();
    $rows = $matrix['bcode'] ?? [];
    $width = (int) ($matrix['num_cols'] ?? count($rows));
    $quiet = 4;
    $size = $width + ($quiet * 2);
    $rects = '';
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < $width; $x++) {
            if (!empty($row[$x])) {
                $rects .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="1" height="1"/>';
            }
        }
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img"><title>Visitor pass QR code</title><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
    exit;
} catch (Throwable $e) {
    publicVisitorHandle($e);
}

