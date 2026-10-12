/* Isolated fixtures only; see run_review.py. All outside requests are blocked. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, request } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8095';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-focus';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(base).hostname));

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const contexts = [], errors = [], outside = [], checks = [], metrics = [];
  let page;
  const mark = (text) => { checks.push(text); console.log('PASS ' + text); };
  const context = async (options = {}) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, serviceWorkers: 'block', ...options });
    contexts.push(ctx);
    ctx.setDefaultTimeout(10000);
    ctx.on('page', p => p.on('pageerror', e => errors.push(e.message)));
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    return ctx;
  };
  const snap = async () => {
    const response = await request.newContext();
    try { const r = await response.get(origin + '/__studio_review_fixture'); assert.equal(r.status(), 200); const data = await r.json(); assert.equal(data.isolated, true); return data; }
    finally { await response.dispose(); }
  };
  const idle = async p => p.waitForFunction(() => document.body.dataset.reviewSubmitting !== 'true' && document.body.dataset.reviewArriving !== 'true');
  const id = p => p.locator('body').getAttribute('data-current-id');
  const focus = p => p.locator('#review-main').focus();
  const enterFocus = async p => {
    await p.locator('[data-review-focus-toggle]').click();
    assert.equal(await p.locator('body').getAttribute('data-review-focus-mode'), 'true');
  };
  const image = p => p.locator('[data-review-shot]:not([hidden]) [data-review-image]');
  const openDetails = async p => { if (!await p.locator('[data-review-inspector]').evaluate(e => e.open)) await p.locator('[data-review-inspector] > summary').click(); };
  const closeDetails = async p => { if (await p.locator('[data-review-inspector]').evaluate(e => e.open)) await p.keyboard.press('Escape'); };
  const change = async (p, trigger) => {
    const before = await id(p); await trigger();
    await p.waitForFunction(previous => document.body.dataset.currentId !== previous, before);
    await idle(p);
  };
  const baseline = await snap(); const f = baseline.fixtures;
  let failure;
  try {
    for (const locale of ['de', 'en']) {
      const ctx = await context();
      await ctx.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
      page = await ctx.newPage(); await page.goto(origin + '/review');
      await image(page).evaluate(img => img.decode());
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'false', 'Classic view is the default');
      assert.equal(await page.locator('[data-review-focus-toggle]').getAttribute('aria-keyshortcuts'), 'f');
      assert.equal(await page.locator('[data-review-chrome-toggle]').isVisible(), false);
      assert.equal(await page.locator('.studio-review-shell > .studio-header').isVisible(), true);
      assert.equal(await page.locator('.review-card > .review-case-heading').count(), 1);
      const nativeImageStyle = await image(page).getAttribute('style');
      await page.screenshot({path:path.join(output,`${locale}-classic-1440.png`)});
      await page.setViewportSize({width:375,height:812});
      await page.screenshot({path:path.join(output,`${locale}-classic-375.png`)});
      assert.equal(await page.locator('[data-review-focus-toggle]').isVisible(), true);
      await page.setViewportSize({width:1440,height:900});
      await page.keyboard.press('f');
      assert.equal(await page.locator('[data-review-focus-toggle]').getAttribute('aria-pressed'), 'true');
      for (const selector of ['[data-review-navigation]', '[data-review-queue]', '[data-review-inspector]']) {
        const summary=page.locator(selector+' > summary');
        await summary.click(); await summary.click();
        assert.equal(await page.locator('[data-review-focus]').evaluate(el=>el===document.activeElement), true, 'Closing by mouse restores review keys immediately');
        assert.equal(await summary.evaluate(el=>new Promise(resolve=>{
          const panel=el.parentElement;
          panel.addEventListener('toggle',()=>resolve(document.activeElement===el),{once:true});
          panel.open=true; panel.open=false; el.focus();
        })),true,'A queued panel close must not steal focus from its visible summary');
        await summary.focus(); await page.keyboard.press('Enter'); await page.keyboard.press('Enter');
        assert.equal(await page.locator(selector).evaluate(el=>el.open), false, 'Enter closes the focused summary, not skips the case');
        assert.notEqual(await page.locator('body').getAttribute('data-review-submitting'),'true','Summary keys never start an asynchronous Skip');
        assert.equal(await id(page),f.main.id);
        await summary.click(); await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-review-focus]').evaluate(el=>el===document.activeElement), true, 'Escape restores review focus');
      }
      await openDetails(page);
      await page.locator('.review-basis > summary').click();
      const field=page.locator('[data-review-form] select').first();
      await field.focus(); await page.keyboard.press('f');
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'true', 'F is native in fields');
      await field.selectOption(f.main.olderEvidenceId);
      await field.evaluate(el=>{
        const nav=document.querySelector('[data-review-navigation]');
        nav.open=true; nav.open=false; el.focus();
      });
      await page.waitForTimeout(50);
      assert.equal(await field.evaluate(el=>el===document.activeElement),true,'Queued panel toggle does not steal focus from a field');
      await page.evaluate(()=>{const field=document.createElement('textarea');field.id='test-typing';document.querySelector('.review-source').append(field);field.focus();});
      await page.keyboard.type('f');
      assert.equal(await page.locator('#test-typing').inputValue(),'f');
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'true');
      await page.locator('#test-typing').evaluate(el=>el.remove());
      await closeDetails(page);
      for(let i=0;i<4;i++) {
        await page.locator('[data-review-zoom="in"]').click();
        await page.keyboard.press('f'); // Works from a toolbar button, too.
        assert.equal(await image(page).getAttribute('style') || '', nativeImageStyle || '', 'Native image sizing restored');
        assert.equal(await page.locator('.review-image-link').first().getAttribute('role'), null);
        assert.equal(await page.locator('.review-evidence > .review-shot-picker').count(),1);
        assert.equal(await field.inputValue(),f.main.olderEvidenceId,'Mode switch preserves the chosen assessment basis');
        await page.keyboard.press('f');
      }
      await page.keyboard.down('f'); await page.keyboard.down('f'); await page.keyboard.up('f');
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'false', 'Held F toggles only once');
      await page.reload();
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'false');
      await enterFocus(page); await page.reload();
      assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'true', 'Chosen mode survives reload in the tab');
      await image(page).evaluate(img=>img.decode());
      await page.mouse.move(740,420); const beforeWheel=(await image(page).boundingBox()).width;
      await page.mouse.wheel(0,-100);
      await page.waitForFunction(previous=>document.querySelector('[data-review-shot]:not([hidden]) img').getBoundingClientRect().width>previous,beforeWheel);
      assert.ok(Math.abs((await image(page).boundingBox()).width/beforeWheel-Math.exp(.4))<.02,'Exactly one zoom handler after repeated toggles');
      await page.locator('[data-review-zoom="fit"]').click();
      mark(`${locale}: classic default, F toggle, native fields, repeated mode switches, menu focus and reload persistence`);
      for (const [width,height] of [[1440,900],[1878,950],[1024,600],[844,390],[375,812]]) {
        await page.setViewportSize({width,height});
        await page.waitForFunction(() => {
          const img=document.querySelector('[data-review-shot]:not([hidden]) img');
          const box=img.getBoundingClientRect();
          const ratio=Math.min(innerWidth/img.naturalWidth, innerHeight/img.naturalHeight);
          return Math.abs(box.width - img.naturalWidth*ratio)<2;
        });
        const geometry=await image(page).evaluate(img=>({box:img.getBoundingClientRect().toJSON(),natural:[img.naturalWidth,img.naturalHeight],scroll:document.documentElement.scrollWidth,viewport:[innerWidth,innerHeight]}));
        metrics.push({locale,...geometry});
        assert.ok(geometry.box.left>=-1 && geometry.box.top>=-1 && geometry.box.right<=width+1 && geometry.box.bottom<=height+1);
        assert.ok(geometry.scroll<=width,'No horizontal document overflow');
        assert.equal(await page.locator('[data-review-inspector]').evaluate(e=>e.open),false);
        const buttons=await page.locator('[data-review-fixed], [data-review-confirm], [data-review-skip], [data-review-chrome-toggle], [data-review-focus-toggle], [data-review-zoom="fit"]').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {visible:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.closest('button')===n};}));
        assert.ok(buttons.every(b=>b.visible),`Controls accessible at ${width}x${height}: ${JSON.stringify(buttons)}`);
        if(width===1440||width===375) await page.screenshot({path:path.join(output,`${locale}-${width}.png`)});
        await page.locator('[data-review-navigation] > summary').click();
        for (const link of await page.locator('[data-review-navigation] a').all()) {
          if (await link.isVisible()) await link.click({trial:true});
        }
        if(width===1440||width===375) await page.screenshot({path:path.join(output,`${locale}-menu-${width}.png`)});
        await page.keyboard.press('Escape');
      }
      await page.setViewportSize({width:1440,height:900});
      await page.locator('[data-review-chrome-toggle]').click();
      assert.equal(await page.locator('[data-review-dock]').isVisible(),false);
      assert.equal(await page.locator('[data-review-inspector]').isVisible(),false);
      assert.equal(await page.locator('.review-alert-chip').first().isVisible(),true,'Critical warning survives hidden chrome');
      assert.equal(await page.locator('[data-review-chrome-toggle]').isVisible(),true);
      assert.equal(await page.locator('[data-review-focus-toggle]').isVisible(),true);
      await page.keyboard.press('f');
      assert.equal(await page.locator('[data-review-dock]').isVisible(),true,'Exiting hidden focus restores the classic controls');
      await page.keyboard.press('f');
      assert.equal(await page.locator('[data-review-dock]').isVisible(),false,'Hidden preference is specific to focus mode');
      await page.screenshot({path:path.join(output,`${locale}-hidden.png`)});
      await page.keyboard.press('Escape');
      assert.equal(await page.locator('[data-review-dock]').isVisible(),true);
      await page.locator('[data-review-zoom="actual"]').click();
      let b=await image(page).boundingBox(); assert.equal(Math.round(b.width),960); assert.equal(Math.round(b.height),540);
      for(let i=0;i<4;i++) await page.locator('[data-review-zoom="in"]').click();
      b=await image(page).boundingBox(); assert.ok(b.width>1440);
      const beforePan=b.x;
      await page.mouse.move(740,420); await page.mouse.down(); await page.mouse.move(500,470,{steps:8}); await page.mouse.up();
      assert.ok((await image(page).boundingBox()).x < beforePan-100);
      await page.locator('.review-image-link').first().focus();
      await page.keyboard.press('ArrowRight'); assert.equal(await id(page),f.main.id,'Image keyboard panning never votes');
      await page.locator('[data-review-zoom="fit"]').click();
      await openDetails(page); await page.locator('[data-review-pin]').click();
      await page.waitForFunction(()=>document.querySelector('[data-review-shot]:not([hidden]) img').getBoundingClientRect().width<1100);
      await page.screenshot({path:path.join(output,`${locale}-pinned.png`)});
      await page.locator('[data-review-pin]').click();
      await page.locator(`[data-review-shot-link="${f.main.missingEvidenceId}"]`).click();
      assert.equal(await page.locator('[data-review-viewer-tools]').isVisible(),false);
      await page.locator(`[data-review-shot-link="${f.main.olderEvidenceId}"]`).click();
      await page.waitForFunction(()=>{const img=document.querySelector('[data-review-shot]:not([hidden]) img');return img?.complete && img.naturalWidth>0;});
      await page.waitForFunction(()=>document.querySelector('[data-review-viewer-tools]').hidden===false);
      await closeDetails(page);
      assert.equal(await page.evaluate(()=>window.__reviewFixtureExecuted),undefined);
      await page.close();
      mark(`${locale}: full-window fit, overlays, responsive controls, hide/restore, pinning, zoom/1:1/pan and image selection`);
    }
    // Upcoming image requests may warm evidence only: never speculative review GETs.
    for (const mode of ['classic', 'focus']) {
      const pc = await context();
      await pc.addInitScript(() => {
        const NativeImage = window.Image;
        window.__speculated = [];
        window.Image = function(...args) { const img = new NativeImage(...args); window.__speculated.push(img); return img; };
        window.Image.prototype = NativeImage.prototype;
      });
      const pp = await pc.newPage(); page = pp;
      const requested = [];
      pp.on('request', r => requested.push({ url: r.url(), method: r.method() }));
      await pp.goto(origin + '/review');
      if (mode === 'focus') await enterFocus(pp);
      await pp.waitForFunction(() => window.__speculated.length === 2 && window.__speculated.every(img => img.complete && img.naturalWidth > 0));
      const hints = await pp.locator('[data-review-preloads]').evaluate(el => JSON.parse(el.dataset.reviewPreloads));
      assert.equal(hints.length, 2);
      assert.equal(await pp.evaluate(hints => {
        const build = (hint) => {
          const doc = document.implementation.createHTMLDocument();
          const shell = doc.createElement('div'); shell.className = 'studio-review-shell';
          const figure = doc.createElement('figure'); figure.dataset.reviewShot = hint.evidenceId;
          const img = doc.createElement('img'); img.loading = 'lazy'; img.dataset.reviewImage = '';
          img.dataset.reviewSha256 = hint.sha256; img.src = hint.url;
          figure.append(img); shell.append(figure); doc.body.append(shell);
          return window.LibreReviewPreload.importShell(doc).querySelector('img');
        };
        const hint = hints[0];
        for (const changed of [{ ...hint, evidenceId: 'changed' }, { ...hint, sha256: 'changed' }, { ...hint, url: hints[1].url }]) {
          if (window.__speculated.includes(build(changed))) return false;
        }
        const now = Date.now;
        try {
          Date.now = () => now() + 60001;
          return !window.__speculated.includes(build(hint));
        } finally { Date.now = now; }
      }, hints), true, 'Fresh evidence ID, path and hash must all match; images older than one minute are not reused');
      assert.equal(requested.filter(r => new URL(r.url).pathname === '/review').length, 1, 'No speculative trail-changing GET');
      assert.equal(requested.filter(r => r.method === 'POST').length, 0);
      for (const hint of hints) assert.equal(requested.filter(r => r.url === origin + hint.url).length, 1);
      assert.deepEqual((await snap()).snapshot, baseline.snapshot, 'Preloading never creates assessments, jobs or rechecks');
      await pp.evaluate(() => { window.__firstPreload = window.__speculated[0]; window.__secondPreload = window.__speculated[1]; });
      await change(pp, () => pp.locator('[data-review-skip]').click());
      assert.equal(await id(pp), f.readyError.id);
      assert.equal(await image(pp).evaluate(img => img === window.__firstPreload && img.complete && img.naturalWidth > 0), true, 'Use the exact decoded node, not just its URL');
      await change(pp, () => pp.locator('[data-review-skip]').click());
      assert.equal(await id(pp), f.readyUnchecked.id);
      assert.equal(await image(pp).evaluate(img => img === window.__secondPreload), true, 'Keep the second hint across card lifecycles');
      for (const hint of hints) assert.equal(requested.filter(r => r.url === origin + hint.url).length, 1, 'No second artifact fetch on arrival');
      assert.equal(await image(pp).getAttribute('alt') !== '', true, 'Fresh image semantics survive adoption');
      assert.equal(await pp.locator('[data-review-back]').isEnabled(), true);
      assert.deepEqual((await snap()).snapshot, baseline.snapshot, 'Preloading and Skip leave all stored case data unchanged');
      await pp.waitForTimeout(600);
      assert.ok(await pp.evaluate(() => window.__speculated.filter(img => !img.isConnected && !img.hasAttribute('alt') && img.hasAttribute('src')).length <= 2), 'Retain at most two speculative images');
      await pp.goto(origin + '/review?images=missing');
      assert.deepEqual(await pp.locator('[data-review-preloads]').evaluate(el => JSON.parse(el.dataset.reviewPreloads)), []);
      await pp.close();
      mark(mode + ': next two screenshots decoded before action, reused without refetch, bounded and trail-neutral');
    }

    // One in-flight preload is transferred, not cancelled/restarted on a quick vote.
    const pendingContext = await context();
    const pendingPage = await pendingContext.newPage(); page = pendingPage;
    let pendingRequests = 0, pendingSecondRequests = 0, pendingRelease;
    pendingPage.on('request', r => { if (r.url().includes('/artifacts/' + f.readyUnchecked.id + '/')) pendingSecondRequests++; });
    const pendingGate = new Promise(resolve => { pendingRelease = resolve; });
    await pendingPage.route(`**/artifacts/${f.readyError.id}/**`, async route => { pendingRequests++; await pendingGate; await route.continue(); });
    try {
      await pendingPage.goto(origin + '/review'); await enterFocus(pendingPage);
      for (let i = 0; i < 200 && !pendingRequests; i++) await pendingPage.waitForTimeout(20);
      assert.equal(pendingRequests, 1, 'First upcoming image starts while current case is displayed');
      await pendingPage.waitForTimeout(250);
      assert.equal(pendingSecondRequests, 0, 'Only one speculative download is active');
      await pendingPage.locator('[data-review-skip]').click();
      await pendingPage.waitForFunction(() => document.querySelector('[data-review-network]')?.hidden === false);
      assert.equal(await id(pendingPage), f.main.id);
      assert.equal(pendingRequests, 1, 'Adopt the existing request during next-image preparation');
      pendingRelease();
      await pendingPage.waitForFunction(previous => document.body.dataset.currentId !== previous, f.main.id);
      await idle(pendingPage);
      assert.equal(await id(pendingPage), f.readyError.id);
      assert.equal(pendingRequests, 1);
    } finally { pendingRelease(); await pendingPage.close(); }
    mark('In-flight preload survives a quick action without a duplicate image request');

    const guardedContext = await context();
    await guardedContext.addInitScript(() => {
      const connection = new EventTarget(); connection.saveData = true;
      Object.defineProperty(navigator, 'connection', { value: connection });
      window.__testHidden = true;
      Object.defineProperty(document, 'hidden', { get: () => window.__testHidden });
    });
    const gp = await guardedContext.newPage(); page = gp;
    let guardedRequests = 0;
    gp.on('request', r => { if (r.url().includes('/artifacts/' + f.readyError.id + '/')) guardedRequests++; });
    await gp.goto(origin + '/review');
    await gp.waitForTimeout(600); assert.equal(guardedRequests, 0);
    await gp.evaluate(() => { window.__testHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
    await gp.waitForTimeout(600); assert.equal(guardedRequests, 0, 'Save-Data suppresses speculation');
    await gp.evaluate(() => { navigator.connection.saveData = false; navigator.connection.effectiveType = '2g'; navigator.connection.dispatchEvent(new Event('change')); });
    await gp.waitForTimeout(600); assert.equal(guardedRequests, 0, 'Very slow connections suppress speculation');
    await gp.evaluate(() => { navigator.connection.effectiveType = '4g'; navigator.connection.dispatchEvent(new Event('change')); });
    for (let i = 0; i < 200 && !guardedRequests; i++) await gp.waitForTimeout(20);
    assert.equal(guardedRequests, 1);
    await gp.close();
    mark('Background tabs and Save-Data pause speculative downloads; visibility/connection changes resume them');

    const failedContext = await context(); const fp = await failedContext.newPage(); page = fp;
    let failedRequests = 0;
    await fp.route(`**/artifacts/${f.readyError.id}/**`, async route => {
      failedRequests++;
      if (failedRequests === 1) await route.abort(); else await route.continue();
    });
    await fp.goto(origin + '/review');
    for (let i = 0; i < 200 && !failedRequests; i++) await fp.waitForTimeout(20);
    await fp.waitForTimeout(600);
    assert.equal(failedRequests, 1, 'Failed preloads never retry in a loop');
    await change(fp, () => fp.locator('[data-review-skip]').click());
    await image(fp).evaluate(img => img.decode());
    assert.equal(failedRequests, 2, 'Actual arrival can load a failed speculative image normally');
    await fp.close();
    assert.deepEqual((await snap()).snapshot, baseline.snapshot);
    mark('Failed speculative images fall back to a normal read; all preload tests remain read-only');

    const touch=await context({viewport:{width:375,height:812},hasTouch:true,isMobile:true});
    const tp=await touch.newPage();await tp.goto(origin+'/review');await enterFocus(tp);await image(tp).evaluate(img=>img.decode());
    const cdp=await touch.newCDPSession(tp);const fitted=(await image(tp).boundingBox()).width;
    await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:135,y:410,id:1},{x:235,y:410,id:2}]});
    await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:60,y:410,id:1},{x:315,y:410,id:2}]});
    await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
    assert.ok((await image(tp).boundingBox()).width>fitted*1.5,'Pinch enlarges the screenshot');
    assert.equal(await id(tp),f.main.id);
    assert.deepEqual((await snap()).snapshot,baseline.snapshot,'All viewing operations are read-only');
    mark('Touch pinch zoom changes only the image viewport, never case data');

    // An interrupted visual effect must never strand the newly installed card.
    const motionContext=await context();
    await motionContext.addInitScript(() => Object.defineProperty(navigator, 'connection', { value: { saveData: true, addEventListener() {} }, configurable: true }));
    const mp=await motionContext.newPage(); page=mp;
    await mp.goto(origin+'/review'); await enterFocus(mp);
    await mp.evaluate(()=>{
      window.__motionCalls=0;
      const animate=Element.prototype.animate;
      Element.prototype.animate=function(...args){
        window.__motionCalls++;
        const animation=animate.apply(this,args);
        animation.play=()=>{}; // Keep the arrival paused until its cancellation.
        return animation;
      };
    });
    for (const cancel of ['focus', 'resize', 'reduced']) {
      const previous=await id(mp);
      await mp.locator('[data-review-skip]').click();
      await mp.waitForFunction(()=>document.body.dataset.reviewArriving==='true'&&document.querySelector('.review-image-arriving'));
      assert.notEqual(await id(mp),previous);
      if(cancel==='focus') await mp.keyboard.press('f');
      if(cancel==='resize') await mp.setViewportSize({width:1360,height:850});
      if(cancel==='reduced') await mp.emulateMedia({reducedMotion:'reduce'});
      await idle(mp);
      assert.equal(await mp.locator('.review-transition-layer, .review-image-arriving').count(),0);
      assert.equal(await mp.locator('body').getAttribute('data-review-transition'),null);
      assert.equal(await mp.locator('[data-review-confirm]').isEnabled(),true);
      if(cancel==='focus') await mp.keyboard.press('f');
      if(cancel==='reduced') await mp.emulateMedia({reducedMotion:'no-preference'});
    }
    const motionCalls=await mp.evaluate(()=>window.__motionCalls);
    let slowRequested=false, releaseSlow;
    const slowGate=new Promise(resolve=>{releaseSlow=resolve;});
    await mp.route('**/artifacts/**',async route=>{slowRequested=true;await slowGate;await route.abort();});
    try {
      const previous=await id(mp);
      await mp.locator('[data-review-skip]').click();
      for(let i=0;i<200&&!slowRequested;i++) await mp.waitForTimeout(20);
      assert.ok(slowRequested);
      await mp.waitForFunction(previous=>document.body.dataset.currentId!==previous,previous,{timeout:5000});
      await idle(mp);
      assert.equal(await mp.evaluate(()=>window.__motionCalls),motionCalls,'Slow decode falls back without an empty-image animation');
      assert.equal(await mp.locator('.review-transition-layer').count(),0);
    } finally { releaseSlow(); }
    await mp.locator('[data-review-image-warning]').waitFor();
    await mp.unroute('**/artifacts/**'); await mp.close();
    assert.deepEqual((await snap()).snapshot,baseline.snapshot,'Cancellation and slow-image fallback never assess a case');
    mark('Focus/resize/reduced-motion cancel cleanly; bounded slow/broken-image fallback keeps review usable');

    // Hold a real POST before the server handles it: no optimistic exit or duplicate write.
    const ctx=await context();
    await ctx.addInitScript(() => Object.defineProperty(navigator, 'connection', { value: { saveData: true, addEventListener() {} }, configurable: true }));
    page=await ctx.newPage();
    const imageRequests=[];page.on('request',request=>{if(request.resourceType()==='image')imageRequests.push(request.url());});
    await page.goto(origin+'/review'); await enterFocus(page);
    const outgoingUrl=await image(page).evaluate(img=>img.src);
    await page.evaluate(()=>{window.__documentIdentity='retained';window.__outgoing=document.querySelector('[data-review-shot]:not([hidden]) img');window.__animations=[];const animate=Element.prototype.animate;Element.prototype.animate=function(frames,options){const animation=animate.call(this,frames,options);window.__animations.push({frames,options,kind:this.className,retainsImage:!this.classList.contains('review-transition-image')||this.querySelector('img')===window.__outgoing,imageReady:document.querySelector('[data-review-shot]:not([hidden]) img')?.complete});(window.__liveAnimations ||= []).push(animation);return animation;};});
    // Hold the next image too: saving must not start an empty-image transition.
    let nextImageRelease, imageRequested=false;
    const nextImageGate=new Promise(resolve=>{nextImageRelease=resolve;});
    await page.route(`**/artifacts/${f.readyError.id}/**`,async route=>{imageRequested=true;await nextImageGate;await route.continue();});
    let release, posts=0;
    const gate=new Promise(resolve=>{release=resolve;});
    await page.route('**/review/*/assessment',async route=>{posts++;await gate;await route.continue();});
    await page.locator('[data-review-navigation] > summary').click();
    await page.locator('[data-review-navigation] > summary').click();
    await page.keyboard.press('ArrowRight'); // Regression: no mouse vote/refocus needed.
    await page.waitForFunction(()=>document.body.dataset.reviewSubmitting==='true');
    await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowLeft');
    await page.waitForTimeout(150);
    assert.equal(posts,1); assert.equal(await id(page),f.main.id);
    assert.equal(await page.evaluate(()=>window.__animations.length),0,'No exit before save confirmation');
    release();
    await page.waitForFunction(()=>document.querySelector('[data-review-network]')?.hidden===false);
    for(let i=0;i<200&&!imageRequested;i++) await page.waitForTimeout(20);
    assert.ok(imageRequested,'Next stored screenshot is prepared before swapping');
    assert.equal(await id(page),f.main.id,'Keep the outgoing image while decoding the next one');
    assert.equal(await page.evaluate(()=>window.__animations.length),0);
    nextImageRelease();
    await page.waitForFunction(()=>document.body.dataset.reviewArriving==='true' && window.__liveAnimations?.length===2);
    const overlap=await page.evaluate(()=>{
      window.__liveAnimations.forEach(a=>a.pause());
      window.__liveAnimations.forEach(a=>a.currentTime=120);
      const layer=document.querySelector('.review-transition-layer');
      // Check the lock in the same browser task as the paused animation. A
      // screenshot/runner round-trip may legitimately exceed the 1s safety
      // timeout; the application must not stay locked forever for a paused test.
      const state={
        arriving:document.body.dataset.reviewArriving,
        layers:document.querySelectorAll('.review-transition-layer').length,
        hidden:layer?.getAttribute('aria-hidden'),
        controls:layer?.querySelectorAll('a, button, [data-review-image]').length,
        blur:getComputedStyle(document.querySelector('[data-review-dock]')).backdropFilter,
        mid:window.__liveAnimations.map(a=>({progress:a.effect.getComputedTiming().progress,time:a.currentTime}))
      };
      document.querySelector('#review-main').dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true,cancelable:true}));
      document.querySelector('[data-review-confirm]').click();
      return {...state,submitting:document.body.dataset.reviewSubmitting};
    });
    assert.equal(overlap.arriving,'true');
    assert.notEqual(overlap.submitting,'true','Neither keyboard nor button starts a save during the overlap');
    assert.equal(overlap.layers,1);
    assert.equal(overlap.hidden,'true');
    assert.equal(overlap.controls,0);
    assert.equal(overlap.blur,'none','Moving evidence is not re-blurred by the overlays');
    assert.ok(overlap.mid.every(a=>a.progress>0&&a.progress<1),'Exit and arrival overlap instead of a blank gap');
    await page.screenshot({path:path.join(output,'transition-overlap.png')});
    await page.evaluate(()=>{if(document.body.dataset.reviewArriving==='true')window.__liveAnimations.forEach(a=>a.play());});
    await page.waitForFunction(previous=>document.body.dataset.currentId!==previous,f.main.id); await idle(page);
    assert.equal(posts,1,'Next card stays locked throughout the overlapping animation');
    assert.equal(await id(page),f.readyError.id);
    assert.equal(await page.locator('[data-review-technical-error]').isVisible(),true);
    assert.equal(await page.evaluate(()=>window.__documentIdentity),'retained','Document stays mounted');
    const transitions=await page.evaluate(()=>window.__animations);
    assert.ok(transitions.some(a=>a.frames.some(frame=>String(frame.transform).includes('translate3d(420px'))));
    assert.ok(transitions.every(a=>a.imageReady && a.retainsImage));
    assert.equal(imageRequests.filter(url=>url===outgoingUrl).length,1,'No second fetch/decode of the outgoing evidence');
    assert.equal(await page.locator('.review-transition-layer, .review-image-arriving').count(),0,'Temporary layers are released');
    assert.equal(await page.locator('body').getAttribute('data-review-transition'),null);
    await page.unroute(`**/artifacts/${f.readyError.id}/**`);
    let current=await snap();
    assert.equal(current.snapshot.finding_assessment.length,baseline.snapshot.finding_assessment.length+1);
    assert.equal(current.snapshot.finding.find(r=>r.id===f.main.id).manual_assessment,'confirmed');
    await page.unroute('**/review/*/assessment');
    mark('Decoded-image overlapping transition after confirmed save; no blank gap, blur work, stale layers or duplicate writes');

    await page.locator('[data-review-chrome-toggle]').click();
    assert.equal(await page.locator('[data-review-dock]').isVisible(),false);
    assert.equal(await page.locator('[data-review-technical-error]').isVisible(),true);
    await change(page,()=>page.keyboard.press('ArrowLeft'));
    assert.equal(await id(page),f.readyUnchecked.id);
    current=await snap();assert.equal(current.snapshot.finding.find(r=>r.id===f.readyError.id).manual_assessment,'fixed');
    assert.ok((await page.evaluate(()=>window.__animations)).some(a=>a.frames.some(frame=>String(frame.transform).includes('translate3d(-420px'))));
    await change(page,()=>page.keyboard.press('Enter'));
    assert.equal(await id(page),f.readyNoJsFixed.id);
    await change(page,()=>page.keyboard.press('ArrowDown'));
    assert.equal(await id(page),f.readyUnchecked.id);
    await change(page,()=>page.keyboard.press('ArrowDown'));
    assert.equal(await id(page),f.readyError.id);
    current=await snap();assert.equal(current.snapshot.finding.find(r=>r.id===f.readyError.id).manual_assessment,null);
    assert.equal(current.snapshot.finding.find(r=>r.id===f.main.id).manual_assessment,'confirmed');
    await change(page,()=>page.keyboard.press('ArrowDown'));
    assert.equal(await id(page),f.main.id);
    current=await snap();assert.equal(current.snapshot.finding.find(r=>r.id===f.main.id).manual_assessment,null);
    assert.equal(current.snapshot.finding_assessment_reset.length,3);
    await page.keyboard.press('Escape');
    mark('Hidden controls retain keyboard workflow; left, skip and multi-step back preserve audited reset semantics');

    // Invalid CSRF produces an actual server-rendered error and keeps the same case.
    const beforeError=await snap();
    await page.locator('[data-review-form] input[name="_token"]').evaluate(e=>e.value='invalid');
    await page.locator('[data-review-confirm]').click();
    await page.locator('[data-review-error]').waitFor(); await idle(page);
    assert.equal(await id(page),f.main.id);
    assert.deepEqual((await snap()).snapshot,beforeError.snapshot);
    await page.locator('[data-review-chrome-toggle]').click();
    assert.equal(await page.locator('[data-review-error]').isVisible(),true);
    await page.reload();
    mark('Server validation failure stays on the case; errors remain visible with hidden controls');

    // A response lost AFTER the server commits must not cause an automatic retry.
    let lostPosts=0;
    await page.route('**/review/*/assessment',async route=>{lostPosts++;await route.fetch({maxRedirects:0,timeout:15000});await route.abort('connectionreset');});
    await focus(page); await page.keyboard.press('ArrowRight');
    await page.locator('[data-review-recover]').waitFor();
    await page.keyboard.press('ArrowRight');
    assert.equal(lostPosts,1);assert.equal(await id(page),f.main.id);
    assert.equal((await snap()).snapshot.finding.find(r=>r.id===f.main.id).manual_assessment,'confirmed');
    await page.unroute('**/review/*/assessment');
    await page.locator('[data-review-recover]').click();
    await page.waitForFunction(()=>document.body.dataset.reviewSubmitting!=='true');
    assert.equal(await id(page),f.readyError.id);
    mark('Lost response after commit blocks duplicate assessment and offers explicit GET recovery');

    const reduced=await context({reducedMotion:'reduce'});const rp=await reduced.newPage();
    await rp.goto(origin+'/review'); await enterFocus(rp);
    await reduced.route(f.readyError.url, route=>route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>Local PoC fixture</title>'}));
    const popup=rp.waitForEvent('popup'); await focus(rp); await rp.keyboard.press('ArrowUp');
    const pop=await popup; await pop.waitForLoadState(); assert.equal(pop.url(),f.readyError.url); await pop.close();
    assert.equal(await id(rp),f.readyError.id);
    mark('PoC shortcut opens exactly the stored URL in a separate tab');
    await rp.evaluate(()=>{window.__animateCalls=0;const a=Element.prototype.animate;Element.prototype.animate=function(...args){window.__animateCalls++;return a.apply(this,args);};});
    await change(rp,()=>rp.locator('[data-review-skip]').click());
    assert.equal(await rp.evaluate(()=>window.__animateCalls),0);
    mark('Reduced-motion preference suppresses card movement');

    const native=await context({javaScriptEnabled:false,viewport:{width:1440,height:900}});const np=await native.newPage();
    await np.goto(origin+'/review');
    const nativeId=await id(np);
    await Promise.all([np.waitForNavigation(),np.locator('[data-review-fixed]').click()]);
    assert.notEqual(await id(np),nativeId);
    assert.equal((await snap()).snapshot.finding.find(r=>r.id===nativeId).manual_assessment,'fixed');
    await Promise.all([np.waitForNavigation(),np.locator('[data-review-back]').click()]);
    assert.equal(await id(np),nativeId);assert.equal((await snap()).snapshot.finding.find(r=>r.id===nativeId).manual_assessment,null);
    await np.screenshot({path:path.join(output,'native.png')});
    mark('Native forms still save and reset without JavaScript');

    const delayed=await context({reducedMotion:'reduce'});const dp=await delayed.newPage();
    await dp.goto(origin+'/settings');
    await dp.locator('[name="review_decision_delay_seconds"]').selectOption('3');
    await Promise.all([dp.waitForNavigation(),dp.locator('.studio-settings-actions button[type="submit"]').click()]);
    let imageRelease, imageBlocked=false;
    const imageGate=new Promise(resolve=>{imageRelease=resolve;});
    await delayed.route('**/artifacts/**',async route=>{if(!imageBlocked&&route.request().resourceType()==='image'){imageBlocked=true;await imageGate;}await route.continue();});
    try {
      await dp.goto(origin+'/review',{waitUntil:'domcontentloaded'});
      await dp.waitForFunction(()=>document.body.dataset.reviewDelayLocked==='true');
      await dp.waitForTimeout(3100);
      assert.equal(await dp.locator('[data-review-confirm]').isDisabled(),true,'Loading does not consume the decision pause');
      const beforePause=await snap(); await focus(dp);await dp.keyboard.press('ArrowRight');
      assert.deepEqual((await snap()).snapshot,beforePause.snapshot);
      imageRelease();await image(dp).evaluate(img=>img.decode());
      const readyAt=Date.now();
      await dp.waitForFunction(()=>document.body.dataset.reviewDelayLocked==='false');
      assert.ok(Date.now()-readyAt>=2800,'Full pause starts with the loaded image');
      await change(dp,()=>dp.locator('[data-review-skip]').click());
      assert.equal(await dp.locator('[data-review-confirm]').isDisabled(),true,'Next case starts its own pause');
      await change(dp,()=>dp.locator('[data-review-back]').click());
    } finally { imageRelease(); }
    await dp.goto(origin+'/settings');await dp.locator('[name="review_decision_delay_seconds"]').selectOption('0');
    await Promise.all([dp.waitForNavigation(),dp.locator('.studio-settings-actions button[type="submit"]').click()]);
    mark('Image readiness starts the full decision pause; skip/back still work during it');

    const empty=await context({reducedMotion:'reduce'});const ep=await empty.newPage();
    await ep.goto(origin+'/review?images=missing'); await enterFocus(ep);
    assert.equal(await ep.locator('[data-review-viewer-tools]').isVisible(),false);
    for(let i=0;i<3;i++) await change(ep,()=>ep.locator('[data-review-skip]').click());
    assert.equal(await id(ep),'');assert.equal(await ep.locator('.review-empty').isVisible(),true);
    await change(ep,()=>ep.locator('[data-review-back]').click());assert.equal(await id(ep),f.queued.id);
    mark('Missing images and end-of-round screen remain usable, including Back');

    const final=await snap();
    for(const table of ['domain','screenshot_job','retest_run','evidence']) assert.deepEqual(final.snapshot[table],baseline.snapshot[table],`${table} unchanged`);
    assert.deepEqual(errors,[]);assert.deepEqual(outside,[]);
    mark('No JavaScript errors, target requests, screenshot jobs or technical rechecks');
  } catch(error) {failure=error;console.error(error);if(page&&!page.isClosed())await page.screenshot({path:path.join(output,'failure.png')}).catch(()=>{});}
  finally {await fs.writeFile(path.join(output,'result.json'),JSON.stringify({checks,metrics,errors,outside,failure:failure?.stack},null,2));for(const c of contexts)await c.close();await browser.close();}
  if(failure)process.exitCode=1;
})();
