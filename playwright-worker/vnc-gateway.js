'use strict';

const http = require('node:http');
const https = require('node:https');
const { createHash, timingSafeEqual } = require('node:crypto');
const { performance } = require('node:perf_hooks');
const { WebSocket, WebSocketServer } = require('ws');

const MAX_GRANT_MS = 1000;
const AUTH_TIMEOUT_MS = 750;
const MAX_PAYLOAD = 1024 * 1024;
const MAX_BUFFER = 2 * MAX_PAYLOAD;
const MAX_PENDING = 16;

function fault(message, statusCode = 403) {
  return Object.assign(new Error(message), { statusCode });
}

function bounded(value, fallback, ceiling) {
  return Number.isSafeInteger(value) && value > 0 ? Math.min(value, ceiling) : fallback;
}

function validGeneration(value) {
  return Number.isSafeInteger(value) && value >= 1;
}

function singleHeader(req, name) {
  const value = req.headers[name];
  let count = 0;
  for (let i = 0; i < req.rawHeaders.length; i += 2) {
    if (req.rawHeaders[i].toLowerCase() === name) count += 1;
  }
  return count === 1 && typeof value === 'string' ? value : null;
}

function validCookie(cookie) {
  if (!cookie || cookie.length > 8192) return false;
  const names = new Set();
  let nonempty = false;
  for (const part of cookie.split(';')) {
    const match = part.trim().match(/^([!#$%&'*+.^_`|~0-9A-Za-z-]+)=([\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*)$/);
    if (!match || names.has(match[1])) return false;
    names.add(match[1]);
    nonempty ||= match[2].length > 0;
  }
  return nonempty;
}

function json(res, status, body) {
  if (res.destroyed || res.headersSent) return;
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(body));
}

// Cookie is deliberately an HTTP header: Symfony must load the actual session.
// Never serialize it into the JSON payload or expose callback errors/secrets.
function httpAuthorizer(authUrl, token) {
  return ({ cookie, channelId, findingId, generation, signal }) => new Promise((resolve, reject) => {
    let url;
    try { url = new URL(authUrl); } catch { reject(fault('auth_unavailable', 503)); return; }
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.hash || !token) {
      reject(fault('auth_unavailable', 503));
      return;
    }
    const body = JSON.stringify({ channelId, findingId, generation });
    const req = (url.protocol === 'https:' ? https : http).request(url, {
      method: 'POST', signal, agent: false,
      headers: {
        Authorization: `Bearer ${token}`, Cookie: cookie,
        'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body),
      },
    }, (res) => {
      let size = 0;
      const chunks = [];
      res.on('error', reject);
      res.on('data', (chunk) => {
        size += chunk.length;
        if (size > 8192) {
          req.destroy();
          reject(fault('auth_response_too_large'));
        } else chunks.push(chunk);
      });
      res.on('end', () => {
        if (res.statusCode !== 200 || size > 8192) return reject(fault('auth_denied'));
        try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); } catch { reject(fault('auth_invalid')); }
      });
    });
    req.on('error', reject);
    req.end(body);
  });
}

