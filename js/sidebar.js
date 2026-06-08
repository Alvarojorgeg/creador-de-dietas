/* /js/sidebar.js
   Drawer + notificaciones (con borrar) + tema claro/oscuro. */
(function () {
  'use strict';

  // =====================================================
  // TEMA (claro/oscuro)
  // =====================================================
  function aplicarTema(t) {
    const root = document.documentElement;
    if (t === 'dark') root.setAttribute('data-theme', 'dark');
    else root.removeAttribute('data-theme');
    try { localStorage.setItem('dietista_theme', t); } catch (_) {}
  }
  function temaActual() {
    try {
      const t = localStorage.getItem('dietista_theme');
      return t === 'dark' ? 'dark' : 'light';
    } catch (_) { return 'light'; }
  }
  function alternarTema() {
    const nuevo = temaActual() === 'dark' ? 'light' : 'dark';
    aplicarTema(nuevo);
    document.dispatchEvent(new CustomEvent('themechange', { detail: nuevo }));
    return nuevo;
  }
  aplicarTema(temaActual());
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      document.documentElement.classList.add('theme-ready');
    });
  });
  window.AppTheme = { get: temaActual, set: aplicarTema, toggle: alternarTema };

  // =====================================================
  // DRAWER lateral
  // =====================================================
  const drawer    = document.getElementById('appDrawer');
  const backdrop  = document.getElementById('appDrawerBackdrop');
  const btnOpen   = document.getElementById('btnAbrirMenu');
  const btnClose  = document.getElementById('btnCerrarMenu');

  function abrirMenu() {
    drawer.classList.add('is-open');
    backdrop.hidden = false;
    requestAnimationFrame(() => backdrop.classList.add('is-visible'));
    drawer.setAttribute('aria-hidden', 'false');
    btnOpen && btnOpen.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }
  function cerrarMenu() {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-visible');
    setTimeout(() => { backdrop.hidden = true; }, 200);
    drawer.setAttribute('aria-hidden', 'true');
    btnOpen && btnOpen.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }
  btnOpen   && btnOpen.addEventListener('click',  abrirMenu);
  btnClose  && btnClose.addEventListener('click', cerrarMenu);
  backdrop  && backdrop.addEventListener('click', cerrarMenu);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarMenu(); });

  // =====================================================
  // NOTIFICACIONES
  // =====================================================
  const btnNotis    = document.getElementById('btnNotis');
  const btnCerrarN  = document.getElementById('btnNotisCerrar');
  const panel       = document.getElementById('notisPanel');
  const lista       = document.getElementById('notisList');
  const badge       = document.getElementById('notisBadge');

  const SCRIPT_DIR = new URL(document.currentScript ? document.currentScript.src : (function () {
    const ss = document.scripts; return ss[ss.length - 1].src;
  })()).pathname.replace(/\/[^\/]*$/, '/');
  const URL_NOTIS = SCRIPT_DIR.replace(/\/js\/$/, '/') + 'ajax/ajax_notificaciones.php';

  function escapeHTML(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  async function cargarNotis() {
    try {
      const r = await fetch(URL_NOTIS + '?accion=listar', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await r.json();
      renderNotis(data);
    } catch (err) {
      if (lista) lista.innerHTML = '<li class="notis-empty">Error al cargar.</li>';
    }
  }

  function renderNotis(data) {
    if (!lista || !badge) return;
    const items = (data && data.items) || [];
    if (!items.length) {
      lista.innerHTML = '<li class="notis-empty">No tienes notificaciones.</li>';
    } else {
      let html = '';
      items.forEach(n => {
        const urlAttr = n.url ? ` data-url="${escapeHTML(n.url)}"` : '';
        html += `
          <li class="notis-item${n.leida ? '' : ' is-unread'}" data-id="${n.id}"${urlAttr}>
            <div class="notis-item-icon" aria-hidden="true">${escapeHTML(n.icono || '🔔')}</div>
            <div class="notis-item-body">
              <div class="notis-item-text">${escapeHTML(n.texto)}</div>
              <div class="notis-item-time">${escapeHTML(n.tiempo)}</div>
            </div>
            <button type="button" class="notis-item-del" aria-label="Borrar notificación" title="Borrar">✕</button>
          </li>
        `;
      });
      // Botón "Borrar todas" al final si hay 2+
      if (items.length >= 2) {
        html += `
          <li class="notis-item-clearall">
            <button type="button" class="btn btn-ghost btn-mini" id="btnNotisBorrarTodas">🗑️ Borrar todas</button>
          </li>
        `;
      }
      lista.innerHTML = html;

      // Handlers de borrado
      lista.querySelectorAll('.notis-item-del').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          const li = btn.closest('.notis-item');
          if (!li) return;
          borrarNoti(parseInt(li.dataset.id, 10), li);
        });
      });

      // Click en el cuerpo de la noti = navegar si tiene URL
      lista.querySelectorAll('.notis-item').forEach(li => {
        li.addEventListener('click', e => {
          if (e.target.closest('.notis-item-del')) return;
          const url = li.dataset.url;
          if (url) window.location.href = url;
        });
      });

      // Botón "Borrar todas"
      const btnAll = document.getElementById('btnNotisBorrarTodas');
      if (btnAll) {
        btnAll.addEventListener('click', () => {
          if (!confirm('¿Borrar TODAS tus notificaciones?')) return;
          borrarTodasNotis();
        });
      }
    }

    const noLeidas = (data && data.no_leidas) || 0;
    if (noLeidas > 0) {
      badge.hidden = false;
      badge.textContent = noLeidas > 9 ? '9+' : String(noLeidas);
    } else badge.hidden = true;
  }

  async function borrarNoti(id, li) {
    if (!id) return;
    const fd = new FormData();
    fd.append('id', id);
    try {
      const r = await fetch(URL_NOTIS + '?accion=borrar', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const j = await r.json();
      if (j && j.ok) {
        li.style.opacity = '0';
        setTimeout(() => { cargarNotis(); }, 150);
      }
    } catch (_) {}
  }

  async function borrarTodasNotis() {
    try {
      const r = await fetch(URL_NOTIS + '?accion=borrar_todas', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const j = await r.json();
      if (j && j.ok) cargarNotis();
    } catch (_) {}
  }

  async function marcarLeidas() {
    try {
      await fetch(URL_NOTIS + '?accion=marcar_leidas', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
    } catch (_) {}
  }

  function abrirNotis() {
    panel.hidden = false;
    requestAnimationFrame(() => panel.classList.add('is-open'));
    panel.setAttribute('aria-hidden', 'false');
    cargarNotis().then(marcarLeidas);
  }
  function cerrarNotis() {
    panel.classList.remove('is-open');
    setTimeout(() => { panel.hidden = true; }, 180);
    panel.setAttribute('aria-hidden', 'true');
  }
  btnNotis   && btnNotis.addEventListener('click', () => panel.hidden ? abrirNotis() : cerrarNotis());
  btnCerrarN && btnCerrarN.addEventListener('click', cerrarNotis);
  document.addEventListener('click', e => {
    if (!panel || panel.hidden) return;
    if (!panel.contains(e.target) && e.target !== btnNotis && !(btnNotis && btnNotis.contains(e.target))) cerrarNotis();
  });

  if (panel) {
    cargarNotis();
    setInterval(cargarNotis, 60000);
  }
})();