import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { createReservationService, validDate } from './lib/reservations.mjs';

const reservations = createReservationService();
const indexFile = new URL('./index.html', import.meta.url);
const port = Number(process.env.PORT || 8787);
// Optional for a future separately hosted frontend. No CORS permission by default.
const allowedOrigin = process.env.ALLOWED_ORIGIN || '';

const server = createServer(async (req, res) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Cache-Control', 'no-store');
  if (allowedOrigin && req.headers.origin === allowedOrigin) {
    res.setHeader('Access-Control-Allow-Origin', allowedOrigin);
    res.setHeader('Vary', 'Origin');
  }
  const json = (status, data) => {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(data));
  };
  try {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET') {
      res.setHeader('Allow', 'GET');
      return json(405, { error: 'GET 요청만 지원합니다.' });
    }
    if (url.pathname === '/api/reservations') {
      const date = url.searchParams.get('date');
      if (!validDate(date)) return json(400, { error: '유효한 날짜를 YYYY-MM-DD 형식으로 입력해 주세요.' });
      return json(200, await reservations(date));
    }
    if (url.pathname === '/' || url.pathname === '/index.html') {
      const html = await readFile(indexFile);
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(html);
    }
    if (url.pathname === '/favicon.ico') { res.writeHead(204); return res.end(); }
    return json(404, { error: '페이지를 찾을 수 없습니다.' });
  } catch (error) {
    return json(502, { error: error.message || '예약 정보를 가져오지 못했습니다.' });
  }
});

server.on('error', error => {
  console.error(error.code === 'EADDRINUSE' ? `${port} 포트가 사용 중입니다. 기존 서버를 종료하거나 PORT 환경변수를 바꾸세요.` : error.message);
  process.exitCode = 1;
});
server.listen(port, '0.0.0.0', () => console.log(`양벌 테니스 돔 예약 현황: http://localhost:${port}\n종료: Ctrl+C`));
