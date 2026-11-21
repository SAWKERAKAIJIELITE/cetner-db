#!/usr/bin/env node
// Reference device client for the desktop app team (code-documentation.md, section 7.6).
// Usage:
//   node tools/device-client.mjs register <base-url> [key-file]        → prints device id and fingerprint
//   node tools/device-client.mjs status   <base-url> <device-id>
//   node tools/device-client.mjs login    <base-url> <device-id> <username> <password> [key-file]
// The private key is kept in key-file (default .device-key.pem). A real client stores it encrypted with
// the operating system's per-user protection; this file is for development only.
import { generateKeyPairSync, createPrivateKey, createPublicKey, sign } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { hostname, networkInterfaces, type, release } from 'node:os';

const PREFIX = 'hr-staff-login-v1';
const [, , command, base, ...args] = process.argv;

function loadOrCreateKey(file) {
  if (existsSync(file)) return createPrivateKey(readFileSync(file));
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'P-256' });
  writeFileSync(file, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
  return privateKey;
}

function firstMac() {
  for (const list of Object.values(networkInterfaces())) {
    for (const nic of list ?? []) if (!nic.internal && nic.mac && nic.mac !== '00:00:00:00:00:00') return nic.mac;
  }
  return '02:00:00:00:00:01';
}

async function call(method, path, body) {
  const res = await fetch(`${base.replace(/\/$/, '')}/api/v1/staff${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${res.status} ${JSON.stringify(json)}`);
  return json;
}

if (command === 'register') {
  const key = loadOrCreateKey(args[0] ?? '.device-key.pem');
  const publicKey = createPublicKey(key).export({ type: 'spki', format: 'pem' });
  const { data } = await call('POST', '/devices/register', {
    label: hostname(), public_key: publicKey, reported_mac: firstMac(), os_info: `${type()} ${release()}`,
  });
  console.log(JSON.stringify(data, null, 2));
} else if (command === 'status') {
  console.log(JSON.stringify((await call('GET', `/devices/${args[0]}/status`)).data));
} else if (command === 'login') {
  const [deviceId, username, password, keyFile] = args;
  const key = loadOrCreateKey(keyFile ?? '.device-key.pem');
  const { challenge_id, challenge } = await call('POST', '/auth/challenge', { device_id: deviceId });
  // Sign the username exactly as sent: trimmed, its case unchanged.
  const user = username.trim();
  const payload = [PREFIX, challenge_id, challenge, deviceId, user].join('\n');
  const signature = sign('sha256', Buffer.from(payload, 'utf8'), key).toString('base64'); // DER by default
  const login = await call('POST', '/auth/login', { device_id: deviceId, challenge_id, signature, username: user, password });
  console.log(JSON.stringify({ token: login.token, expires_at: login.expires_at, user: login.user.username, role: login.user.role.slug }, null, 2));
} else {
  console.error('Usage: register <base-url> | status <base-url> <device-id> | login <base-url> <device-id> <username> <password>');
  process.exit(2);
}
