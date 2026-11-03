(function () {
  'use strict';

  const bar = document.getElementById('tpma-identity-bar');
  if (!bar) return;

  const handle = bar.querySelector('.tpma-identity-bar__handle');
  const collapse = bar.querySelector('.tpma-identity-bar__collapse');
  const expand = bar.querySelector('.tpma-identity-bar__expand');
  const storageKey = 'tpma_identity_bar_v1';
  const margin = 8;
  let state = { collapsed: false, x: null, y: null };
  let drag = null;

  try {
    const saved = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
    if (saved && typeof saved === 'object') {
      state.collapsed = saved.collapsed === true;
      if (Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
        state.x = saved.x;
        state.y = saved.y;
      }
    }
  } catch (error) {
    // Browser storage can be unavailable; the controls still work for this page.
  }

  function save() {
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(state));
    } catch (error) {
      // Keep the in-memory state when storage is blocked.
    }
  }

  function clamp(value, size, viewport) {
    return Math.min(Math.max(margin, value), Math.max(margin, viewport - size - margin));
  }

  function setPosition(x, y) {
    state.x = clamp(x, bar.offsetWidth, window.innerWidth);
    state.y = clamp(y, bar.offsetHeight, window.innerHeight);
    bar.style.left = state.x + 'px';
    bar.style.top = state.y + 'px';
    bar.style.right = 'auto';
    bar.style.bottom = 'auto';
  }

  function setCollapsed(value) {
    state.collapsed = value;
    bar.dataset.collapsed = value ? 'true' : 'false';
    expand.hidden = !value;
    if (value) {
      bar.style.left = '';
      bar.style.top = '';
      bar.style.right = '';
      bar.style.bottom = '';
    } else if (state.x !== null && state.y !== null) {
      setPosition(state.x, state.y);
    }
    save();
  }

  collapse.addEventListener('click', function () {
    setCollapsed(true);
    expand.focus();
  });
  expand.addEventListener('click', function () {
    setCollapsed(false);
    collapse.focus();
  });

  handle.addEventListener('keydown', function (event) {
    const offsets = {
      ArrowLeft: [-1, 0], ArrowRight: [1, 0],
      ArrowUp: [0, -1], ArrowDown: [0, 1]
    };
    const offset = offsets[event.key];
    if (!offset) return;
    event.preventDefault();
    const rect = bar.getBoundingClientRect();
    const step = event.shiftKey ? 40 : 10;
    setPosition(rect.left + offset[0] * step, rect.top + offset[1] * step);
    save();
  });

  handle.addEventListener('pointerdown', function (event) {
    if (event.button !== 0 || state.collapsed) return;
    const rect = bar.getBoundingClientRect();
    drag = { pointerId: event.pointerId, dx: event.clientX - rect.left, dy: event.clientY - rect.top };
    handle.setPointerCapture(event.pointerId);
    event.preventDefault();
  });

  handle.addEventListener('pointermove', function (event) {
    if (!drag || event.pointerId !== drag.pointerId) return;
    setPosition(event.clientX - drag.dx, event.clientY - drag.dy);
  });

  function endDrag(event) {
    if (!drag || event.pointerId !== drag.pointerId) return;
    drag = null;
    save();
  }

  handle.addEventListener('pointerup', endDrag);
  handle.addEventListener('pointercancel', endDrag);

  window.addEventListener('resize', function () {
    if (state.x !== null && state.y !== null && !state.collapsed) {
      setPosition(state.x, state.y);
      save();
    }
  });

  setCollapsed(state.collapsed);
}());
