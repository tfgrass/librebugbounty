#!/usr/bin/env node
'use strict';

// Read-only runtime smoke for the private Playwright/noVNC gateway.
// It never starts a browser, sends input, captures, or closes a session.

const http = require('node:http');
const https = require('node:https');
const net = require('node:net');
const tls = require('node:tls');
const { randomBytes } = require('node:crypto');

const DEFAULT_BASE_URL = 'http://127.0.0.1:3000';
const DEFAULT_REQUEST_TIMEOUT_MS = 5000;
const DEFAULT_TOTAL_TIMEOUT_MS = 30000;
const MAX_RESPONSE_BYTES = 64 * 1024;
const CHANNEL_ID = randomBytes(24).toString('hex');
const WS_KEY = randomBytes(16).toString('base64');

class SmokeError extends Error {
  constructor(message, exitCode = 1) {
    super(message);
    this.name = 'SmokeError';
    this.exitCode = exitCode;
  }
}

const fail = (message) => { throw new SmokeError(message); };
const expect = (condition, message) => { if (!condition) fail(message); };

function envNumber(name, fallback, min, max) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new SmokeError(`${name} must be a number`, 2);
  return Math.min(max, Math.max(min, Math.round(value)));
}

function readBaseUrl() {
  const raw = process.env.VNC_RUNTIME_SMOKE_BASE_URL
    || process.env.PLAYWRIGHT_WORKER_URL
    || DEFAULT_BASE_URL;
  let url;
  try { url = new URL(raw); } catch { throw new SmokeError('base URL is invalid', 2); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
    throw new SmokeError('base URL must be private http(s) without credentials', 2);
  }
  return url.origin;
}

function readGatewayToken() {
  const token = process.env.PLAYWRIGHT_GATEWAY_TOKEN || '';
  if (!token || !/^[\x21-\x7e]+$/.test(token)) {
    throw new SmokeError('PLAYWRIGHT_GATEWAY_TOKEN is required from the environment', 2);
  }
  return token;
}

function readAllowedOrigin() {
  const raw = process.env.PLAYWRIGHT_ALLOWED_ORIGINS || '';
  const origin = raw.split(',').map((item) => item.trim()).filter(Boolean)[0];
  if (!origin) throw new SmokeError('PLAYWRIGHT_ALLOWED_ORIGINS must contain an exact origin', 2);
  try {
    const parsed = new URL(origin);
    if (parsed.origin !== origin || !['http:', 'https:'].includes(parsed.protocol)) throw new Error();
  } catch {
    throw new SmokeError('PLAYWRIGHT_ALLOWED_ORIGINS must contain an exact http(s) origin', 2);
  }
  return origin;
}

function safeOrigin(raw) {
  try {
    const url = new URL(raw);
    url.username = '';
    url.password = '';
    return url.origin;
  } catch { return '<invalid-url>'; }
}

function remaining(deadline) {
  return Math.max(1, deadline - Date.now());
}

