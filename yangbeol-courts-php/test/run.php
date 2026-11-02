<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/reservations.php';
$count = 0;
function check(bool $ok, string $name): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $count++;
    echo 'PASS: ' . $name . PHP_EOL;
}
check(yb_valid_date('2028-02-29') && !yb_valid_date('2026-02-30') && !yb_valid_date(['2026-11-05']) && !yb_valid_date('2026-1-01'), 'date validation');
check(yb_classify(['use_yn' => 'N'], ['state_cd' => '10'])['status'] === 'available', 'open day available');
check(yb_classify(['use_yn' => 'N'], ['state_cd' => '20', 'state_nm' => '예약불가'])['status'] === 'unavailable', 'closed application day cannot show availability');
check(yb_classify(['use_yn' => 'N'], null)['status'] === 'unknown', 'missing calendar day is unknown');
check(yb_classify(['use_yn' => 'Y'], ['state_cd' => '10'])['status'] === 'booked', 'booked');
check(yb_classify(['use_yn' => 'E'], ['state_cd' => '30'])['status'] === 'closed', 'closure');
check(yb_classify(['use_yn' => 'N'], ['state_cd' => '15'])['status'] === 'lottery', 'lottery');
check(yb_classify(['use_yn' => '?'], ['state_cd' => '10'])['status'] === 'unknown', 'unknown upstream state');
$date = '2026-11-05';
$fixture = static function (array $batch, string $date, float $deadline): array {
    $out = [];
    foreach ($batch as $court) {
        $out[$court['id']] = [
            'times' => ['data' => [['date' => $date, 'place_cd' => $court['id'], 'start_time' => '06:00', 'end_time' => '08:00', 'rent_amt' => 55000, 'use_yn' => 'N', 'detail' => 'PRIVATE_NAME']], 'error' => null],
            'months' => ['data' => [['date' => $date, 'place_cd' => $court['id'], 'state_cd' => '10', 'state_nm' => '예약가능']], 'error' => null],
        ];
    }
    return $out;
};
$payload = yb_scan($date, $fixture);
check(count($payload['courts']) === 12 && count($payload['courts'][0]['slots']) === 1, 'twelve courts normalized');
check(strpos(json_encode($payload), 'PRIVATE_NAME') === false && strpos(json_encode($payload), 'detail') === false, 'identity fields excluded');
$partial = yb_scan($date, static function ($batch, $date, $deadline) use ($fixture) {
    $out = $fixture($batch, $date, $deadline);
    if (isset($out['25'])) $out['25']['months'] = ['data' => null, 'error' => 'offline'];
    return $out;
});
check($partial['courts'][0]['error'] === 'offline' && count($partial['courts'][0]['slots']) === 0 && $partial['courts'][1]['error'] === null, 'partial outage stays explicit');
$calls = 0;
try {
    yb_scan($date, static function ($batch) use (&$calls) {
        $calls++;
        $out = [];
        foreach ($batch as $court) $out[$court['id']] = ['times' => ['data' => null, 'error' => 'offline'], 'months' => ['data' => null, 'error' => 'offline']];
        return $out;
    });
    check(false, 'outage throws');
} catch (RuntimeException $error) { check($error->getMessage() === 'offline' && $calls === 1, 'complete outage fails after first batch'); }
check(strpos(yb_curl_error(28), 'CURL_28') !== false && strpos(yb_curl_error(60), 'TLS') !== false, 'diagnostic errors');
$temp = tempnam(sys_get_temp_dir(), 'yb-test-');
file_put_contents($temp, YB_CACHE_PREFIX . json_encode(['expires' => time() + 15, 'data' => $payload]));
check(yb_read_cache($temp, $date) !== null && yb_read_cache($temp, '2026-11-06') === null, 'cache date isolation');
file_put_contents($temp, YB_CACHE_PREFIX . json_encode(['expires' => time() - 1, 'data' => $payload]));
check(yb_read_cache($temp, $date) === null, 'cache expiry');
unlink($temp);
echo $count . ' checks passed.' . PHP_EOL;
