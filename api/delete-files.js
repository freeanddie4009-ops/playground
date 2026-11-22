import { del } from '@vercel/blob';
import { blobUrlsIn, deleteFilesQuietly, fail, isBlobUrl, loadData, readJson, requireAdmin, requireMethod, requireSameOrigin, send } from '../lib/common.js';

// 올렸지만 저장에 실패한 파일처럼, 목록에 없는 파일만 지운다
export default async function handler(req, res) {
  try {
    requireMethod(req, 'POST');
    requireSameOrigin(req);
    requireAdmin(req);
    const { urls } = await readJson(req);
    const used = blobUrlsIn(await loadData());
    const targets = (Array.isArray(urls) ? urls : []).slice(0, 50).filter((u) => isBlobUrl(u) && !used.has(u));
    await deleteFilesQuietly(del, targets);
    send(res, 200, { ok: true, deleted: targets.length });
  } catch (err) {
    fail(res, err);
  }
}
