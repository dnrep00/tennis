<?php
declare(strict_types=1);

// Direct requests must not expose server-side code or configuration.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const YB_ROOT = 'https://gjcenter.gjcs.or.kr';
const YB_CACHE_TTL = 15;
const YB_CACHE_PREFIX = "<?php exit; ?>\n";

function yb_valid_date($date): bool
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
    $parts = array_map('intval', explode('-', $date));
    return $date >= '2000-01-01' && $date <= '2100-12-31' && checkdate($parts[1], $parts[2], $parts[0]);
}

function yb_courts(): array
{
    $courts = [];
    for ($i = 1; $i <= 12; $i++) $courts[] = ['id' => (string)($i + 24), 'name' => $i . '번 코트'];
    return $courts;
}

function yb_source_url(string $date, string $id): string
{
    return YB_ROOT . '/fmcs/3?' . http_build_query([
        'facilities_type' => 'T', 'base_date' => str_replace('-', '', $date), 'rent_type' => '1001',
        'center' => 'GJCS03', 'part' => '10', 'place' => $id,
    ]);
}

function yb_classify(array $row, ?array $day): array
{
    $state = (string)($day['state_cd'] ?? '');
    $use = $row['use_yn'] ?? '';
    if ($state === '30') return ['status' => 'closed', 'label' => $day['state_nm'] ?? '휴관일'];
    if ($use === 'Y') return ['status' => 'booked', 'label' => '예약완료'];
    if ($use === 'E') return ['status' => 'unavailable', 'label' => '마감'];
    if ($use === 'U') return ['status' => 'unavailable', 'label' => '예약불가'];
    if (!$day || !in_array($state, ['10', '11', '15'], true)) {
        return ['status' => $day ? 'unavailable' : 'unknown', 'label' => $day['state_nm'] ?? '날짜 상태 미확인'];
    }
    if ($use === 'D' || $state === '15') return ['status' => 'lottery', 'label' => '추첨접수'];
    if ($use === 'N' && $state === '10') return ['status' => 'available', 'label' => '예약가능'];
    return ['status' => 'unknown', 'label' => $state === '11' ? ($day['state_nm'] ?? '접수방식 확인') : '상태 미확인'];
}

