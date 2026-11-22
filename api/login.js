import { clientIp, fail, HttpError, passwordMatches, readJson, redis, requireMethod, requireSameOrigin, send, setSession, setupStatus } from '../lib/common.js';

const MAX_TRIES = 10;      // 10분 동안 틀릴 수 있는 횟수
const WINDOW_SEC = 600;

export default async function handler(req, res) {
  try {
    requireMethod(req, 'POST');
    requireSameOrigin(req);
    if (!setupStatus().password) throw new HttpError(503, '교사 비밀번호(TEACHER_PASSWORD)가 아직 설정되지 않았습니다.');
    const key = 'lara:login-fail:' + clientIp(req);
    let tries = 0;
    try { tries = Number(await redis(['GET', key])) || 0; } catch { /* 저장소가 없으면 횟수 제한 없이 진행 */ }
    if (tries >= MAX_TRIES) throw new HttpError(429, '비밀번호를 여러 번 틀렸어요. 10분 뒤에 다시 해 주세요.');
    const { password } = await readJson(req);
    if (!passwordMatches(password)) {
      try { await redis(['INCR', key]); await redis(['EXPIRE', key, String(WINDOW_SEC)]); } catch { /* 무시 */ }
      await new Promise((r) => setTimeout(r, 600));
      throw new HttpError(401, '비밀번호가 맞지 않아요.');
    }
    try { await redis(['DEL', key]); } catch { /* 무시 */ }
    setSession(req, res);
    send(res, 200, { ok: true });
  } catch (err) {
    fail(res, err);
  }
}
