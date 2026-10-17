/* Real DDEV/TLS/WSS/Chromium acceptance. Synthetic loopback fixture ONLY.
 * Run on the host: node tests/browser/studio-review-novnc-live.cjs
 * Refuses a busy lease. Creates/removes ONLY its unique synthetic finding;
 * never navigates an existing finding or touches another operator's session.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const repo = path.resolve(__dirname, '../..');
const origin = 'https://librebugbounty.ddev.site';
const run = randomBytes(6).toString('hex');
const domain = `novnc-runtime-${run}.invalid`;
const output = path.join(repo, 'var/novnc-live-' + run);
const marker = '/tmp/lbb-novnc-' + run + '.json';
const desktop = 'ddev-librebugbounty-playwright-interactive';
const web = 'ddev-librebugbounty-web';
const proof = 'LBB native noVNC proof ' + run;
fs.mkdirSync(output, { recursive: true });
function docker(container, args, options = {}) { return execFileSync('docker', ['exec', ...(options.input ? ['-i'] : []), container, ...args], { cwd: repo, encoding: 'utf8', timeout: 30000, ...options }); }
function php(body) {
  return JSON.parse(docker(web, ['php'], { input: '<?php require "/var/www/html/vendor/autoload.php"; $kernel=new App\\Kernel("dev",false); $kernel->boot(); $db=$kernel->getContainer()->get("doctrine")->getConnection(); $em=$kernel->getContainer()->get("doctrine")->getManager(); '+body }));
}
const literal = value => JSON.stringify(value).replace(/\$/g, '\\$');
let id;
let browser;
let context;
let ownerPage;
let fixtureStarted = false;
let released = false;
async function click(page, action, confirmation = false) {
  await page.locator(`[data-novnc-action="${action}"]`).click();
  if (confirmation) await page.locator('[data-novnc-confirm-yes]').click();
}
(async () => {
  assert.equal(php('echo json_encode($db->fetchOne("SELECT state FROM interactive_browser_lease WHERE singleton_id=1"));'), 'available', 'Foreign session is busy; nothing was changed');
  // Independently verify TLS using the host trust store; do not weaken gateway Origin checks.
  execFileSync('curl', ['-fsS', '-o', '/dev/null', origin + '/review'], { timeout: 15000 });
  const html = '<!doctype html><html><head><title>Local noVNC acceptance</title></head><body style="background:#132b42;color:white;font:32px sans-serif;padding:80px"><h1>Local noVNC fixture</h1><p>Click here and press A for native proof.</p><input aria-label="local input" value="VNC input fixture"><script>document.addEventListener("keydown",e=>{if(e.key.toLowerCase()==="a")alert('+JSON.stringify(proof)+')});</script></body></html>';
  const server = `const http=require('http'),fs=require('fs'); const s=http.createServer((q,r)=>{r.writeHead(200,{'Content-Type':'text/html'});r.end(${JSON.stringify(html)})});s.listen(0,'127.0.0.1',()=>fs.writeFileSync(${JSON.stringify(marker)},JSON.stringify({pid:process.pid,port:s.address().port})));`;
  execFileSync('docker', ['exec', '-d', desktop, 'node', '-e', server], { timeout: 10000 });
  fixtureStarted = true;
  let fixture;
  for (let n = 0; n < 50; n++) {
    try { fixture = JSON.parse(docker(desktop, ['cat', marker], { stdio: ['pipe', 'pipe', 'ignore'] })); break; }
    catch { await new Promise(resolve => setTimeout(resolve, 100)); }
  }
  assert.ok(fixture?.port);
  const url = `http://127.0.0.1:${fixture.port}/`;
  const seeded = php(`$domain=(new App\\Entity\\Domain())->setHostname(${literal(domain)})->setScheme("http"); $finding=(new App\\Entity\\Finding())->setDomain($domain)->setTitle("Synthetic noVNC runtime acceptance")->setType("other")->setSeverity("low")->setUrl(${literal(url)})->setMethod("GET")->setStatus("new"); $em->persist($domain);$em->persist($finding);$em->flush();echo json_encode(["id"=>$finding->getId()]);`);
  id = seeded.id;
  browser = await chromium.launch({ headless: true });
  // Chromium's isolated test trust store lacks mkcert; TLS was verified above.
  context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1080 } });
  const a = await context.newPage(); ownerPage = a;
  const target = `${origin}/review/${id}/interactive`;
  const errors = [];
  for (const page of [a]) page.on('pageerror', error => errors.push(error.message));
  await a.goto(target);
  await a.locator('[data-review-novnc][data-state="available"]').waitFor();
  assert.equal(php('echo json_encode($db->fetchOne("SELECT state FROM interactive_browser_lease WHERE singleton_id=1"));'), 'available', 'GET must not acquire/start');
  await click(a, 'acquire');
  await a.locator('[data-review-novnc][data-state="own_active"]').waitFor({ timeout: 60000 });
  await a.locator('[data-novnc-screen] canvas').waitFor({ state: 'visible', timeout: 30000 });
  const before = php('echo json_encode($db->fetchAssociative("SELECT generation,worker_id,expires_at FROM interactive_browser_lease WHERE singleton_id=1"));');
  await a.screenshot({ path: path.join(output, 'owner-vnc.png'), fullPage: true });
  const b = await context.newPage(); ownerPage = b;
  b.on('pageerror', error => errors.push(error.message));
  await b.goto(target);
  await b.locator('[data-review-novnc][data-state="busy"]').waitFor({ timeout: 10000 });
  assert.equal(await b.locator('[data-novnc-screen] canvas').count(), 0, 'Nonowner gets no framebuffer');
  await click(b, 'takeover', true);
  await b.locator('[data-review-novnc][data-state="own_active"]').waitFor({ timeout: 20000 });
  await b.locator('[data-novnc-screen] canvas').waitFor({ state: 'visible', timeout: 20000 });
  await a.locator('[data-review-novnc][data-state="busy"]').waitFor({ timeout: 10000 });
  const after = php('echo json_encode($db->fetchAssociative("SELECT generation,worker_id,expires_at FROM interactive_browser_lease WHERE singleton_id=1"));');
  assert.equal(after.worker_id, before.worker_id, 'Takeover retained the exact browser');
  assert.equal(after.expires_at, before.expires_at, 'Takeover did not renew TTL');
  assert.ok(Number(after.generation) > Number(before.generation));
  // An automated headed capture uses its own Xvfb/Chromium while this retained
  // interactive desktop is active. Both targets are synthetic loopback pages.
  const parallel = JSON.parse(docker('ddev-librebugbounty-playwright-shot-1', ['node', '-e', `
    const http=require('http');
    const fixture=http.createServer((q,r)=>{r.writeHead(200,{'Content-Type':'text/html'});r.end('<h1>Independent automatic lane</h1>')});
    fixture.listen(0,'127.0.0.1',async()=>{
      try {
        const response=await fetch('http://127.0.0.1:3000/screenshot',{method:'POST',headers:{Authorization:'Bearer '+process.env.PLAYWRIGHT_WORKER_TOKEN,'Content-Type':'application/json'},body:JSON.stringify({url:'http://127.0.0.1:'+fixture.address().port+'/',timeoutMs:10000})});
        const data=await response.json(); console.log(JSON.stringify({status:response.status,png:typeof data.screenshotBase64==='string'&&data.screenshotBase64.startsWith('iVBOR')}));
      }catch(e){console.error('Synthetic shot lane failed');process.exitCode=1}finally{fixture.close()}
    });
  `]));
  assert.equal(parallel.status, 200, 'Automatic screenshot lane remains available during VNC');
  assert.equal(parallel.png, true);
  assert.equal(php('echo json_encode($db->fetchOne("SELECT worker_id FROM interactive_browser_lease WHERE singleton_id=1"));'), before.worker_id);
  const canvas = b.locator('[data-novnc-screen] canvas');
  await canvas.click({ position: { x: 300, y: 240 } });
  await b.keyboard.press('a');
  await b.locator('[data-novnc-dialog-message]').filter({ hasText: proof }).waitFor({ timeout: 20000 });
  const status = await b.evaluate(async () => {
    const p = document.querySelector('[data-review-novnc]');
    const channel = sessionStorage.getItem('lbb.review.interactive.channel');
    return (await fetch(`/review/${p.dataset.findingId}/interactive/status`, { headers: { 'X-LBB-Tab': channel } })).json();
  });
  assert.equal(status.dialogObservation.captureSucceeded, true, 'Native dialog image retained before dismiss');
  await b.screenshot({ path: path.join(output, 'dialog-observed.png'), fullPage: true });
  await click(b, 'save', true);
  await b.waitForURL(url => url.pathname === '/review', { timeout: 30000 });
  const saved = php(`echo json_encode($db->fetchAssociative('SELECT screenshot_path,capture_metadata FROM screenshot_job WHERE finding_id=?', [${literal(id)}]));`);
  const meta = JSON.parse(saved.capture_metadata);
  assert.equal(meta.inputMethod, 'vnc');
  assert.equal(meta.manualInteraction, null);
  assert.equal(meta.manualActionCount, null);
  assert.equal(meta.dialogText, proof);
  assert.equal(meta.dialogImageCaptured, true);
  assert.equal(meta.captureSelection, 'retained-dialog');
  assert.equal(php(`echo json_encode($db->fetchOne('SELECT manual_assessment FROM finding WHERE id=?',[${literal(id)}]));`), null);
  assert.ok(saved.screenshot_path.startsWith('storage/artifacts/') && !saved.screenshot_path.includes('..'), 'Retained artifact uses a repository-relative safe path');
  fs.copyFileSync(path.join(repo, saved.screenshot_path), path.join(output, 'retained-native-dialog.png'));
  released = true;
  assert.equal(php('echo json_encode($db->fetchOne("SELECT state FROM interactive_browser_lease WHERE singleton_id=1"));'), 'available');
  assert.deepEqual(errors, []);
  fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ pass: true, realWss: true, realChromium: true, takeoverRetainedContext: true, parallelAutomaticLane: true, nativeDialogImage: true, explicitSave: true, autoAssessment: false }, null, 2));
  console.log('PASS real DDEV WSS, two-tab takeover, native Chromium alert, immutable evidence; artifacts:', output);
})().catch(error => { console.error('FAIL live noVNC:', error.message); process.exitCode = 1; }).finally(async () => {
  if (id && !released && ownerPage && !ownerPage.isClosed()) {
    try {
      await ownerPage.evaluate(async findingId => {
        const p = document.querySelector('[data-review-novnc]'); if (!p) return;
        const channel = sessionStorage.getItem('lbb.review.interactive.channel');
        const status = await (await fetch(`/review/${findingId}/interactive/status`, { headers: { 'X-LBB-Tab': channel } })).json();
        if (!status.canRelease) return;
        await fetch(`/review/${findingId}/interactive/lease/release`, { method: 'POST', headers: { Accept: 'application/json' }, body: new URLSearchParams({ _token: p.dataset.csrfToken, channelId: channel, expectedRevision: status.revision }) });
      }, id);
    } catch {}
  }
  await browser?.close();
  if (id) {
    try {
      const result = php(`$id=${literal(id)}; $name=${literal(domain)}; if ($db->fetchOne('SELECT finding_id FROM interactive_browser_lease WHERE singleton_id=1')===$id) { echo json_encode(['cleanup'=>'lease_still_present']); exit; } $row=$db->fetchAssociative('SELECT f.domain_id,d.hostname FROM finding f JOIN domain d ON f.domain_id=d.id WHERE f.id=?',[$id]); if (!$row || $row['hostname']!==$name) { echo json_encode(['cleanup'=>'guard_denied']);exit; } $files=$db->fetchFirstColumn('SELECT file_path FROM evidence WHERE finding_id=?',[$id]);$db->beginTransaction();$db->executeStatement('DELETE FROM screenshot_job WHERE finding_id=?',[$id]);$db->executeStatement('DELETE FROM evidence WHERE finding_id=?',[$id]);$db->executeStatement('DELETE FROM finding WHERE id=?',[$id]);$db->executeStatement('DELETE FROM domain WHERE id=? AND hostname=?',[$row['domain_id'],$name]);$db->commit();foreach($files as $file){if(is_string($file)&&str_starts_with($file,'storage/artifacts/')&&!str_contains($file,'..'))@unlink('/var/www/html/'.$file);}echo json_encode(['cleanup'=>'ok']);`);
      assert.equal(result.cleanup, 'ok', 'Synthetic fixture cleanup could not be confirmed');
    } catch (error) { console.error('Fixture cleanup:', error.message); process.exitCode = 1; }
  }
  if (fixtureStarted) {
    try { docker(desktop, ['node', '-e', `const fs=require('fs');const f=${JSON.stringify(marker)};const d=JSON.parse(fs.readFileSync(f));process.kill(d.pid,'SIGTERM');fs.unlinkSync(f);`]); }
    catch { console.error('Loopback fixture cleanup could not be confirmed'); process.exitCode = 1; }
  }
});
