'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const { once } = require('node:events');
const { WebSocket, WebSocketServer } = require('ws');
const { createGateway } = require('./vnc-gateway');

const CHANNEL = 'ab'.repeat(24);
const OTHER_CHANNEL = 'cd'.repeat(24);
const ORIGIN = 'https://lbb.test';
const COOKIE = 'PHPSESSID=local-session; preference=en';
const PATH = '/review/finding-123/interactive/vnc';
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((a, b) => { resolve = a; reject = b; });
  return { promise, resolve, reject };
}
async function until(predicate, timeout = 2000) {
  const end = Date.now() + timeout;
  while (!predicate()) {
    if (Date.now() > end) throw new Error('fixture condition timed out');
    await delay(5);
  }
}
async function listen(server) {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return server.address().port;
}

async function harness(t, options = {}) {
  const upstreamServer = http.createServer();
  const upstream = new WebSocketServer({ server: upstreamServer, perMessageDeflate: false });
  const upstreamPort = await listen(upstreamServer);
  const received = [];
  const peers = [];
  upstream.on('connection', (peer) => {
    peers.push(peer);
    peer.on('error', () => {});
    peer.on('message', (data) => received.push(data.toString()));
    peer.send(Buffer.from('RFB fixture'));
  });
  let gateway;
  let fallbackCalls = 0;
  const server = http.createServer((req, res) => {
    if (gateway.handleControl(req, res)) return;
    fallbackCalls += 1;
    res.writeHead(404); res.end();
  });
  const authCalls = [];
  const advances = [];
  const session = { active: true, expiresAt: Date.now() + 120000, captureLocked: false };
  let quiesce;
  let resume;
  gateway = createGateway(server, {
    allowedOrigins: [ORIGIN], gatewayToken: 'gateway-secret', workerToken: 'worker-secret',
    upstream: { host: '127.0.0.1', port: upstreamPort },
    getSessionState: () => session,
    onQuiesce: (fn) => { quiesce = fn; }, onResume: (fn) => { resume = fn; },
    advanceGeneration: async (generation) => { advances.push(generation); },
    authorize: async (request) => {
      authCalls.push(request);
      return { decision: 'allow', generation: request.generation ?? gateway.state().activeGeneration, grantMs: 1000 };
    },
    ...options,
  });
  const port = await listen(server);
  const clients = [];
  function connect(params = {}) {
    const headers = { Host: 'lbb.test', Origin: ORIGIN, Cookie: COOKIE, ...params.headers };
    for (const [name, value] of Object.entries(headers)) if (value === null) delete headers[name];
    const ws = new WebSocket(`ws://127.0.0.1:${port}${params.path ?? PATH}`, params.protocol ?? `lbb-tab.${CHANNEL}`, {
      headers, perMessageDeflate: false, autoPong: params.autoPong ?? true,
    });
    clients.push(ws);
    const messages = [];
    ws.on('message', (data) => messages.push(data.toString()));
    const closed = new Promise((resolve) => ws.once('close', (code, reason) => resolve({ code, reason: reason.toString() })));
    const opened = new Promise((resolve) => {
      ws.once('open', () => resolve(true));
      ws.once('error', () => resolve(false));
      ws.once('unexpected-response', (_, res) => { res.resume(); ws.terminate(); resolve(false); });
    });
    ws.on('error', () => {});
    return { ws, messages, opened, closed };
  }
  t.after(async () => {
    gateway.close();
    for (const client of clients) client.terminate();
    for (const peer of peers) peer.terminate();
    upstream.close();
    await Promise.all([new Promise((r) => server.close(r)), new Promise((r) => upstreamServer.close(r))]);
  });
  return { gateway, server, port, connect, received, peers, authCalls, advances, session,
    quiesce: () => quiesce(), resume: () => resume(), fallbackCalls: () => fallbackCalls };
}

async function connected(h, params) {
  const client = h.connect(params);
  assert.equal(await client.opened, true);
  await until(() => client.messages.includes('RFB fixture'));
  return client;
}

