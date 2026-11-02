<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        respond(405, ['error' => 'GET 요청만 지원합니다.']);
        exit;
    }
    require_once dirname(__DIR__) . '/lib/reservations.php';
    $date = $_GET['date'] ?? null;
    if (!yb_valid_date($date)) {
        respond(400, ['error' => '유효한 날짜를 YYYY-MM-DD 형식으로 입력해 주세요.']);
        exit;
    }
    respond(200, yb_reservations($date));
} catch (Throwable $error) {
    $status = $error->getCode() === 503 ? 503 : 502;
    if ($status === 503) header('Retry-After: 3');
    error_log('[reservation-api] ' . $error->getMessage());
    respond($status, ['error' => $error->getMessage()]);
}
