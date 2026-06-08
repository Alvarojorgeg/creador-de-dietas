/* /js/swaps.js
   Intercambio de alimentos: pulsa el botón "🔄" de un alimento de la dieta
   y abre un modal con alternativas equivalentes en macros, calculadas
   por el endpoint /ajax/ajax_alternativas.php.
*/
(function () {
  'use strict';

  // Localizar todos los items con info de alimento
  const items = document.querySelectorAll('.meal-item[data-id-alimento]');
  if (!items.length) return;

  const modal = document.getElementById('modalSwap');
  if (!modal) return;
  const body  = document.getElementById('modalSwapBody');
  const close = document.getElementById('modalSwapClose');
  const title = document.getElementById('modalSwapTitle');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  function openModal() {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.hidden = true;
    document.body.style.overflow = '';
  }
  close.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

  // Detectar base URL (para que funcione desde /roles/cliente/*)
  // El script se carga con ruta relativa "../../js/swaps.js" desde cliente_dieta.php
  // así que para el AJAX usamos también prefijo relativo
  const scriptEl = document.currentScript || (function(){ const s = document.scripts; return s[s.length-1]; })();
  const scriptPath = scriptEl ? new URL(scriptEl.src).pathname : '';
  const base = scriptPath.replace(/\/js\/swaps\.js$/, '/');
  const ENDPOINT = base + 'ajax/ajax_alternativas.php';

  // Inyectar botones en cada item
  items.forEach(li => {
    if (li.querySelector('.meal-swap-btn')) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'meal-swap-btn';
    btn.setAttribute('aria-label', 'Ver alternativas equivalentes');
    btn.title = 'Ver alternativas';
    btn.innerHTML = '🔄 <span>Alternativas</span>';
    btn.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      cargarAlternativas(li);
    });
    li.appendChild(btn);
  });

  async function cargarAlternativas(li) {
    const idA  = parseInt(li.dataset.idAlimento, 10);
    const cant = parseFloat(li.dataset.cantidad);
    const nombre = li.querySelector('.meal-item-name')?.textContent || '';

    title.textContent = '🔄 Alternativas para ' + nombre;
    body.innerHTML = '<p class="text-muted text-center">Buscando alimentos equivalentes…</p>';
    openModal();

    let json;
    try {
      const r = await fetch(`${ENDPOINT}?id_alimento=${idA}&cantidad=${cant}`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      json = await r.json();
    } catch (_) {
      body.innerHTML = '<p class="text-danger text-center">Error de conexión. Inténtalo de nuevo.</p>';
      return;
    }

    if (!json || !json.ok) {
      body.innerHTML = '<p class="text-muted text-center">No se han podido cargar las alternativas.</p>';
      return;
    }

    renderModal(json);
  }

  function renderModal(d) {
    const o = d.original;
    const alts = d.alternativas || [];

    let html = `
      <section class="swap-original">
        <p class="swap-eyebrow">Tu alimento</p>
        <h4 class="swap-original-name">${esc(o.nombre)}${o.marca ? ' <span class="text-muted">·  '+esc(o.marca)+'</span>' : ''}</h4>
        <div class="swap-macros">
          <span class="swap-macro"><strong>${o.cantidad}g</strong></span>
          <span class="swap-macro"><strong>${o.kcal}</strong> kcal</span>
          <span class="swap-macro swap-macro--p"><strong>${o.p}g</strong> P</span>
          <span class="swap-macro swap-macro--c"><strong>${o.c}g</strong> C</span>
          <span class="swap-macro swap-macro--g"><strong>${o.g}g</strong> G</span>
        </div>
        <p class="swap-dominant">💡 Igualamos la <strong>${esc(o.dom_label)}</strong> como referencia principal.</p>
      </section>

      <h4 class="swap-list-title">${alts.length} alternativa${alts.length === 1 ? '' : 's'}</h4>
    `;

    if (alts.length === 0) {
      html += '<p class="text-muted text-center" style="padding: 1em 0;">No hemos encontrado alimentos equivalentes con las mismas macros en la base de datos.</p>';
    } else {
      html += '<ul class="swap-list" role="list">';
      alts.forEach(a => {
        const scorePct = Math.round(a.score * 100);
        const scoreClass = a.score >= 0.85 ? 'is-great' : (a.score >= 0.65 ? 'is-good' : 'is-medium');
        const kcalDiffSign = a.kcal_diff > 0 ? '+' : '';
        const kcalDiffClass = Math.abs(a.kcal_diff) <= 30 ? 'is-ok' : (a.kcal_diff > 0 ? 'is-more' : 'is-less');

        html += `
          <li class="swap-item">
            <div class="swap-item-head">
              <div class="swap-item-name">
                <strong>${esc(a.nombre)}</strong>
                ${a.marca ? '<span class="text-muted">·  '+esc(a.marca)+'</span>' : ''}
              </div>
              <span class="swap-score swap-score--${scoreClass}" title="Similitud de perfil macro">${scorePct}%</span>
            </div>
            <div class="swap-qty">
              <span class="swap-qty-num">${a.cantidad} g</span>
              <span class="text-muted">para igualar ${esc(o.dom_label)}</span>
            </div>
            <div class="swap-macros">
              <span class="swap-macro"><strong>${a.kcal}</strong> kcal
                <span class="swap-kdiff swap-kdiff--${kcalDiffClass}">(${kcalDiffSign}${a.kcal_diff})</span>
              </span>
              <span class="swap-macro swap-macro--p"><strong>${a.p}g</strong> P</span>
              <span class="swap-macro swap-macro--c"><strong>${a.c}g</strong> C</span>
              <span class="swap-macro swap-macro--g"><strong>${a.g}g</strong> G</span>
            </div>
          </li>
        `;
      });
      html += '</ul>';

      html += '<p class="text-muted swap-footnote">💡 La similitud se calcula comparando el reparto entre proteínas, carbohidratos y grasas. 100% = perfil idéntico.</p>';
    }

    body.innerHTML = html;
  }
})();
