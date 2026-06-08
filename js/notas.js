/* /js/notas.js
   Sistema de notas/postits del dietista:
   - "+" crea una nota vacía en el servidor y la pinta inmediatamente
   - El contenido se guarda con debounce (1s tras dejar de escribir) + en el blur
   - Cambio de color guarda al instante
   - "X" borra la nota
*/
(function () {
  'use strict';

  const grid = document.getElementById('notasGrid');
  if (!grid) return;
  const btnAdd = document.getElementById('btnAddNota');

  const CSRF     = grid.dataset.csrf;
  const BASE_URL = grid.dataset.base || '../../';
  const ENDPOINT = BASE_URL + 'ajax/ajax_notas.php';

  const HEADERS = {
    'X-Requested-With': 'XMLHttpRequest',
    'Accept':           'application/json'
  };

  const COLORS = ['amarillo','rosa','azul','verde','naranja','lila'];

  function escapeHTML(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function ahoraStr() {
    const d = new Date();
    const pad = n => String(n).padStart(2,'0');
    return pad(d.getDate()) + '/' + pad(d.getMonth()+1) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // Debounce por nota
  const debTimers = new Map();

  function debounce(id, fn, delay = 1000) {
    if (debTimers.has(id)) clearTimeout(debTimers.get(id));
    debTimers.set(id, setTimeout(() => { debTimers.delete(id); fn(); }, delay));
  }

  // ---- POST helper ----
  async function post(accion, params) {
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    for (const k in params) fd.append(k, params[k]);
    try {
      const r = await fetch(ENDPOINT + '?accion=' + accion, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: HEADERS
      });
      return await r.json();
    } catch (_) { return { ok: false, error: 'red' }; }
  }

  // ---- Hidratar nota existente con handlers ----
  function hidratarNota(article) {
    const id = parseInt(article.dataset.id, 10);
    if (!id) return;

    const textarea = article.querySelector('.nota-content');
    const btnDel   = article.querySelector('.nota-del');
    const colors   = article.querySelectorAll('.nota-color');
    const status   = article.querySelector('.nota-status');
    const fecha    = article.querySelector('.nota-fecha');

    // Marcar el color activo
    const colorActual = [...article.classList]
      .map(c => c.replace('nota--',''))
      .find(c => COLORS.includes(c)) || 'amarillo';
    article.querySelectorAll('.nota-color').forEach(b => {
      b.classList.toggle('is-active', b.dataset.c === colorActual);
    });

    function setStatus(txt, ok = true) {
      if (!status) return;
      status.textContent = txt;
      status.classList.toggle('is-error', !ok);
      if (txt) setTimeout(() => { if (status.textContent === txt) status.textContent = ''; }, 1800);
    }

    // Guardado de contenido (debounce 1s) + en blur (inmediato)
    function guardarContenido() {
      setStatus('Guardando…');
      post('editar', { id: id, contenido: textarea.value }).then(j => {
        if (j && j.ok) {
          setStatus('Guardado ✓');
          if (fecha) fecha.textContent = ahoraStr();
        } else setStatus('Error', false);
      });
    }

    if (textarea) {
      textarea.addEventListener('input', () => debounce(id, guardarContenido, 1000));
      textarea.addEventListener('blur', () => {
        if (debTimers.has(id)) { clearTimeout(debTimers.get(id)); debTimers.delete(id); }
        guardarContenido();
      });
    }

    // Cambio de color
    colors.forEach(btn => {
      btn.addEventListener('click', async () => {
        const c = btn.dataset.c;
        // Visual instantáneo
        COLORS.forEach(k => article.classList.remove('nota--' + k));
        article.classList.add('nota--' + c);
        colors.forEach(b => b.classList.toggle('is-active', b === btn));
        const j = await post('editar', { id: id, contenido: textarea.value, color: c });
        if (j && j.ok) { setStatus('Color guardado'); if (fecha) fecha.textContent = ahoraStr(); }
        else setStatus('Error', false);
      });
    });

    // Borrar
    if (btnDel) {
      btnDel.addEventListener('click', async () => {
        if (!confirm('¿Borrar esta nota? No se puede deshacer.')) return;
        article.style.transition = 'opacity .2s, transform .2s';
        article.style.opacity = '0';
        article.style.transform = 'scale(.9)';
        const j = await post('borrar', { id: id });
        if (j && j.ok) {
          setTimeout(() => {
            article.remove();
            comprobarVacio();
          }, 200);
        } else {
          article.style.opacity = '';
          article.style.transform = '';
          alert('No se pudo borrar la nota.');
        }
      });
    }
  }

  // ---- Crear elemento DOM de una nota (para notas nuevas) ----
  function crearElementoNota(id, color = 'amarillo', contenido = '') {
    const art = document.createElement('article');
    art.className = 'nota nota--' + color;
    art.dataset.id = id;
    art.innerHTML = `
      <header class="nota-head">
        <div class="nota-colors" role="group" aria-label="Cambiar color">
          ${COLORS.map(c => `<button type="button" class="nota-color nota-color--${c}" data-c="${c}" aria-label="${c}"></button>`).join('')}
        </div>
        <button type="button" class="nota-del" aria-label="Borrar nota">✕</button>
      </header>
      <textarea class="nota-content" placeholder="Escribe aquí…" maxlength="5000">${escapeHTML(contenido)}</textarea>
      <footer class="nota-foot">
        <span class="nota-status" aria-live="polite"></span>
        <span class="nota-fecha">${ahoraStr()}</span>
      </footer>
    `;
    return art;
  }

  // ---- Comprobar si hay que mostrar el "vacío" ----
  function comprobarVacio() {
    const notas = grid.querySelectorAll('.nota');
    let empty = document.getElementById('notasEmpty');
    if (notas.length === 0) {
      if (!empty) {
        empty = document.createElement('div');
        empty.className = 'notas-empty';
        empty.id = 'notasEmpty';
        empty.innerHTML = '<span class="notas-empty-icon" aria-hidden="true">📝</span><p>Aún no tienes notas. Pulsa <strong>«+»</strong> para crear la primera.</p>';
        grid.appendChild(empty);
      }
    } else if (empty) {
      empty.remove();
    }
  }

  // ---- Botón "+" añadir nueva nota ----
  btnAdd.addEventListener('click', async () => {
    // Elegir un color rotando para variedad
    const usados = [...grid.querySelectorAll('.nota')].slice(0, 6).map(n => {
      return [...n.classList].map(c => c.replace('nota--','')).find(c => COLORS.includes(c)) || 'amarillo';
    });
    const disponibles = COLORS.filter(c => !usados.includes(c));
    const color = disponibles.length ? disponibles[0] : COLORS[Math.floor(Math.random() * COLORS.length)];

    const j = await post('crear', { color: color });
    if (!j || !j.ok) { alert('No se pudo crear la nota. Revisa tu conexión.'); return; }

    const art = crearElementoNota(j.id, j.color || color, '');
    art.style.opacity = '0';
    art.style.transform = 'scale(.92)';

    // Quitar el "vacío" si existe
    const empty = document.getElementById('notasEmpty');
    if (empty) empty.remove();

    grid.insertBefore(art, grid.firstChild);
    hidratarNota(art);

    requestAnimationFrame(() => {
      art.style.transition = 'opacity .2s, transform .2s';
      art.style.opacity = '1';
      art.style.transform = 'scale(1)';
    });

    // Enfocar para empezar a escribir
    setTimeout(() => art.querySelector('.nota-content').focus(), 180);
  });

  // ---- Hidratar todas las notas que ya están en el DOM (SSR) ----
  grid.querySelectorAll('.nota').forEach(hidratarNota);
})();