function request(h, path, { method = 'GET', token = 'gateway-secret', body } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: h.port, path, method,
      headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), Connection: 'close' },
    }, (res) => {
      let result = '';
      res.on('data', (chunk) => { result += chunk; });
      res.on('end', () => resolve({ status: res.statusCode, body: result ? JSON.parse(result) : null, headers: res.headers }));
    });
    req.on('error', reject);
    req.end(body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body));
  });
}

test('bootstrap is explicitly fenced, bridges binary in both directions, exposes only channel hashes', async (t) => {
  const h = await harness(t);
  assert.deepEqual(h.gateway.state(), { activeGeneration: null, sockets: [] });
  assert.equal(await h.connect().opened, false);
  assert.equal(h.authCalls.length, 0);
  await h.gateway.fence(1);
  const client = await connected(h);
  client.ws.send(Buffer.from('pointer'));
  await until(() => h.received.length === 1);
  assert.deepEqual(h.received, ['pointer']);
  assert.equal(client.ws.protocol, `lbb-tab.${CHANNEL}`);
  assert.equal(h.authCalls[0].generation, null);
  assert.equal(h.authCalls[0].cookie, COOKIE);
  assert.equal(h.authCalls[0].findingId, 'finding-123');
  assert.equal(h.authCalls[0].channelId, CHANNEL);
  const state = h.gateway.state();
  assert.equal(state.sockets[0].generation, 1);
  assert.match(state.sockets[0].channelIdHash, /^[a-f0-9]{64}$/);
  assert.equal(JSON.stringify(state).includes(CHANNEL), false);
});

test('rejects invalid Origin, Host, Cookie, route and channel before authorization', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  for (const params of [
    { headers: { Origin: null } }, { headers: { Origin: 'null' } },
    { headers: { Origin: 'https://evil.test' } }, { headers: { Origin: `${ORIGIN}/` } },
    { headers: { Host: 'evil.test' } }, { headers: { Host: 'lbb.test:443' } },
    { headers: { Cookie: null } }, { headers: { Cookie: 'PHPSESSID=' } },
    { headers: { Cookie: 'PHPSESSID=a; PHPSESSID=b' } }, { headers: { Cookie: 'bad cookie' } },
    { headers: { Cookie: 'PHPSESSID=a,b' } }, { headers: { Cookie: `PHPSESSID=${'a'.repeat(8200)}` } },
    { protocol: `lbb-tab.${'a'.repeat(47)}` }, { protocol: `lbb-tab.${'A'.repeat(48)}` },
    { protocol: [`lbb-tab.${CHANNEL}`, 'binary'] }, { protocol: 'binary' },
    { path: `${PATH}?token=secret` }, { path: `${PATH}/` },
    { path: '/review/finding%2F123/interactive/vnc' }, { path: '/review/interactive/vnc' },
  ]) assert.equal(await h.connect(params).opened, false, JSON.stringify(params));
  assert.equal(h.authCalls.length, 0);
});

test('duplicate security headers are rejected even when Node folds them', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  for (const duplicate of ['Host: lbb.test', `Origin: ${ORIGIN}`, `Cookie: ${COOKIE}`, `Sec-WebSocket-Protocol: lbb-tab.${CHANNEL}`]) {
    const socket = net.connect(h.port, '127.0.0.1');
    await once(socket, 'connect');
    socket.write(`GET ${PATH} HTTP/1.1\r\nHost: lbb.test\r\nOrigin: ${ORIGIN}\r\nCookie: ${COOKIE}\r\nSec-WebSocket-Protocol: lbb-tab.${CHANNEL}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n${duplicate}\r\n\r\n`);
    const [data] = await once(socket, 'data');
    assert.match(data.toString(), /^HTTP\/1\.1 (400|403)/);
    socket.destroy();
  }
  assert.equal(h.authCalls.length, 0);
});

