import { isAdmin, send, setupStatus } from '../lib/common.js';

export default function handler(req, res) {
  send(res, 200, { admin: isAdmin(req), setup: setupStatus() });
}