function requestJson(baseUrl, path, token, deadline) {
  const url = new URL(path, baseUrl);
  const transport = url.protocol === 'https:' ? https : http;
  const timeout = Math.min(DEFAULT_REQUEST_TIMEOUT_MS, remaining(deadline));
  return new Promise((resolve, reject) => {
    const req = transport.request(url, {
      method: 'GET',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      agent: false,
    }, (res) => {
      const chunks = [];
      let bytes = 0;
      res.on('data', (chunk) => {
        bytes += chunk.length;
        if (bytes > MAX_RESPONSE_BYTES) {
          req.destroy();
          reject(new SmokeError('response too large'));
          return;
        }
        chunks.push(chunk);
      });
      res.on('end', () => {
        clearTimeout(timer);
        let body = null;
        try { body = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { /* checked by caller */ }
        resolve({ status: res.statusCode, body });
      });
    });
    // Absolute, not an idle timeout: a trickling response must not extend it.
    const timer = setTimeout(() => req.destroy(new SmokeError('request timeout')), timeout);
    req.on('error', error => { clearTimeout(timer); reject(error); });
    req.end();
  });
}

function probeUpgrade(baseUrl, origin, cookie, deadline) {
  const url = new URL(baseUrl);
  const host = new URL(origin).host;
  const secure = url.protocol === 'https:';
  const headers = [
    'GET /review/11111111-1111-4111-8111-111111111111/interactive/vnc HTTP/1.1',
    `Host: ${host}`,
    `Origin: ${origin}`,
    'Upgrade: websocket',
    'Connection: Upgrade',
    `Sec-WebSocket-Key: ${WS_KEY}`,
    'Sec-WebSocket-Version: 13',
    `Sec-WebSocket-Protocol: lbb-tab.${CHANNEL_ID}`,
  ];
  if (cookie) headers.push(`Cookie: ${cookie}`);
  headers.push('', '');

  return new Promise((resolve, reject) => {
    const socket = secure
      ? tls.connect({ host: url.hostname, port: Number(url.port || 443), servername: url.hostname })
      : net.connect({ host: url.hostname, port: Number(url.port || 80) });
    let data = '';
    let settled = false;
    const done = (value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      socket.destroy();
      resolve(value);
    };
    const timer = setTimeout(() => {
      if (!settled) {
        settled = true;
        socket.destroy();
        reject(new SmokeError('upgrade probe timeout'));
      }
    }, Math.min(DEFAULT_REQUEST_TIMEOUT_MS, remaining(deadline)));
    socket.once('error', (error) => {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        reject(error);
      }
    });
    if (secure) socket.once('secureConnect', () => socket.write(headers.join('\r\n')));
    else socket.once('connect', () => socket.write(headers.join('\r\n')));
    socket.on('data', (chunk) => {
      data += chunk.toString('latin1');
      if (data.length > MAX_RESPONSE_BYTES) {
        settled = true; clearTimeout(timer); socket.destroy();
        reject(new SmokeError('upgrade response too large')); return;
      }
      const end = data.indexOf('\r\n\r\n');
      if (end >= 0) {
        const status = Number(/^HTTP\/1\.1\s+(\d+)/.exec(data.slice(0, end))?.[1] || 0);
        done({ status, upgraded: status === 101 });
      }
    });
    socket.once('close', () => {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        const status = Number(/^HTTP\/1\.1\s+(\d+)/.exec(data)?.[1] || 0);
        resolve({ status, upgraded: status === 101 });
      }
    });
  });
}

async function run() {
  const baseUrl = readBaseUrl();
  const token = readGatewayToken();
  const allowedOrigin = readAllowedOrigin();
  const timeoutMs = envNumber('VNC_RUNTIME_SMOKE_TIMEOUT_MS', DEFAULT_TOTAL_TIMEOUT_MS, 5000, 120000);
  const startedAt = Date.now();
  const deadline = startedAt + timeoutMs;

  const health = await requestJson(baseUrl, '/health', null, deadline);
  expect(health.status === 200 && health.body?.ok === true, 'health endpoint did not return 200');

  const state = await requestJson(baseUrl, '/vnc-control/state', token, deadline);
  expect(state.status === 200 && state.body && typeof state.body === 'object', 'gateway state did not return 200 JSON');
  expect(state.body.activeGeneration === null || Number.isSafeInteger(state.body.activeGeneration), 'gateway generation is invalid');
  expect(Array.isArray(state.body.sockets), 'gateway socket state is invalid');

  const foreign = await probeUpgrade(baseUrl, 'https://foreign.invalid', 'smoke=1', deadline);
  expect(!foreign.upgraded && foreign.status >= 400, 'foreign Origin was not rejected');

  const missingCookie = await probeUpgrade(baseUrl, allowedOrigin, null, deadline);
  expect(!missingCookie.upgraded && missingCookie.status >= 400, 'missing Cookie was not rejected');

  console.log(`vnc-runtime-smoke: PASS 4/4 checks (${safeOrigin(baseUrl)}), ${Math.round((Date.now() - startedAt) / 1000)}s`);
}

run().catch((error) => {
  const code = error instanceof SmokeError ? error.exitCode : 1;
  console.error(`vnc-runtime-smoke: FAIL - ${error.message || 'unknown error'}`);
  process.exitCode = code;
});