test('same-channel reconnect replaces one socket; other channels and findings cannot silently replace', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  const first = await connected(h);
  const second = await connected(h);
  assert.deepEqual(await first.closed, { code: 4001, reason: 'replaced' });
  assert.equal(h.gateway.state().sockets.length, 1);
  assert.equal(await h.connect({ protocol: `lbb-tab.${OTHER_CHANNEL}` }).opened, false);
  assert.equal(await h.connect({ path: '/review/other-finding/interactive/vnc' }).opened, false);
  second.ws.send(Buffer.from('new input'));
  await until(() => h.received.includes('new input'));
  assert.deepEqual(h.advances, [1]);
});

test('fence gates input and old frames immediately and ACK waits for advanceGeneration', async (t) => {
  const settle = deferred();
  const h = await harness(t, { advanceGeneration: (generation) => generation === 2 ? settle.promise : Promise.resolve() });
  await h.gateway.fence(1);
  const client = await connected(h);
  const oldPeer = h.peers[0];
  let ack = false;
  const fenced = h.gateway.fence(2).then((value) => { ack = true; return value; });
  assert.equal(h.gateway.state().activeGeneration, 2);
  assert.equal(h.gateway.state().sockets.length, 0);
  client.ws.send(Buffer.from('stale input'));
  oldPeer.send(Buffer.from('stale framebuffer'), () => {});
  assert.equal(await h.connect().opened, false);
  await delay(25);
  assert.equal(ack, false);
  assert.deepEqual(h.received, []);
  assert.deepEqual(client.messages, ['RFB fixture']);
  settle.resolve();
  assert.deepEqual(await fenced, { fenced: true, activeGeneration: 2, closedSockets: 1 });
  assert.deepEqual(h.received, []);
  assert.deepEqual(client.messages, ['RFB fixture']);
  await connected(h);
  await assert.rejects(h.gateway.fence(1), { statusCode: 409 });
});

test('a delayed earlier reconnect cannot replace a newer socket when authorization completes out of order', async (t) => {
  const earlier = deferred();
  const entered = deferred();
  let calls = 0;
  const h = await harness(t, { authorize: () => {
    if (++calls === 1) { entered.resolve(); return earlier.promise; }
    return { decision: 'allow', generation: 1, grantMs: 1000 };
  } });
  await h.gateway.fence(1);
  const first = h.connect();
  await entered.promise;
  const second = await connected(h);
  earlier.resolve({ decision: 'allow', generation: 1, grantMs: 1000 });
  assert.equal(await first.opened, false);
  second.ws.send(Buffer.from('newer wins'));
  await until(() => h.received.includes('newer wins'));
  assert.equal(h.peers.length, 1);
  assert.equal(h.gateway.state().sockets.length, 1);
});

test('pending bootstrap cannot reopen after a fence, even if its allow names the new generation', async (t) => {
  const pending = deferred();
  const entered = deferred();
  const h = await harness(t, { authorize: () => { entered.resolve(); return pending.promise; } });
  await h.gateway.fence(1);
  const client = h.connect();
  await entered.promise;
  await h.gateway.fence(2);
  pending.resolve({ decision: 'allow', generation: 2, grantMs: 1000 });
  assert.equal(await client.opened, false);
  await delay(10);
  assert.equal(h.peers.length, 0);
  assert.deepEqual(h.gateway.state().sockets, []);
});

test('delayed old refresh cannot reopen, forward input or deliver old frames after fence ACK', async (t) => {
  const pending = deferred();
  const refreshing = deferred();
  const h = await harness(t, { authorize: ({ generation }) => {
    if (generation !== null) { refreshing.resolve(); return pending.promise; }
    return { decision: 'allow', generation: 1, grantMs: 80 };
  } });
  await h.gateway.fence(1);
  const client = await connected(h);
  await refreshing.promise;
  await h.gateway.fence(2);
  pending.resolve({ decision: 'allow', generation: 1, grantMs: 1000 });
  if (client.ws.readyState === WebSocket.OPEN) client.ws.send(Buffer.from('old input'));
  h.peers[0].send(Buffer.from('old frame'), () => {});
  await delay(20);
  assert.deepEqual(h.received, []);
  assert.deepEqual(client.messages, ['RFB fixture']);
  assert.deepEqual(h.gateway.state().sockets, []);
});

