const ROOT = 'https://gjcenter.gjcs.or.kr';
export const COURTS = Array.from({ length: 12 }, (_, i) => ({ id: String(i + 25), name: `${i + 1}번 코트` }));

export function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value ?? '')) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(+parsed) && parsed.toISOString().slice(0, 10) === value && value >= '2000-01-01' && value <= '2100-12-31';
}

export function sourceUrl(date, id) {
  const url = new URL('/fmcs/3', ROOT);
  url.search = new URLSearchParams({ facilities_type: 'T', base_date: date.replaceAll('-', ''), rent_type: '1001', center: 'GJCS03', part: '10', place: id });
  return url.href;
}

// An empty time is only available when the calendar also permits applications.
export function classify(row, day) {
  if (String(day?.state_cd) === '30') return { status: 'closed', label: day.state_nm || '휴관일' };
  if (row.use_yn === 'Y') return { status: 'booked', label: '예약완료' };
  if (row.use_yn === 'E') return { status: 'unavailable', label: '마감' };
  if (row.use_yn === 'U') return { status: 'unavailable', label: '예약불가' };
  if (!day || !['10', '11', '15'].includes(String(day.state_cd))) {
    return { status: day ? 'unavailable' : 'unknown', label: day?.state_nm || '날짜 상태 미확인' };
  }
  if (row.use_yn === 'D' || String(day.state_cd) === '15') return { status: 'lottery', label: '추첨접수' };
  if (row.use_yn === 'N' && String(day.state_cd) === '10') return { status: 'available', label: '예약가능' };
  if (row.use_yn === 'N' && String(day.state_cd) === '11') return { status: 'unknown', label: day.state_nm || '접수방식 확인' };
  return { status: 'unknown', label: '상태 미확인' };
}

export function normalizeCourt(court, date, times, months) {
  if (!Array.isArray(times) || !Array.isArray(months)) throw new Error('예약 사이트 응답 형식이 변경되었습니다.');
  const day = months.find(row => row.date === date && String(row.place_cd) === court.id);
  const slots = times.filter(row => row.date === date && String(row.place_cd) === court.id && /^\d{2}:\d{2}$/.test(row.start_time) && /^\d{2}:\d{2}$/.test(row.end_time)).map(row => ({
    start: row.start_time, end: row.end_time, name: row.time_nm || '',
    amount: Number.isFinite(Number(row.rent_amt)) ? Number(row.rent_amt) : null,
    ...classify(row, day)
  }));
  return {
    ...court, url: sourceUrl(date, court.id), dayLabel: day?.state_nm || '날짜 상태 미확인',
    dayStatus: String(day?.state_cd) === '30' ? 'closed' : 'unavailable',
    slots, error: null
  };
}

export function createReservationService({ fetchImpl = fetch, now = () => Date.now(), ttl = 15000 } = {}) {
  const cache = new Map();
  const pending = new Map();
  // One scan at a time, with three courts in flight, even across different dates.
  let queue = Promise.resolve();

  async function getJson(path, date, court) {
    const url = new URL(path, ROOT);
    url.search = new URLSearchParams({ company_code: 'GJCS03', part_code: '10', place_code: court.id, base_date: date.replaceAll('-', ''), rent_type: '1001', mem_no: '' });
    const response = await fetchImpl(url, { signal: AbortSignal.timeout(12000), headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`예약 사이트 응답 오류 (${response.status})`);
    return response.json();
  }

  async function scan(date) {
    const courts = [];
    for (let offset = 0; offset < COURTS.length; offset += 3) {
      const batch = await Promise.all(COURTS.slice(offset, offset + 3).map(async court => {
        try {
          const [times, months] = await Promise.all([
            getJson('/rest/facilities/place_time_state_list', date, court),
            getJson('/rest/facilities/place_month_state_list', date, court)
          ]);
          return normalizeCourt(court, date, times, months);
        } catch (error) {
          const message = /certificate|fetch failed/i.test(error.message)
            ? '예약 사이트 연결에 실패했습니다. 네트워크와 시스템 인증서를 확인해 주세요.'
            : /timeout|aborted/i.test(error.message) ? '예약 사이트 응답 시간이 초과되었습니다.' : error.message;
          return { ...court, url: sourceUrl(date, court.id), dayLabel: '조회 실패', dayStatus: 'unknown', slots: [], error: message };
        }
      }));
      courts.push(...batch);
    }
    const result = { date, updatedAt: new Date(now()).toISOString(), courts };
    if (courts.every(c => c.error)) throw new Error(courts[0].error);
    // Do not cache partial failures: a retry should retry the upstream.
    if (courts.every(c => !c.error)) {
      cache.set(date, { expires: now() + ttl, result });
      if (cache.size > 24) cache.delete(cache.keys().next().value);
    }
    return result;
  }

  return async function reservations(date) {
    if (!validDate(date)) throw new TypeError('날짜를 YYYY-MM-DD 형식으로 입력해 주세요.');
    const cached = cache.get(date);
    if (cached && cached.expires > now()) return cached.result;
    if (pending.has(date)) return pending.get(date);
    const job = queue.then(() => scan(date));
    queue = job.catch(() => {});
    pending.set(date, job);
    try { return await job; } finally { pending.delete(date); }
  };
}
