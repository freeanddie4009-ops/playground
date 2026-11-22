import { fail, loadData, send } from '../lib/common.js';

export default async function handler(req, res) {
  try {
    send(res, 200, await loadData());
  } catch (err) {
    fail(res, err);
  }
}