test('fence is monotonic, idempotent and retries a failed worker advance while gated', async (t) => {
  let calls = 0;
  const h = await harness(t, { advanceGeneration: async () => { if (++calls === 1) throw new Error('FIFO failure'); } });
  await assert.rejects(h.gateway.fence(3), /FIFO failure/);
  assert.equal(await h.connect().opened, false);
  await h.gateway.fence(3);
  const client = await connected(h);
  assert.equal((await h.gateway.fence(3)).closedSockets, 0);
  assert.equal(client.ws.readyState, WebSocket.OPEN);
  assert.equal(calls, 2);
  for (const generation of [0, -1, 1.1, '4', null, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
    await assert.rejects(h.gateway.fence(generation), { statusCode: 400 });
  }
  await assert.rejects(h.gateway.fence(2), { statusCode: 409 });
});

test('concurrent fences immediately announce generations and a superseded transition never opens sockets', async (t) => {
  const settle = deferred();
  const calls = [];
  const h = await harness(t, { advanceGeneration: async (generation) => {
    calls.push(generation);
    if (generation === 1) await settle.promise;
  } });
  const first = h.gateway.fence(1);
  const rejectedFirst = assert.rejects(first, { statusCode: 409 });
  const second = h.gateway.fence(2);
  assert.equal(h.gateway.fence(2), second);
  assert.equal(await h.connect().opened, false);
  assert.deepEqual(calls, [1, 2]);
  settle.resolve();
  await rejectedFirst;
  await second;
  assert.deepEqual(calls, [1, 2]);
  await connected(h);
});

test('worker generation announcement precedes already queued stale RPC microtasks', async (t) => {
  let workerGeneration = 0;
  const h = await harness(t, { advanceGeneration: (generation) => {
    workerGeneration = generation;
    return Promise.resolve();
  } });
  await h.gateway.fence(1);
  const oldRpc = Promise.resolve().then(() => workerGeneration === 1 ? 'executed' : 'rejected');
  const fenced = h.gateway.fence(2);
  assert.equal(workerGeneration, 2);
  assert.equal(await oldRpc, 'rejected');
  await fenced;
});

test('idle authorization refresh is bound to its generation and policy denial closes immediately', async (t) => {
  const generations = [];
  const h = await harness(t, { authorize: ({ generation }) => {
    generations.push(generation);
    return generation === null ? { decision: 'allow', generation: 1, grantMs: 70 } : { decision: 'deny', reason: 'policy_changed' };
  } });
  await h.gateway.fence(1);
  const client = await connected(h);
  await client.closed;
  assert.deepEqual(generations, [null, 1]);
  assert.deepEqual(h.gateway.state().sockets, []);
});

test('a refresh generation mismatch revokes both directions', async (t) => {
  const h = await harness(t, { authorize: ({ generation }) => ({ decision: 'allow', generation: generation === null ? 1 : 2, grantMs: 70 }) });
  await h.gateway.fence(1);
  const client = await connected(h);
  await client.closed;
  assert.equal(h.gateway.state().sockets.length, 0);
});

test('grants reject nonfinite/oversize lifetimes and invalid generations', async (t) => {
  for (const response of [
    { generation: 1, grantMs: 0 }, { generation: 1, grantMs: -1 },
    { generation: 1, grantMs: Infinity }, { generation: 1, grantMs: NaN },
    { generation: 1, grantMs: 1001 }, { generation: 1, grantMs: '1000' },
    { generation: '1', grantMs: 1000 }, { generation: 2, grantMs: 1000 },
  ]) {
    const h = await harness(t, { authorize: async () => ({ decision: 'allow', ...response }) });
    await h.gateway.fence(1);
    assert.equal(await h.connect().opened, false);
  }
});

test('bootstrap has a deadline, aborts callback, and a late result cannot admit a socket', async (t) => {
  const pending = deferred();
  let signal;
  const h = await harness(t, { authTimeoutMs: 30, authorize: (req) => { signal = req.signal; return pending.promise; } });
  await h.gateway.fence(1);
  const client = h.connect();
  assert.equal(await client.opened, false);
  assert.equal(signal.aborted, true);
  pending.resolve({ decision: 'allow', generation: 1, grantMs: 1000 });
  await delay(10);
  assert.equal(h.peers.length, 0);
});

test('an unanswered idle refresh expires its original grant before the callback deadline', async (t) => {
  let signal;
  const h = await harness(t, { authorize: ({ generation, signal: s }) => {
    if (generation === null) return { decision: 'allow', generation: 1, grantMs: 80 };
    signal = s;
    return new Promise(() => {});
  } });
  await h.gateway.fence(1);
  const client = await connected(h);
  const start = Date.now();
  await client.closed;
  assert.ok(Date.now() - start < 400);
  assert.equal(signal.aborted, true);
});

test('grant time includes callback latency and cannot be extended by a delayed response', async (t) => {
  const h = await harness(t, { authorize: async () => {
    await delay(50);
    return { decision: 'allow', generation: 1, grantMs: 25 };
  } });
  await h.gateway.fence(1);
  assert.equal(await h.connect().opened, false);
});

test('quiesce disconnects before forwarding; failed capture may resume only with a new grant', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  const client = await connected(h);
  h.quiesce();
  client.ws.send(Buffer.from('after quiesce'));
  assert.equal(await h.connect().opened, false);
  await client.closed;
  assert.deepEqual(h.received, []);
  h.resume();
  await connected(h);
  assert.equal(h.authCalls.filter((a) => a.generation === null).length, 2);
});

