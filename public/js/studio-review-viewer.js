(() => {
  'use strict';
  const t = window.LibreBugBountyI18n?.t || ((key) => key);

  // Local evidence only. Panning/zooming never invokes an assessment action.
  window.LibreReviewViewer = { mount(root, signal) {
    const tools = root.querySelector('[data-review-viewer-tools]');
    if (!tools) return { select() {}, setActive() {} };
    const output = root.querySelector('[data-review-zoom-value]');
    const warning = root.querySelector('[data-review-image-warning]');
    let image, viewport, scale = 1, fit = 1, x = 0, y = 0, fitMode = true;
    let observer, imageEvents, restore;
    let active = false;
    const pointers = new Map();
    let previous = null;
    const on = (node, type, fn, options = {}) => node?.addEventListener(type, fn, { ...options, signal });
    const usable = () => active && image?.complete && image.naturalWidth > 0 && image.naturalHeight > 0;
    const moving = () => root.dataset.reviewSubmitting === 'true' || root.dataset.reviewArriving === 'true';
    const geometry = () => {
      if (!usable()) return;
      fit = Math.min(viewport.clientWidth / image.naturalWidth, viewport.clientHeight / image.naturalHeight);
      if (fitMode) { scale = fit; x = 0; y = 0; }
      draw();
    };
    const draw = () => {
      if (!usable()) return;
      const width = image.naturalWidth * scale, height = image.naturalHeight * scale;
      const limitX = Math.max(0, (width - viewport.clientWidth) / 2);
      const limitY = Math.max(0, (height - viewport.clientHeight) / 2);
      x = Math.max(-limitX, Math.min(limitX, x));
      y = Math.max(-limitY, Math.min(limitY, y));
      image.style.width = `${width}px`;
      image.style.height = `${height}px`;
      image.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))`;
      viewport.dataset.zoomed = String(limitX > 0 || limitY > 0);
      viewport.dataset.scale = String(scale);
      output.textContent = `${Math.round(scale * 100)}%`;
    };
    const zoom = (next, at = { x: 0, y: 0 }) => {
      if (!usable() || moving()) return;
      fitMode = false;
      next = Math.max(Math.min(fit, 1) / 2, Math.min(Math.max(8, fit * 4), next));
      const ratio = next / scale;
      x = at.x - (at.x - x) * ratio;
      y = at.y - (at.y - y) * ratio;
      scale = next;
      draw();
    };
    const center = (event) => {
      const rect = viewport.getBoundingClientRect();
      return { x: event.clientX - rect.left - rect.width / 2, y: event.clientY - rect.top - rect.height / 2 };
    };
    const reset = () => { fitMode = true; geometry(); };
    const state = () => {
      const values = [...pointers.values()];
      if (!values.length) return null;
      return { x: values.reduce((sum, p) => sum + p.x, 0) / values.length,
        y: values.reduce((sum, p) => sum + p.y, 0) / values.length,
        distance: values.length === 2 ? Math.hypot(values[1].x - values[0].x, values[1].y - values[0].y) : 0 };
    };
    const select = () => {
      observer?.disconnect();
      imageEvents?.abort();
      restore?.();
      restore = null;
      imageEvents = new AbortController();
      pointers.clear(); previous = null;
      image = root.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
      viewport = image?.closest('.review-image-link');
      tools.hidden = !active || !viewport;
      warning.hidden = true;
      if (image) image.loading = 'eager';
      if (!active || !viewport) return;
      // Restore native image/link semantics when leaving focus mode or changing
      // evidence. Repeated toggles must not leave transforms or listeners behind.
      const attributes = [[viewport, 'role'], [viewport, 'aria-label'], [image, 'style'], [image, 'draggable']]
        .map(([node, name]) => [node, name, node.getAttribute(name)]);
      const selectedViewport = viewport;
      restore = () => {
        for (const pointer of pointers.keys()) {
          if (selectedViewport.hasPointerCapture(pointer)) selectedViewport.releasePointerCapture(pointer);
        }
        attributes.forEach(([node, name, value]) => value === null ? node.removeAttribute(name) : node.setAttribute(name, value));
        ['zoomed', 'scale', 'dragging'].forEach(key => delete selectedViewport.dataset[key]);
      };
      viewport.setAttribute('role', 'region');
      viewport.setAttribute('aria-label', t('Bildansicht: Mausrad zum Zoomen, Ziehen zum Verschieben.'));
      image.draggable = false;
      const listen = (type, fn, options = {}) => viewport.addEventListener(type, fn, { ...options, signal: imageEvents.signal });
      const loaded = () => { warning.hidden = usable(); tools.hidden = !usable(); reset(); };
      image.addEventListener('load', loaded, { signal: imageEvents.signal });
      image.addEventListener('error', loaded, { signal: imageEvents.signal });
      if (image.complete) loaded();
      observer = new ResizeObserver(geometry);
      observer.observe(viewport);
      listen('click', (event) => { if (!event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) event.preventDefault(); });
      listen('dragstart', (event) => event.preventDefault());
      listen('dblclick', (event) => { event.preventDefault(); if (!moving()) Math.abs(scale - 1) < 0.01 ? reset() : zoom(1); });
      listen('wheel', (event) => {
        if (event.ctrlKey || event.metaKey || !usable()) return;
        event.preventDefault();
        zoom(scale * Math.exp(-Math.max(-120, Math.min(120, event.deltaY)) * 0.004), center(event));
      }, { passive: false });
      listen('pointerdown', (event) => {
        if (event.button !== 0 || !usable() || moving()) return;
        pointers.set(event.pointerId, center(event));
        previous = state();
        viewport.setPointerCapture(event.pointerId);
        viewport.dataset.dragging = 'true';
      });
      listen('pointermove', (event) => {
        if (!pointers.has(event.pointerId) || moving()) return;
        pointers.set(event.pointerId, center(event));
        const next = state();
        if (previous?.distance > 0 && next.distance > 0) zoom(scale * next.distance / previous.distance, previous);
        if (previous) { x += next.x - previous.x; y += next.y - previous.y; draw(); }
        previous = next;
      });
      const release = (event) => {
        pointers.delete(event.pointerId); previous = state();
        if (!pointers.size) delete viewport.dataset.dragging;
      };
      ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((type) => listen(type, release));
      listen('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey || moving()) return;
        if (event.key === '+' || event.key === '=') zoom(scale * 1.25);
        else if (event.key === '-') zoom(scale / 1.25);
        else if (event.key === '0') reset();
        else if (event.key === '1') zoom(1);
        else if (viewport.dataset.zoomed === 'true' && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
          if (event.key === 'ArrowLeft') x += 60;
          if (event.key === 'ArrowRight') x -= 60;
          if (event.key === 'ArrowUp') y += 60;
          if (event.key === 'ArrowDown') y -= 60;
          draw();
        } else return;
        event.preventDefault(); event.stopPropagation();
      });
    };
    root.querySelectorAll('[data-review-zoom]').forEach((button) => on(button, 'click', () => {
      if (moving()) return;
      const action = button.dataset.reviewZoom;
      if (action === 'fit') reset();
      if (action === 'actual') zoom(1);
      if (action === 'in') zoom(scale * 1.25);
      if (action === 'out') zoom(scale / 1.25);
    }));
    signal.addEventListener('abort', () => { observer?.disconnect(); imageEvents?.abort(); restore?.(); }, { once: true });
    select();
    return { select, setActive(value) { if (active !== value) { active = value; select(); } } };
  } };
})();
