import { handleUpload } from '@vercel/blob/client';
import { fail, HttpError, readJson, requireAdmin, requireMethod, requireSameOrigin, send } from '../lib/common.js';

const MAX_MB = 50;
const ALLOWED = [
  'image/*', 'video/*', 'audio/*', 'text/*', 'application/pdf', 'application/zip', 'application/x-zip-compressed',
  'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/x-hwp', 'application/haansofthwp', 'application/vnd.hancom.hwp', 'application/vnd.hancom.hwpx',
  'application/hwp+zip', 'application/octet-stream', 'application/json',
];

// 브라우저가 Blob 저장소로 파일을 직접 올릴 수 있도록, 교사에게만 짧은 업로드 허가를 내준다
export default async function handler(req, res) {
  try {
    requireMethod(req, 'POST');
    requireSameOrigin(req);
    requireAdmin(req);
    const body = await readJson(req);
    if (!body || body.type !== 'blob.generate-client-token') throw new HttpError(400, '잘못된 업로드 요청입니다.');
    if (!process.env.BLOB_READ_WRITE_TOKEN) throw new HttpError(503, '파일 저장소(Vercel Blob)가 연결되지 않았습니다.');
    const result = await handleUpload({
      body,
      request: req,
      onBeforeGenerateToken: async (pathname) => {
        if (!/^uploads\/(thumbs|files|apps)\/[^/]{1,200}$/.test(pathname)) throw new HttpError(400, '올릴 수 없는 파일 경로입니다.');
        return { allowedContentTypes: ALLOWED, maximumSizeInBytes: MAX_MB * 1024 * 1024, addRandomSuffix: true };
      },
    });
    send(res, 200, result);
  } catch (err) {
    fail(res, err);
  }
}