test('captureLocked cannot resume on hooks, reconnect or takeover', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  const client = await connected(h);
  h.quiesce();
  h.session.captureLocked = true;
  h.resume();
  await client.closed;
  assert.equal(await h.connect().opened, false);
  await h.gateway.fence(2);
  h.resume();
  assert.equal(await h.connect().opened, false);
  assert.equal(h.authCalls.length, 1);
});

test('worker advance may synchronously quiesce and asynchronously resume before the fence ACK', async (t) => {
  let h;
  const settle = deferred();
  h = await harness(t, { advanceGeneration: async (generation) => {
    h.quiesce();
    if (generation === 2) await settle.promise;
    h.resume();
  } });
  await h.gateway.fence(1);
  await connected(h);
  const fenced = h.gateway.fence(2);
  assert.equal(await h.connect().opened, false);
  settle.resolve();
  await fenced;
  await connected(h);
});

test('every input checks live capture lock, session existence and absolute expiry', async (t) => {
  for (const update of [
    (s) => { s.captureLocked = true; }, (s) => { s.active = false; },
    (s) => { s.expiresAt = Date.now() - 1; }, (s) => { s.expiresAt = Infinity; },
  ]) {
    const h = await harness(t);
    await h.gateway.fence(1);
    const client = await connected(h);
    update(h.session);
    client.ws.send(Buffer.from('blocked'));
    await client.closed;
    assert.deepEqual(h.received, []);
  }
});

test('session expiry also gates framebuffer delivery without browser input', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  const client = await connected(h);
  h.session.expiresAt = new Date(Date.now() - 1).toISOString();
  h.peers[0].send(Buffer.from('expired frame'));
  await client.closed;
  assert.deepEqual(client.messages, ['RFB fixture']);
});

