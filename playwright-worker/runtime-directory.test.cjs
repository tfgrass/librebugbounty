'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const run = promisify(execFile);
const helper = path.join(__dirname, 'runtime-directory.sh');
const prepare = (directory, reference) => run('bash', ['-euo', 'pipefail', '-c', 'source "$1"; prepare_worker_runtime_directory "$2" "$3"', 'runtime-test', helper, directory, reference]);

test('parallel worker startup publishes a private application-owned var directory without leftovers', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'lbb-worker-directory-'));
  try {
    const directory = path.join(root, 'var');
    await Promise.all(Array.from({ length: 8 }, () => prepare(directory, root)));
    const info = await fs.stat(directory), reference = await fs.stat(root);
    assert.equal(info.mode & 0o777, 0o755);
    assert.equal(info.uid, reference.uid);
    assert.equal(info.gid, reference.gid);
    assert.deepEqual(await fs.readdir(root), ['var']);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('an existing application directory keeps its mode and files', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'lbb-worker-directory-'));
  try {
    const directory = path.join(root, 'var');
    await fs.mkdir(directory, { mode: 0o700 });
    await fs.writeFile(path.join(directory, 'application-state'), 'retained');
    await prepare(directory, root);
    assert.equal((await fs.stat(directory)).mode & 0o777, 0o700);
    assert.equal(await fs.readFile(path.join(directory, 'application-state'), 'utf8'), 'retained');
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('a non-directory application path fails without replacing user data', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'lbb-worker-directory-'));
  try {
    const directory = path.join(root, 'var');
    await fs.writeFile(directory, 'existing user data');
    await assert.rejects(prepare(directory, root));
    assert.equal(await fs.readFile(directory, 'utf8'), 'existing user data');
    assert.deepEqual(await fs.readdir(root), ['var']);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});