function yb_normalize(array $court, string $date, array $times, array $months): array
{
    $day = null;
    foreach ($months as $row) {
        if (is_array($row) && ($row['date'] ?? '') === $date && (string)($row['place_cd'] ?? '') === $court['id']) {
            $day = $row;
            break;
        }
    }
    $slots = [];
    foreach ($times as $row) {
        if (!is_array($row) || ($row['date'] ?? '') !== $date || (string)($row['place_cd'] ?? '') !== $court['id']) continue;
        if (!preg_match('/^\d{2}:\d{2}$/D', (string)($row['start_time'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/D', (string)($row['end_time'] ?? ''))) continue;
        // Explicit allowlist: never forward reservation holder / team details.
        $slots[] = array_merge([
            'start' => $row['start_time'], 'end' => $row['end_time'], 'name' => $row['time_nm'] ?? '',
            'amount' => isset($row['rent_amt']) && is_numeric($row['rent_amt']) ? (float)$row['rent_amt'] : null,
        ], yb_classify($row, $day));
    }
    return array_merge($court, [
        'url' => yb_source_url($date, $court['id']), 'dayLabel' => $day['state_nm'] ?? '날짜 상태 미확인',
        'dayStatus' => (string)($day['state_cd'] ?? '') === '30' ? 'closed' : ($day ? 'unavailable' : 'unknown'),
        'slots' => $slots, 'error' => null,
    ]);
}

function yb_failed(array $court, string $date, string $message): array
{
    return array_merge($court, [
        'url' => yb_source_url($date, $court['id']), 'dayLabel' => '조회 실패',
        'dayStatus' => 'unknown', 'slots' => [], 'error' => $message,
    ]);
}

function yb_curl_error(int $code): string
{
    if ($code === 28) return '예약 사이트 연결 시간이 초과되었습니다 (CURL_28).';
    if (in_array($code, [51, 58, 60, 77, 83], true)) return '예약 사이트의 TLS 인증서를 검증하지 못했습니다 (CURL_' . $code . ').';
    if ($code === 6) return '예약 사이트의 서버 주소를 찾지 못했습니다 (CURL_6).';
    if ($code === 7) return '예약 사이트에 연결할 수 없습니다 (CURL_7).';
    return '예약 사이트 통신에 실패했습니다 (CURL_' . $code . ').';
}

// Three courts in parallel, two read-only requests per court. Total scan budget: 20s.
function yb_fetch_batch(array $courts, string $date, float $deadline): array
{
    $multi = curl_multi_init();
    $jobs = [];
    try {
        foreach ($courts as $court) {
            foreach (['times' => 'place_time_state_list', 'months' => 'place_month_state_list'] as $kind => $endpoint) {
                $url = YB_ROOT . '/rest/facilities/' . $endpoint . '?' . http_build_query([
                    'company_code' => 'GJCS03', 'part_code' => '10', 'place_code' => $court['id'],
                    'base_date' => str_replace('-', '', $date), 'rent_type' => '1001', 'mem_no' => '',
                ]);
                $remaining = max(1, (int)(($deadline - microtime(true)) * 1000));
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT_MS => min(4000, $remaining), CURLOPT_TIMEOUT_MS => min(6000, $remaining),
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'YangbeolAvailability/1.0',
                    CURLOPT_ENCODING => '',
                ]);
                $body = '';
                // Stop oversized / unexpected responses without retaining arbitrary remote data.
                $job = (object)['ch' => $ch, 'court' => $court['id'], 'kind' => $kind, 'body' => $body];
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($job): int {
                    if (strlen($job->body) + strlen($chunk) > 1048576) return 0;
                    $job->body .= $chunk;
                    return strlen($chunk);
                });
                $jobs[] = $job;
                curl_multi_add_handle($multi, $ch);
            }
        }
        do {
            do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
            if ($status !== CURLM_OK) throw new RuntimeException('예약 조회 통신을 시작하지 못했습니다.');
            if (!$running || microtime(true) >= $deadline) break;
            if (curl_multi_select($multi, 0.2) === -1) usleep(10000);
        } while ($running);

        $result = [];
        foreach ($jobs as $job) {
            $code = curl_errno($job->ch);
            $http = (int)curl_getinfo($job->ch, CURLINFO_HTTP_CODE);
            $data = null;
            $error = null;
            if ($code) $error = yb_curl_error($code);
            elseif (!$http) $error = '예약 조회 전체 제한 시간(20초)을 초과했습니다.';
            elseif ($http !== 200) $error = '예약 사이트 응답 오류 (HTTP ' . $http . ').';
            else {
                $data = json_decode($job->body, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || array_values($data) !== $data) {
                    $error = '예약 사이트 응답 형식이 변경되었거나 JSON이 아닙니다.';
                }
            }
            if ($error) error_log('[reservation-upstream] ' . json_encode([
                'date' => $date, 'court' => $job->court, 'endpoint' => $job->kind,
                'curlCode' => $code, 'httpStatus' => $http, 'cause' => curl_error($job->ch),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $result[$job->court][$job->kind] = ['data' => $data, 'error' => $error];
        }
        return $result;
    } finally {
        foreach ($jobs as $job) {
            curl_multi_remove_handle($multi, $job->ch);
            curl_close($job->ch);
        }
        curl_multi_close($multi);
    }
}

function yb_scan(string $date, ?callable $batchLoader = null): array
{
    $deadline = microtime(true) + 20;
    $courts = [];
    $loader = $batchLoader ?? 'yb_fetch_batch';
    foreach (array_chunk(yb_courts(), 3) as $batch) {
        if (microtime(true) >= $deadline) {
            foreach ($batch as $court) $courts[] = yb_failed($court, $date, '예약 조회 전체 제한 시간(20초)을 초과했습니다.');
            continue;
        }
        $results = $loader($batch, $date, $deadline);
        foreach ($batch as $court) {
            $pair = $results[$court['id']] ?? [];
            $error = ($pair['times']['error'] ?? null) ?: ($pair['months']['error'] ?? null);
            if (!$error && (!isset($pair['times']['data']) || !isset($pair['months']['data']))) $error = '예약 사이트 응답을 확인하지 못했습니다.';
            $courts[] = $error ? yb_failed($court, $date, $error) : yb_normalize($court, $date, $pair['times']['data'], $pair['months']['data']);
        }
        // A complete first-batch connection failure should not trigger another 18 requests.
        if (count($courts) === 3 && count(array_filter($courts, static function ($c) { return $c['error'] !== null; })) === 3) {
            foreach (array_slice(yb_courts(), 3) as $court) $courts[] = yb_failed($court, $date, $courts[0]['error']);
            break;
        }
    }
    if (count(array_filter($courts, static function ($c) { return $c['error'] === null; })) === 0) throw new RuntimeException($courts[0]['error']);
    return ['date' => $date, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'courts' => $courts];
}

function yb_cache_dir(): string
{
    $suffix = 'yangbeol-' . substr(hash('sha256', __DIR__), 0, 16);
    foreach ([sys_get_temp_dir() . DIRECTORY_SEPARATOR . $suffix, __DIR__ . DIRECTORY_SEPARATOR . 'cache'] as $dir) {
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) return $dir;
    }
    throw new RuntimeException('캐시 폴더를 만들 수 없습니다. 호스팅의 임시 폴더 쓰기 권한을 확인해 주세요.');
}

function yb_read_cache(string $file, string $date): ?array
{
    if (!is_file($file)) return null;
    $text = @file_get_contents($file);
    if (!is_string($text) || strpos($text, YB_CACHE_PREFIX) !== 0) return null;
    $entry = json_decode(substr($text, strlen(YB_CACHE_PREFIX)), true);
    return is_array($entry) && ($entry['expires'] ?? 0) > time() && ($entry['data']['date'] ?? '') === $date ? $entry['data'] : null;
}

function yb_reservations(string $date): array
{
    if (!yb_valid_date($date)) throw new InvalidArgumentException('유효한 날짜를 YYYY-MM-DD 형식으로 입력해 주세요.');
    if (!extension_loaded('curl') || !function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL 확장이 필요합니다. 닷홈의 PHP cURL 지원 상태를 확인해 주세요.');
    $dir = yb_cache_dir();
    $file = $dir . DIRECTORY_SEPARATOR . $date . '.php';
    $cached = yb_read_cache($file, $date);
    if ($cached !== null) return $cached;
    $lock = @fopen($dir . DIRECTORY_SEPARATOR . 'scan.lock', 'c');
    if (!$lock) throw new RuntimeException('조회 잠금 파일을 만들 수 없습니다. 캐시 폴더 권한을 확인해 주세요.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('다른 예약 조회를 처리 중입니다. 잠시 후 다시 조회해 주세요.', 503);
    }
    try {
        $cached = yb_read_cache($file, $date);
        if ($cached !== null) return $cached;
        $payload = yb_scan($date);
        $complete = count(array_filter($payload['courts'], static function ($c) { return $c['error'] !== null; })) === 0;
        if ($complete) {
            // Files have an executable PHP exit prefix, including the web-root fallback cache.
            $entry = YB_CACHE_PREFIX . json_encode(['expires' => time() + YB_CACHE_TTL, 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $temp = tempnam($dir, 'yb-');
            if ($temp !== false) {
                if (file_put_contents($temp, $entry, LOCK_EX) !== false) {
                    @chmod($temp, 0600);
                    if (!@rename($temp, $file)) @unlink($temp);
                } else @unlink($temp);
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '????-??-??.php') ?: [] as $old) {
                if ($old !== $file && filemtime($old) < time() - 60) @unlink($old);
            }
        }
        return $payload;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
