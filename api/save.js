import { del } from '@vercel/blob';
import { blobUrlsIn, cleanData, deleteFilesQuietly, DATA_KEY, fail, loadData, readJson, redis, requireAdmin, requireMethod, requireSameOrigin, send } from '../lib/common.js';

// 교사가 고친 전체 목록을 저장한다. rev(판 번호)가 다르면 다른 곳에서 먼저 바뀐 것이라 거절한다.
export default async function handler(req, res) {
  try {
    requireMethod(req, 'POST');
    requireSameOrigin(req);
    requireAdmin(req);
    const body = await readJson(req);
    const current = await loadData();
    if (Number(body.rev) !== Number(current.rev)) {
      return send(res, 409, { error: '다른 곳에서 먼저 바뀌었어요. 최신 내용을 다시 불러왔으니 한 번 더 해 주세요.', data: current });
    }
    const next = cleanData(body.data);
    next.rev = Number(current.rev) + 1;
    next.updatedAt = new Date().toISOString();
    await redis(['SET', DATA_KEY, JSON.stringify(next)]);

    // 더 이상 쓰지 않는 파일은 저장소에서 지운다 (실패해도 저장은 유지)
    const keep = blobUrlsIn(next);
    const gone = [...blobUrlsIn(current)].filter((u) => !keep.has(u));
    await deleteFilesQuietly(del, gone);

    send(res, 200, { ok: true, rev: next.rev, data: next });
  } catch (err) {
    fail(res, err);
  }
}
