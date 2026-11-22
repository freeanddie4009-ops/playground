import { clearSession, send } from '../lib/common.js';

export default function handler(req, res) {
  clearSession(req, res);
  send(res, 200, { ok: true });
}
