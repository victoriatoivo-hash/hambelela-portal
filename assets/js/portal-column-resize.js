(() => {
  const boundHandles = new WeakMap();
  let suppressClickUntil = 0;

  const stopEvent = (event) => {
    event.preventDefault();
    event.stopPropagation();
  };

  function bindHandle(handle, options = {}) {
    if (!handle || boundHandles.has(handle)) return boundHandles.get(handle)?.destroy || (() => {});

    const key = String(options.key || handle.dataset.columnKey || '');
    const readWidth = () => Number(options.readWidth?.(key, handle)) || handle.parentElement?.getBoundingClientRect().width || 0;
    const applyWidth = (width) => {
      const next = Number(options.clampWidth?.(key, width) ?? width);
      if (!Number.isFinite(next)) return;
      options.applyWidth?.(key, next, handle);
      handle.setAttribute('aria-valuenow', String(Math.round(next)));
    };
    let startX = 0;
    let startWidth = 0;
    let pointerId = null;
    let moved = false;

    const finish = (event) => {
      document.removeEventListener('pointermove', move, true);
      document.removeEventListener('pointerup', finish, true);
      document.removeEventListener('pointercancel', finish, true);
      handle.classList.remove('is-resizing', 'is-active');
      document.body.classList.remove('is-resizing-column', 'is-resizing-portal-column');
      if (pointerId !== null && handle.hasPointerCapture?.(pointerId)) handle.releasePointerCapture(pointerId);
      if (moved) suppressClickUntil = performance.now() + 180;
      pointerId = null;
      options.onCommit?.(key, handle, event);
    };

    const move = (event) => {
      if (pointerId !== null && event.pointerId !== pointerId) return;
      const delta = event.clientX - startX;
      if (Math.abs(delta) > 2) moved = true;
      applyWidth(startWidth + delta);
      stopEvent(event);
    };

    const pointerDown = (event) => {
      if (event.button !== undefined && event.button !== 0) return;
      startX = event.clientX;
      startWidth = readWidth();
      pointerId = event.pointerId;
      moved = false;
      handle.classList.add('is-resizing', 'is-active');
      document.body.classList.add('is-resizing-column', 'is-resizing-portal-column');
      handle.setPointerCapture?.(event.pointerId);
      document.addEventListener('pointermove', move, true);
      document.addEventListener('pointerup', finish, true);
      document.addEventListener('pointercancel', finish, true);
      stopEvent(event);
    };

    const keyDown = (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      const step = event.shiftKey ? 25 : 10;
      applyWidth(readWidth() + (event.key === 'ArrowRight' ? step : -step));
      options.onCommit?.(key, handle, event);
      stopEvent(event);
    };

    const doubleClick = (event) => {
      if (!options.autoFit) return;
      const width = options.autoFit(key, handle);
      if (Number.isFinite(Number(width))) {
        applyWidth(Number(width));
        options.onCommit?.(key, handle, event);
      }
      stopEvent(event);
    };

    const click = (event) => {
      if (performance.now() <= suppressClickUntil || event.target === handle) stopEvent(event);
    };

    handle.addEventListener('pointerdown', pointerDown);
    handle.addEventListener('keydown', keyDown);
    handle.addEventListener('dblclick', doubleClick);
    handle.addEventListener('click', click);

    const destroy = () => {
      handle.removeEventListener('pointerdown', pointerDown);
      handle.removeEventListener('keydown', keyDown);
      handle.removeEventListener('dblclick', doubleClick);
      handle.removeEventListener('click', click);
      boundHandles.delete(handle);
    };
    boundHandles.set(handle, { destroy });
    return destroy;
  }

  function deviceKey() {
    const width = Math.round(Number(window.screen?.width || window.innerWidth || 0));
    const height = Math.round(Number(window.screen?.height || window.innerHeight || 0));
    const scale = Number(window.devicePixelRatio || 1).toFixed(2);
    return `${width}x${height}@${scale}`;
  }

  window.PortalColumnResize = Object.freeze({ bindHandle, deviceKey });
})();