test('backpressure closes instead of queueing client input', async (t) => {
  const h = await harness(t, { maxBufferedBytes: 32 });
  await h.gateway.fence(1);
  const client = await connected(h);
  const peerPort = h.peers[0]._socket.localPort;
  const descriptor = Object.getOwnPropertyDescriptor(WebSocket.prototype, 'bufferedAmount');
  Object.defineProperty(WebSocket.prototype, 'bufferedAmount', {
    ...descriptor, get() {
      return !this._isServer && this.url === `ws://127.0.0.1:${peerPort}/` ? 32 : descriptor.get.call(this);
    },
  });
  try {
    client.ws.send(Buffer.from('pointer'));
    assert.equal((await client.closed).code, 1009);
    assert.deepEqual(h.received, []);
  } finally { Object.defineProperty(WebSocket.prototype, 'bufferedAmount', descriptor); }
});

test('backpressure bounds outgoing framebuffer bytes for a slow browser', async (t) => {
  const h = await harness(t, { maxBufferedBytes: 32 });
  await h.gateway.fence(1);
  const client = await connected(h);
  h.peers[0].send(Buffer.alloc(33, 'x'));
  assert.equal((await client.closed).code, 1009);
  assert.deepEqual(client.messages, ['RFB fixture']);
});

test('oversize and text messages fail closed; a flood is bounded', async (t) => {
  for (const kind of ['oversize', 'text', 'flood']) {
    const h = await harness(t, { maxPayload: 32, maxMessagesPerSecond: 2 });
    await h.gateway.fence(1);
    const client = await connected(h);
    if (kind === 'oversize') client.ws.send(Buffer.alloc(33));
    else if (kind === 'text') client.ws.send('not binary');
    else for (let i = 0; i < 4; i += 1) client.ws.send(Buffer.from('input'));
    const result = await client.closed;
    assert.equal(result.code, kind === 'oversize' ? 1009 : kind === 'text' ? 1003 : 1008);
    assert.ok(h.received.length <= (kind === 'flood' ? 2 : 0));
  }
});

test('heartbeat closes after two unanswered pings; reconnect keeps worker state', async (t) => {
  const h = await harness(t, { heartbeatMs: 20 });
  await h.gateway.fence(1);
  const client = await connected(h, { autoPong: false });
  const result = await client.closed;
  assert.equal(result.reason, 'heartbeat_timeout');
  const replacement = await connected(h);
  await delay(90);
  assert.equal(replacement.ws.readyState, WebSocket.OPEN);
  assert.deepEqual(h.advances, [1]);
});

test('browser ping/pong floods cannot bypass the input rate and outgoing buffer bounds', async (t) => {
  for (const operation of ['ping', 'pong']) {
    const h = await harness(t, { maxMessagesPerSecond: 2 });
    await h.gateway.fence(1);
    const client = await connected(h);
    for (let i = 0; i < 4; i += 1) client.ws[operation]('fixture');
    assert.equal((await client.closed).code, 1008);
    assert.deepEqual(h.received, []);
  }
  const h = await harness(t, { maxBufferedBytes: 32 });
  await h.gateway.fence(1);
  const client = await connected(h);
  client.ws.ping(Buffer.alloc(32));
  assert.equal((await client.closed).code, 1009);
});