function createGateway(server, options = {}) {
  if (typeof options.advanceGeneration !== 'function' || typeof options.getSessionState !== 'function') {
    throw new TypeError('advanceGeneration and getSessionState are required');
  }
  const upstream = options.upstream || { host: '127.0.0.1', port: 6080 };
  if (!['127.0.0.1', '::1'].includes(upstream.host) || !Number.isInteger(upstream.port) || upstream.port < 1 || upstream.port > 65535) {
    throw new TypeError('VNC upstream must be a loopback WebSocket endpoint');
  }
  const origins = new Set();
  const configuredOrigins = options.allowedOrigins ?? process.env.PLAYWRIGHT_ALLOWED_ORIGINS ?? '';
  for (const origin of (Array.isArray(configuredOrigins) ? configuredOrigins : configuredOrigins.split(',')).map((v) => v.trim()).filter(Boolean)) {
    const parsed = new URL(origin);
    if (!['https:', 'http:'].includes(parsed.protocol) || parsed.origin !== origin) throw new TypeError('Expected exact HTTP(S) origins');
    origins.add(origin);
  }
  const token = options.gatewayToken ?? process.env.PLAYWRIGHT_GATEWAY_TOKEN;
  const workerToken = options.workerToken ?? process.env.PLAYWRIGHT_WORKER_TOKEN;
  const credentialValid = typeof token === 'string' && token.length > 0 && token !== workerToken && !/[\r\n]/.test(token);
  const authorize = options.authorize || httpAuthorizer(process.env.PLAYWRIGHT_GATEWAY_AUTH_URL, credentialValid ? token : null);
  const authTimeoutMs = bounded(options.authTimeoutMs, AUTH_TIMEOUT_MS, AUTH_TIMEOUT_MS);
  const maxPayload = bounded(options.maxPayload, MAX_PAYLOAD, MAX_PAYLOAD);
  const maxBuffer = bounded(options.maxBufferedBytes, MAX_BUFFER, MAX_BUFFER);
  const maxRate = bounded(options.maxMessagesPerSecond, 1000, 1000);
  const heartbeatMs = bounded(options.heartbeatMs, 10000, 10000);
  const records = new Set();
  let activeGeneration = null;
  let settledGeneration = null;
  let quiesced = false;
  let closed = false;
  let operator = null;
  let connectionSequence = 0;
  let newestAdmittedSequence = 0;
  let fenceTail = Promise.resolve();
  let pendingFence = null;
  const wss = new WebSocketServer({
    noServer: true, maxPayload, maxFragments: 1024, maxBufferedChunks: 1024,
    perMessageDeflate: false, clientTracking: false, autoPong: false,
    handleProtocols: (protocols) => protocols.values().next().value,
  });
  wss.on('headers', (headers) => headers.push('Cache-Control: no-store'));

  function sessionReady() {
    try {
      const session = options.getSessionState();
      const expiry = session?.expiresAt instanceof Date ? session.expiresAt.getTime()
        : typeof session?.expiresAt === 'string' ? Date.parse(session.expiresAt) : session?.expiresAt;
      return session?.active === true && session.captureLocked === false && Number.isFinite(expiry) && expiry > Date.now();
    } catch { return false; }
  }

  function ready() {
    return !closed && !quiesced && activeGeneration !== null && settledGeneration === activeGeneration && sessionReady();
  }

  function rejectUpgrade(socket, status = 403) {
    if (socket.destroyed) return;
    socket.end(`HTTP/1.1 ${status} Rejected\r\nConnection: close\r\nCache-Control: no-store\r\nContent-Length: 0\r\n\r\n`);
    const timer = setTimeout(() => socket.destroy(), 100);
    timer.unref();
    socket.once('close', () => clearTimeout(timer));
  }

  function stop(record, code = 4003, reason = 'revoked') {
    if (record.closed) return;
    record.closed = true; // The synchronous gate always precedes transport work.
    if (operator === record) operator = null;
    clearTimeout(record.refreshTimer);
    clearTimeout(record.expiryTimer);
    record.controller?.abort();
    // Terminate the upstream immediately; no queued input may drain after fence.
    record.upstream?.terminate();
    if (record.client) {
      if (record.client.bufferedAmount || record.client.readyState !== WebSocket.OPEN) record.client.terminate();
      else {
        record.client.close(code, reason);
        record.closeTimer = setTimeout(() => record.client.terminate(), 100);
        record.closeTimer.unref();
      }
    } else {
      rejectUpgrade(record.socket);
    }
  }

  function permitted(record) {
    if (record.closed || !ready() || record.generation !== activeGeneration || performance.now() >= record.grantUntil) {
      stop(record);
      return false;
    }
    return true;
  }

  async function grant(record, generation) {
    const controller = new AbortController();
    record.controller = controller;
    const started = performance.now();
    let timer;
    const aborted = new Promise((_, reject) => {
      controller.signal.addEventListener('abort', () => reject(fault('auth_timeout')), { once: true });
      timer = setTimeout(() => controller.abort(), authTimeoutMs);
      timer.unref();
    });
    let result;
    try {
      result = await Promise.race([
        aborted,
        Promise.resolve().then(() => authorize({
          cookie: record.cookie, channelId: record.channelId, findingId: record.findingId,
          generation, signal: controller.signal,
        })),
      ]);
    } finally {
      clearTimeout(timer);
      if (record.controller === controller) record.controller = null;
    }
    if (record.closed || !ready() || record.requestGeneration !== activeGeneration
      || result?.decision !== 'allow' || !validGeneration(result.generation)
      || result.generation !== activeGeneration || (generation !== null && generation !== result.generation)
      || !Number.isSafeInteger(result.grantMs) || result.grantMs < 1 || result.grantMs > MAX_GRANT_MS
      || performance.now() >= started + result.grantMs) throw fault('auth_denied');
    record.generation = result.generation;
    // Count the network round trip against the grant, never extend it on receipt.
    record.grantUntil = started + result.grantMs;
  }

  function scheduleGrant(record) {
    const remaining = record.grantUntil - performance.now();
    record.expiryTimer = setTimeout(() => stop(record, 4003, 'grant_expired'), Math.max(1, remaining));
    record.expiryTimer.unref();
    record.refreshTimer = setTimeout(async () => {
      if (!permitted(record)) return;
      try {
        await grant(record, record.generation);
        clearTimeout(record.expiryTimer);
        scheduleGrant(record);
      } catch { stop(record); }
    }, Math.max(1, remaining / 2));
    record.refreshTimer.unref();
  }

  function withinRate(record) {
    const now = performance.now();
    if (now - record.rateStart >= 1000) { record.rateStart = now; record.rateCount = 0; }
    if (++record.rateCount > maxRate) { stop(record, 1008, 'input_flood'); return false; }
    return true;
  }

  function forward(record, destination, data, isBinary, fromClient) {
    // This guards BOTH input and old framebuffer delivery, including queued events.
    if (!permitted(record)) return;
    if (!isBinary) return stop(record, 1003, 'binary_required');
    if (fromClient && !withinRate(record)) return;
    if (destination.readyState !== WebSocket.OPEN) return stop(record, 1011, 'upstream_unavailable');
    if (destination.bufferedAmount + data.length > maxBuffer) return stop(record, 1009, 'backpressure');
    destination.send(data, { binary: true, compress: false }, (error) => { if (error) stop(record); });
  }

  async function upgrade(req, socket, head) {
    socket.on('error', () => {});
    const origin = singleHeader(req, 'origin');
    const host = singleHeader(req, 'host');
    const cookie = singleHeader(req, 'cookie');
    const protocol = singleHeader(req, 'sec-websocket-protocol');
    const route = req.url?.match(/^\/review\/([A-Za-z0-9_-]{1,128})\/interactive\/vnc$/);
    const channel = protocol?.match(/^lbb-tab\.([a-f0-9]{48})$/);
    if (req.method !== 'GET' || !route || !channel || !origins.has(origin)
      || host !== new URL(origin).host || !validCookie(cookie) || head.length > maxPayload
      || !ready() || records.size >= MAX_PENDING) return rejectUpgrade(socket);
    const record = {
      socket, cookie, findingId: route[1], channelId: channel[1],
      sequence: ++connectionSequence,
      requestGeneration: activeGeneration, generation: null, grantUntil: 0,
      closed: false, missedPongs: 0, rateStart: performance.now(), rateCount: 0,
    };
    records.add(record);
    socket.once('close', () => {
      stop(record);
      clearTimeout(record.closeTimer);
      records.delete(record);
    });
    try {
      await grant(record, null);
      if (socket.destroyed || record.closed) return;
      if (operator && (operator.channelId !== record.channelId || operator.findingId !== record.findingId)) {
        return stop(record, 4003, 'busy');
      }
      // Callback completion order cannot let an earlier connection evict a newer one.
      if (newestAdmittedSequence > record.sequence) return stop(record, 4001, 'replaced');
      wss.handleUpgrade(req, socket, head, (client) => {
        if (operator) stop(operator, 4001, 'replaced');
        newestAdmittedSequence = record.sequence;
        record.client = client;
        operator = record;
        const upstreamHost = upstream.host === '::1' ? '[::1]' : upstream.host;
        record.upstream = new WebSocket(`ws://${upstreamHost}:${upstream.port}/`, ['binary'], {
          maxPayload, maxFragments: 1024, maxBufferedChunks: 1024,
          perMessageDeflate: false, handshakeTimeout: 1000,
        });
        client.on('error', () => stop(record));
        client.on('close', () => stop(record));
        client.on('pong', () => { if (permitted(record) && withinRate(record)) record.missedPongs = 0; });
        client.on('ping', (data) => {
          // Automatic pong responses would bypass both the rate and buffer limit.
          if (!permitted(record) || !withinRate(record)) return;
          if (client.bufferedAmount + data.length + 10 > maxBuffer) return stop(record, 1009, 'backpressure');
          client.pong(data, false, (error) => { if (error) stop(record); });
        });
        client.on('message', (data, binary) => forward(record, record.upstream, data, binary, true));
        record.upstream.on('message', (data, binary) => forward(record, client, data, binary, false));
        record.upstream.on('error', () => stop(record, 1011, 'upstream_unavailable'));
        record.upstream.on('close', () => stop(record, 1011, 'upstream_closed'));
        scheduleGrant(record);
      });
    } catch { stop(record); }
  }

  const upgradeHandler = (req, socket, head) => { void upgrade(req, socket, head).catch(() => socket.destroy()); };
  server.on('upgrade', upgradeHandler);

  function fence(generation) {
    if (!validGeneration(generation)) return Promise.reject(fault('invalid_generation', 400));
    if (closed) return Promise.reject(fault('gateway_closed', 503));
    if (activeGeneration !== null && generation < activeGeneration) return Promise.reject(fault('stale_generation', 409));
    if (generation === activeGeneration && settledGeneration === generation) {
      return Promise.resolve({ fenced: true, activeGeneration, closedSockets: 0 });
    }
    if (generation === activeGeneration && pendingFence) return pendingFence;
    activeGeneration = generation;
    settledGeneration = null; // Immediately fence, BEFORE awaiting the worker FIFO.
    let closedSockets = 0;
    for (const record of records) {
      if (!record.closed) { closedSockets += 1; stop(record); }
    }
    // Invoke immediately: the worker synchronously announces its generation before
    // entering its own FIFO. Deferring invocation could let queued stale RPCs run.
    let advancement;
    try { advancement = options.advanceGeneration(generation); }
    catch (error) { advancement = Promise.reject(error); }
    const operation = Promise.all([fenceTail.catch(() => {}), advancement]).then(() => {
      if (closed) throw fault('gateway_closed', 503);
      if (activeGeneration !== generation) throw fault('stale_generation', 409);
      settledGeneration = generation;
      return { fenced: true, activeGeneration, closedSockets };
    });
    pendingFence = operation;
    fenceTail = operation;
    // Failed transitions remain gated, but the same generation can be retried.
    void operation.finally(() => { if (pendingFence === operation) pendingFence = null; }).catch(() => {});
    return operation;
  }

  function state() {
    return {
      activeGeneration,
      sockets: [...records].filter((r) => r.client && !r.closed).map((r) => ({
        channelIdHash: createHash('sha256').update(r.channelId).digest('hex'), generation: r.generation,
      })),
    };
  }

  function controlAuthenticated(req) {
    const header = singleHeader(req, 'authorization');
    if (!credentialValid || !header?.startsWith('Bearer ')) return false;
    const digest = (v) => createHash('sha256').update(v).digest();
    return timingSafeEqual(digest(header.slice(7)), digest(token));
  }

  async function control(req, res) {
    if (!controlAuthenticated(req)) { req.resume(); return json(res, 401, { error: 'unauthorized' }); }
    if (closed) return json(res, 503, { error: 'gateway_closed' });
    if (req.url === '/vnc-control/state' && req.method === 'GET') return json(res, 200, state());
    if (req.url !== '/vnc-control/fence' || req.method !== 'POST') {
      req.resume();
      return json(res, 405, { error: 'method_not_allowed' });
    }
    // Body and deadline are bounded even on an otherwise idle control socket.
    const payload = await new Promise((resolve, reject) => {
      let body = '';
      let bytes = 0;
      const timer = setTimeout(() => fail(fault('body_timeout', 408)), 1000);
      timer.unref();
      const cleanup = () => { clearTimeout(timer); req.off('data', data); req.off('end', end); req.off('error', fail); req.off('aborted', aborted); };
      const fail = (error) => { cleanup(); req.resume(); res.shouldKeepAlive = false; reject(error); };
      const aborted = () => fail(fault('body_aborted', 400));
      const data = (chunk) => {
        bytes += chunk.length;
        if (bytes > 1024) fail(fault('body_too_large', 413));
        else body += chunk.toString('utf8');
      };
      const end = () => {
        cleanup();
        try { resolve(JSON.parse(body)); } catch { reject(fault('invalid_json', 400)); }
      };
      req.on('data', data); req.on('end', end); req.on('error', fail); req.on('aborted', aborted);
    });
    json(res, 200, await fence(payload?.generation));
  }

  function handleControl(req, res) {
    if (!['/vnc-control/fence', '/vnc-control/state'].includes(req.url)) return false;
    void control(req, res).catch((error) => json(res, error.statusCode || 503, {
      fenced: false, reason: error.statusCode ? error.message : 'fence_failed',
    }));
    return true;
  }

  const unsubscribeQuiesce = options.onQuiesce?.(() => {
    quiesced = true;
    for (const record of records) stop(record, 4003, 'quiesced');
  });
  const unsubscribeResume = options.onResume?.(() => {
    // A successful capture permanently locks its retained context, even on takeover.
    if (!closed && sessionReady()) quiesced = false;
  });
  const heartbeat = setInterval(() => {
    for (const record of records) {
      if (!record.client || !permitted(record)) continue;
      if (record.missedPongs >= 2) stop(record, 4000, 'heartbeat_timeout');
      else if (record.client.bufferedAmount + 2 > maxBuffer) stop(record, 1009, 'backpressure');
      else {
        record.missedPongs += 1;
        record.client.ping(undefined, false, (error) => { if (error) stop(record); });
      }
    }
  }, heartbeatMs);
  heartbeat.unref();

  function close() {
    if (closed) return;
    closed = true;
    server.off('upgrade', upgradeHandler);
    server.off('close', close);
    clearInterval(heartbeat);
    for (const record of records) {
      stop(record);
      record.client?.terminate();
      record.socket.destroy();
      clearTimeout(record.closeTimer);
    }
    if (typeof unsubscribeQuiesce === 'function') unsubscribeQuiesce();
    if (typeof unsubscribeResume === 'function') unsubscribeResume();
    wss.close();
  }
  server.once('close', close);
  return { fence, state, handleControl, close };
}

module.exports = { createGateway };
