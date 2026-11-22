// 2026 Lara AI 플레이그라운드 - 서버 공통 기능 (Vercel Node 함수에서 사용)
import crypto from 'node:crypto';

export const DATA_KEY = 'lara:site';
const COOKIE = 'lara_admin';
const SESSION_HOURS = 12;

/* ---------- 환경 설정 ---------- */
function redisConf() {
  const url = process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL;
  const token = process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN;
  return url && token ? { url: url.replace(/\/$/, ''), token } : null;
}
export function setupStatus() {
  return {
    redis: !!redisConf(),
    blob: !!process.env.BLOB_READ_WRITE_TOKEN,
    password: !!process.env.TEACHER_PASSWORD,
  };
}

/* ---------- Upstash Redis (REST) ---------- */
export async function redis(command) {
  const conf = redisConf();
  if (!conf) throw new HttpError(503, '데이터 저장소(Upstash Redis)가 연결되지 않았습니다.');
  const r = await fetch(conf.url, {
    method: 'POST',
    headers: { Authorization: `Bearer ${conf.token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(command),
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || j.error) throw new HttpError(502, '데이터 저장소 오류: ' + (j.error || r.status));
  return j.result;
}

export function emptyData() {
  return { v: 2, rev: 0, apps: [], mats: [], notices: [] };
}
export async function loadData() {
  const raw = await redis(['GET', DATA_KEY]);
  if (!raw) return emptyData();
  try {
    const d = JSON.parse(raw);
    return { ...emptyData(), ...d };
  } catch {
    return emptyData();
  }
}

/* ---------- 오류·응답 ---------- */
export class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
export function send(res, status, obj) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(JSON.stringify(obj));
}
export function fail(res, err) {
  const status = err && err.status ? err.status : 500;
  if (status === 500) console.error(err);
  send(res, status, { error: status === 500 ? '서버에서 문제가 생겼어요. 잠시 뒤 다시 해 주세요.' : err.message });
}
export function requireMethod(req, method) {
  if (req.method !== method) throw new HttpError(405, '허용되지 않은 요청입니다.');
}
/* 다른 사이트에서 보낸 요청 차단 */
export function requireSameOrigin(req) {
  const origin = req.headers.origin;
  if (!origin) return;
  const host = req.headers['x-forwarded-host'] || req.headers.host;
  try {
    if (new URL(origin).host !== host) throw new Error();
  } catch {
    throw new HttpError(403, '허용되지 않은 요청입니다.');
  }
}
export async function readJson(req) {
  if (req.body && typeof req.body === 'object') return req.body;
  if (typeof req.body === 'string') return JSON.parse(req.body || '{}');
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const text = Buffer.concat(chunks).toString('utf8');
  return text ? JSON.parse(text) : {};
}

/* ---------- 교사 세션 (서명된 쿠키) ---------- */
function secret() {
  const s = process.env.SESSION_SECRET;
  if (s) return s;
  // 따로 정하지 않았으면 비밀번호와 저장소 토큰으로 서버에서만 아는 값을 만든다
  return crypto.createHash('sha256')
    .update(String(process.env.TEACHER_PASSWORD || '') + '|' + String(process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN || process.env.BLOB_READ_WRITE_TOKEN || ''))
    .digest('hex');
}
function sign(value) {
  return crypto.createHmac('sha256', secret()).update(value).digest('base64url');
}
function parseCookies(req) {
  const out = {};
  String(req.headers.cookie || '').split(';').forEach((p) => {
    const i = p.indexOf('=');
    if (i > 0) out[p.slice(0, i).trim()] = decodeURIComponent(p.slice(i + 1).trim());
  });
  return out;
}
export function isAdmin(req) {
  const c = parseCookies(req)[COOKIE];
  if (!c || !process.env.TEACHER_PASSWORD) return false;
  const [exp, sig] = c.split('.');
  if (!exp || !sig || Number(exp) < Date.now()) return false;
  const want = sign(exp);
  return want.length === sig.length && crypto.timingSafeEqual(Buffer.from(want), Buffer.from(sig));
}
export function requireAdmin(req) {
  if (!isAdmin(req)) throw new HttpError(401, '교사 로그인이 필요합니다.');
}
function cookieFlags(req) {
  const local = /^(localhost|127\.0\.0\.1)(:\d+)?$/.test(String(req.headers.host || ''));
  return `Path=/; HttpOnly; SameSite=Lax${local ? '' : '; Secure'}`;
}
export function setSession(req, res) {
  const exp = String(Date.now() + SESSION_HOURS * 3600 * 1000);
  res.setHeader('Set-Cookie', `${COOKIE}=${exp}.${sign(exp)}; Max-Age=${SESSION_HOURS * 3600}; ${cookieFlags(req)}`);
}
export function clearSession(req, res) {
  res.setHeader('Set-Cookie', `${COOKIE}=; Max-Age=0; ${cookieFlags(req)}`);
}
export function passwordMatches(input) {
  const want = String(process.env.TEACHER_PASSWORD || '');
  if (!want) return false;
  const a = crypto.createHash('sha256').update(String(input || '')).digest();
  const b = crypto.createHash('sha256').update(want).digest();
  return crypto.timingSafeEqual(a, b);
}
export function clientIp(req) {
  return String(req.headers['x-forwarded-for'] || req.socket?.remoteAddress || 'unknown').split(',')[0].trim();
}

/* ---------- 데이터 검사·정리 ---------- */
const CATS = ['reading', 'art', 'sport', 'ras'];
const ICONS = ['book', 'map', 'mindmap', 'music', 'palette', 'robot', 'rope', 'run', 'star', 'dragon'];
const str = (v, max) => String(v == null ? '' : v).trim().slice(0, max);
const httpUrl = (v) => { const s = str(v, 2000); return /^https?:\/\//i.test(s) ? s : ''; };
export function isBlobUrl(v) {
  try { return /\.blob\.vercel-storage\.com$/i.test(new URL(String(v)).hostname); } catch { return false; }
}
const blobUrl = (v) => (isBlobUrl(v) ? str(v, 2000) : null);
const id = (v) => { const s = str(v, 40); return /^[a-z0-9]+$/i.test(s) ? s : null; };

export function cleanData(input) {
  const d = input && typeof input === 'object' ? input : {};
  const apps = (Array.isArray(d.apps) ? d.apps : []).slice(0, 500).map((a) => {
    const kind = a.kind === 'html' ? 'html' : 'link';
    return {
      id: id(a.id), cat: CATS.includes(a.cat) ? a.cat : 'reading', kind,
      name: str(a.name, 80), desc: str(a.desc, 600), usage: str(a.usage, 1500),
      url: kind === 'link' ? httpUrl(a.url) : '',
      html: kind === 'html' ? blobUrl(a.html) : null, htmlName: kind === 'html' ? str(a.htmlName, 160) : '',
      thumb: blobUrl(a.thumb), icon: ICONS.includes(a.icon) ? a.icon : '',
      hidden: !!a.hidden, date: str(a.date, 20),
    };
  }).filter((a) => a.id && a.name && (a.kind === 'link' ? a.url : a.html));
  const appIds = new Set(apps.map((a) => a.id));
  const mats = (Array.isArray(d.mats) ? d.mats : []).slice(0, 3000).map((m) => {
    const type = m.type === 'link' ? 'link' : 'file';
    return {
      id: id(m.id), app: id(m.app), title: str(m.title, 160), type,
      url: type === 'link' ? httpUrl(m.url) : '', asset: type === 'file' ? blobUrl(m.asset) : null,
      origname: str(m.origname, 200), date: str(m.date, 20),
    };
  }).filter((m) => m.id && m.title && appIds.has(m.app) && (m.type === 'link' ? m.url : m.asset));
  const notices = (Array.isArray(d.notices) ? d.notices : []).slice(0, 500).map((n) => ({
    id: id(n.id), title: str(n.title, 120), body: str(n.body, 5000), pinned: !!n.pinned, date: str(n.date, 20),
  })).filter((n) => n.id && n.title);
  return { v: 2, apps, mats, notices };
}

/* 데이터 안에서 쓰이는 Blob 파일 주소 모음 */
export function blobUrlsIn(d) {
  const set = new Set();
  (d.apps || []).forEach((a) => { if (a.thumb) set.add(a.thumb); if (a.html) set.add(a.html); });
  (d.mats || []).forEach((m) => { if (m.asset) set.add(m.asset); });
  return set;
}

/* 파일 지우기는 최대 몇 초만 기다린다 (저장소가 느려도 저장 결과는 바로 돌려주기 위해) */
export async function deleteFilesQuietly(del, urls, ms = 4000) {
  if (!urls.length) return;
  try {
    await Promise.race([del(urls), new Promise((r) => setTimeout(r, ms))]);
  } catch (e) {
    console.warn('파일 정리 실패', e && e.message);
  }
}