test('control handler returns a boolean, uses distinct gateway auth and hides service errors', async (t) => {
  const h = await harness(t);
  assert.equal(h.gateway.handleControl({ url: '/unrelated' }, {}), false);
  assert.equal((await request(h, '/unrelated')).status, 404);
  assert.equal(h.fallbackCalls(), 1);
  for (const token of [null, 'worker-secret', 'invalid']) {
    assert.equal((await request(h, '/vnc-control/state', { token })).status, 401);
    assert.equal((await request(h, '/vnc-control/fence', { token, method: 'POST', body: { generation: 1 } })).status, 401);
  }
  const fenced = await request(h, '/vnc-control/fence', { method: 'POST', body: { generation: 2 } });
  assert.equal(fenced.status, 200);
  assert.equal(fenced.body.fenced, true);
  assert.equal(fenced.headers['cache-control'], 'no-store');
  assert.equal(h.fallbackCalls(), 1);
  assert.equal((await request(h, '/vnc-control/state')).body.activeGeneration, 2);
  assert.equal((await request(h, '/vnc-control/fence', { method: 'POST', body: { generation: 1 } })).status, 409);
  assert.equal((await request(h, '/vnc-control/fence', { method: 'GET' })).status, 405);
  assert.equal((await request(h, '/vnc-control/fence', { method: 'POST', body: '{' })).status, 400);
  assert.equal((await request(h, '/vnc-control/fence', { method: 'POST', body: { generation: '3' } })).status, 400);
  assert.equal((await request(h, '/vnc-control/fence', { method: 'POST', body: 'x'.repeat(1025) })).status, 413);
});

test('control RPC ACK also waits for the worker FIFO after immediate fencing', async (t) => {
  const settle = deferred();
  const entered = deferred();
  const h = await harness(t, { advanceGeneration: async (generation) => {
    if (generation === 2) { entered.resolve(); await settle.promise; }
  } });
  await h.gateway.fence(1);
  const client = await connected(h);
  let ack = false;
  const response = request(h, '/vnc-control/fence', { method: 'POST', body: { generation: 2 } }).then((r) => { ack = true; return r; });
  await entered.promise;
  assert.equal(h.gateway.state().sockets.length, 0);
  assert.equal(ack, false);
  await client.closed;
  assert.equal(ack, false);
  settle.resolve();
  assert.equal((await response).status, 200);
});

test('reusing the worker credential as gateway credential disables control', async (t) => {
  const h = await harness(t, { gatewayToken: 'worker-secret' });
  assert.equal((await request(h, '/vnc-control/state', { token: 'worker-secret' })).status, 401);
});

test('default callback sends Cookie and internal Bearer headers, null bootstrap and integer refresh', async (t) => {
  const calls = [];
  const callback = http.createServer((req, res) => {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      calls.push({ method: req.method, path: req.url, headers: req.headers, body: JSON.parse(body) });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ decision: 'allow', generation: 1, grantMs: 100 }));
    });
  });
  const port = await listen(callback);
  t.after(() => new Promise((resolve) => callback.close(resolve)));
  const previous = process.env.PLAYWRIGHT_GATEWAY_AUTH_URL;
  process.env.PLAYWRIGHT_GATEWAY_AUTH_URL = `http://127.0.0.1:${port}/internal/interactive-gateway/authorize`;
  t.after(() => {
    if (previous === undefined) delete process.env.PLAYWRIGHT_GATEWAY_AUTH_URL;
    else process.env.PLAYWRIGHT_GATEWAY_AUTH_URL = previous;
  });
  const h = await harness(t, { authorize: undefined });
  await h.gateway.fence(1);
  await connected(h);
  await until(() => calls.length >= 2);
  assert.deepEqual(calls[0].body, { channelId: CHANNEL, findingId: 'finding-123', generation: null });
  assert.deepEqual(calls[1].body, { channelId: CHANNEL, findingId: 'finding-123', generation: 1 });
  assert.equal(calls[0].headers.cookie, COOKIE);
  assert.equal(calls[0].headers.authorization, 'Bearer gateway-secret');
  assert.equal(calls[0].path, '/internal/interactive-gateway/authorize');
  assert.equal(calls[0].method, 'POST');
  assert.equal('cookie' in calls[0].body, false);
});

test('shutdown detaches upgrade listener, closes transports, and rejects new fences', async (t) => {
  const h = await harness(t);
  await h.gateway.fence(1);
  const client = await connected(h);
  h.gateway.close(); h.gateway.close();
  await client.closed;
  assert.equal(h.server.listenerCount('upgrade'), 0);
  assert.equal(h.gateway.state().sockets.length, 0);
  await assert.rejects(h.gateway.fence(2), { statusCode: 503 });
});
