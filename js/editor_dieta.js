/* /js/editor_dieta.js — Editor de dietas */
(function () {
  'use strict';

  const $ = id => document.getElementById(id);
  const fmt = v => Math.round(v).toLocaleString('es-ES');

  let dieta    = { bloques: [] };
  let bbdd     = window.BBDD_ALIMENTOS || [];
  let clientes = window.CLIENTES_INFO  || [];
  let chart    = null;
  let editando = false;
  let modalBloqueIdx = -1;
  let modalSeleccionados = new Map();  // id_alimento → gramos

  // Estrategia inicial guardada en BD (se aplica TRAS llenarEstrategias)
  let estrInicial = null;

  const mapAli = {}; bbdd.forEach(a => { mapAli[a.id] = a; });
  const mapCli = {}; clientes.forEach(c => { mapCli[c.id] = c; });

  const COLOR_P = '#3A86C7', COLOR_C = '#2F9E73', COLOR_G = '#E0B628', COLOR_EMPTY = '#E5E7EB';

  function listen(id, ev, fn) { const el = $(id); if (el) el.addEventListener(ev, fn); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  document.addEventListener('DOMContentLoaded', function () {
    try {
      cargarDietaInicial();
      initChart();
      bindEventos();
      llenarEstrategias();
      aplicarEstrategiaGuardada();   // ← restaura los 3 dropdowns desde DIETA_INICIAL
      render();
      actualizarPreviewYAplicar(false);

      // Forzar recalcular() tras layout para que el donut se pinte con los colores reales
      // (soluciona el bug de "todos los colores iguales" al abrir una dieta existente)
      setTimeout(() => {
        try {
          if (chart) chart.resize();
          recalcular();
        } catch (_) {}
      }, 300);
    } catch (err) { console.error('[editor_dieta] init:', err); }
  });

  // ============================================================
  function cargarDietaInicial() {
    const d = window.DIETA_INICIAL;
    editando = !!(d && d.id);

    if ($('cli_single_wrap')) $('cli_single_wrap').hidden = !editando;
    if ($('cli_multi_wrap'))  $('cli_multi_wrap').hidden  = editando;

    if (d && d.nombre !== undefined) {
      if ($('in_nombre')) $('in_nombre').value = d.nombre || '';
      if ($('in_icono') && d.icono && $('in_icono').querySelector(`option[value="${CSS.escape(d.icono)}"]`)) {
        $('in_icono').value = d.icono;
      }
      if (/^#[0-9A-Fa-f]{6}$/.test(d.color || '') && $('in_color')) $('in_color').value = d.color;
      if ($('in_kcal')) $('in_kcal').value = Math.round(d.kcal_objetivo || 0);
      if ($('in_p'))    $('in_p').value    = Math.round(d.prot_objetivo  || 0);
      if ($('in_c'))    $('in_c').value    = Math.round(d.carb_objetivo  || 0);
      if ($('in_g'))    $('in_g').value    = Math.round(d.grasas_objetivo|| 0);
      if (d.id_cliente && $('in_cliente')) $('in_cliente').value = d.id_cliente;
      dieta.bloques = Array.isArray(d.bloques) && d.bloques.length ? d.bloques
                    : [{ nombre: 'Desayuno', alimentos: [] }];

      // Capturar la estrategia guardada (para aplicar después de llenarEstrategias)
      estrInicial = {
        base:       d.estr_base          || 'pond',
        deficit:    d.estr_deficit != null ? parseInt(d.estr_deficit, 10) : -10,
        estrategia: d.estr_estrategia_id != null ? parseInt(d.estr_estrategia_id, 10) : 0
      };
    } else {
      dieta.bloques = [
        { nombre: 'Desayuno', alimentos: [] },
        { nombre: 'Comida',   alimentos: [] },
        { nombre: 'Merienda', alimentos: [] },
        { nombre: 'Cena',     alimentos: [] }
      ];
      if (window.PRECARGAR_CLIENTE > 0) {
        const cb = document.querySelector('.in_cli_multi[value="' + window.PRECARGAR_CLIENTE + '"]');
        if (cb) cb.checked = true;
        const sel = $('in_cliente'); if (sel) sel.value = window.PRECARGAR_CLIENTE;
      }
    }
  }

  // Aplica los valores guardados de los 3 dropdowns (base / déficit / estrategia)
  function aplicarEstrategiaGuardada() {
    if (!estrInicial) return;
    const sB = $('estr_base');
    const sD = $('estr_deficit');
    const sE = $('estr_estrategia');
    if (sB && sB.querySelector('option[value="'+estrInicial.base+'"]')) sB.value = estrInicial.base;
    if (sD && sD.querySelector('option[value="'+estrInicial.deficit+'"]')) sD.value = String(estrInicial.deficit);
    if (sE && estrInicial.estrategia >= 0) {
      const opt = sE.querySelector('option[value="'+estrInicial.estrategia+'"]');
      if (opt) sE.value = String(estrInicial.estrategia);
    }
  }

  function bindEventos() {
    ['in_kcal','in_p','in_c','in_g'].forEach(id => {
      const el = $(id); if (!el) return;
      el.addEventListener('input', function () {
        el.classList.remove('is-auto'); recalcular();
      });
    });

    listen('btn_add_bloque', 'click', addBloque);
    listen('btn_guardar',    'click', guardar);
    listen('modal_close',    'click', cerrarModal);
    listen('modal_search',   'input', filtrarAlimentos);
    listen('modal_add',      'click', confirmarAddAlimentos);

    listen('estr_base',       'change', () => actualizarPreviewYAplicar(true));
    listen('estr_deficit',    'change', () => actualizarPreviewYAplicar(true));
    listen('estr_estrategia', 'change', () => actualizarPreviewYAplicar(true));

    listen('in_cliente', 'change', () => { llenarEstrategias(); actualizarPreviewYAplicar(false); });
    document.querySelectorAll('.in_cli_multi').forEach(cb => {
      cb.addEventListener('change', () => { llenarEstrategias(); actualizarPreviewYAplicar(false); });
    });

    const modal = $('modal_alimentos');
    if (modal) modal.addEventListener('click', e => { if (e.target === modal) cerrarModal(); });

    document.addEventListener('input', function (e) {
      const t = e.target;
      if (!t || !t.classList) return;
      if (t.classList.contains('ali-gr-input')) {
        const i = +t.dataset.i, j = +t.dataset.j;
        if (!dieta.bloques[i] || !dieta.bloques[i].alimentos[j]) return;
        dieta.bloques[i].alimentos[j].gramos = parseFloat(String(t.value).replace(',', '.')) || 0;
        actualizarMacrosDeAlimento(t, i, j);
        recalcular();
      }
    });

    document.addEventListener('change', function (e) {
      const t = e.target;
      if (t && t.classList && t.classList.contains('editor-bloque-name')) {
        const i = +t.dataset.i;
        if (dieta.bloques[i]) dieta.bloques[i].nombre = t.value;
      }
    });
  }

  // ============================================================
  function getIdsClientes() {
    if (editando) {
      const v = parseInt(($('in_cliente') || {}).value || 0, 10);
      return v > 0 ? [v] : [];
    }
    return Array.from(document.querySelectorAll('.in_cli_multi:checked'))
      .map(c => parseInt(c.value, 10)).filter(v => v > 0);
  }
  function clienteSeleccionado() {
    const ids = getIdsClientes();
    return ids.length ? mapCli[ids[0]] : null;
  }

  function llenarEstrategias() {
    const sel = $('estr_estrategia'); if (!sel) return;
    const cli = clienteSeleccionado();
    const valorPrevio = sel.value;
    sel.innerHTML = '';
    if (!cli) { sel.appendChild(new Option('— Selecciona cliente —', '0')); sel.disabled = true; return; }
    if (!cli.estrategias || !cli.estrategias.length) {
      sel.appendChild(new Option('— Sin estrategias guardadas —', '0')); sel.disabled = true; return;
    }
    sel.disabled = false;
    sel.appendChild(new Option('— Solo kcal (no calcular macros) —', '0'));
    cli.estrategias.forEach(e => {
      sel.appendChild(new Option(`${e.nombre} · P ${e.factor_p} / G ${e.factor_g}`, e.id));
    });
    // Conservar la selección previa si sigue siendo válida; si no, primera estrategia
    if (valorPrevio && sel.querySelector('option[value="'+valorPrevio+'"]')) {
      sel.value = valorPrevio;
    } else if (cli.estrategias.length) {
      sel.value = cli.estrategias[0].id;
    }
  }

  function actualizarPreviewYAplicar(aplicar) {
    const prev = $('estr_preview');
    const cli  = clienteSeleccionado();
    if (!cli) {
      if (prev) prev.innerHTML = '<span class="text-muted">Selecciona un cliente para ver su TDEE.</span>';
      return;
    }
    if (!cli.tdee_pond) {
      if (prev) prev.innerHTML = '<span class="text-muted">Este cliente no tiene anamnesis completa (faltan datos o peso registrado).</span>';
      return;
    }
    const baseKey = ($('estr_base') || {}).value || 'pond';
    const tdee    = baseKey === 'entreno'  ? cli.tdee_entreno
                  : baseKey === 'descanso' ? cli.tdee_descanso : cli.tdee_pond;
    const def     = parseFloat(($('estr_deficit') || {}).value) || 0;
    const kcal    = Math.round(tdee * (1 + def / 100));
    const idEstr  = parseInt(($('estr_estrategia') || {}).value || 0, 10);
    const estr    = (idEstr > 0 && cli.estrategias) ? cli.estrategias.find(e => e.id === idEstr) : null;

    const baseLbl = baseKey === 'entreno' ? 'Día entreno' : (baseKey === 'descanso' ? 'Día descanso' : 'Media ponderada');
    const signo   = def > 0 ? '+' + def : def;
    let txt = `${baseLbl}: <strong>${tdee} kcal</strong> · Con ${signo}%: <strong class="mc-k">${kcal} kcal</strong>`;
    if (estr && cli.peso) {
      const p = Math.round(estr.factor_p * cli.peso);
      const g = Math.round(estr.factor_g * cli.peso);
      const c = Math.max(0, Math.round((kcal - p*4 - g*9) / 4));
      txt += ` · <span class="mc-p">P ${p}g</span> · <span class="mc-c">C ${c}g</span> · <span class="mc-g">G ${g}g</span>`;
    }
    if (prev) prev.innerHTML = txt;

    if (!aplicar) return;

    if ($('in_kcal')) { $('in_kcal').value = kcal; $('in_kcal').classList.add('is-auto'); }
    if (estr && cli.peso) {
      const p = Math.round(estr.factor_p * cli.peso);
      const g = Math.round(estr.factor_g * cli.peso);
      const c = Math.max(0, Math.round((kcal - p*4 - g*9) / 4));
      if ($('in_p')) { $('in_p').value = p; $('in_p').classList.add('is-auto'); }
      if ($('in_g')) { $('in_g').value = g; $('in_g').classList.add('is-auto'); }
      if ($('in_c')) { $('in_c').value = c; $('in_c').classList.add('is-auto'); }
    }
    recalcular();
  }

  // ============================================================
  function guardar() {
    const nombre = ($('in_nombre') || {}).value || '';
    if (!nombre.trim()) { alert('Pon un nombre a la dieta.'); return; }

    const idsClientes = getIdsClientes();
    const idClienteLegacy = idsClientes.length ? idsClientes[0] : 0;

    const paquete = {
      nombre:             nombre.trim(),
      icono:              ($('in_icono') || {}).value || '🍽️',
      color:              ($('in_color') || {}).value || '#2F9E73',
      obj_kcal:           parseFloat(($('in_kcal') || {}).value) || 0,
      obj_p:              parseFloat(($('in_p')    || {}).value) || 0,
      obj_c:              parseFloat(($('in_c')    || {}).value) || 0,
      obj_g:              parseFloat(($('in_g')    || {}).value) || 0,
      id_cliente:         idClienteLegacy,
      ids_clientes:       idsClientes,
      bloques:            dieta.bloques,
      // === PERSISTENCIA DE LA ESTRATEGIA ===
      estr_base:          ($('estr_base')       || {}).value || 'pond',
      estr_deficit:       parseInt(($('estr_deficit') || {}).value, 10) || 0,
      estr_estrategia_id: parseInt(($('estr_estrategia') || {}).value, 10) || 0,
    };
    if (!editando && idsClientes.length > 1) {
      if (!confirm('Se crearán ' + idsClientes.length + ' copias de la dieta. ¿Continuar?')) return;
    }
    if ($('paquete_json')) $('paquete_json').value = JSON.stringify(paquete);
    const form = document.getElementById('editor-form');
    if (form) form.submit();
  }

  // ============================================================
  // RENDER BLOQUES
  // ============================================================
  function render() {
    const cont = $('bloques-container');
    if (!cont) return;

    const focused = document.activeElement;
    let focusKey = null;
    if (focused && focused.classList && focused.classList.contains('ali-gr-input')) {
      focusKey = focused.dataset.i + '-' + focused.dataset.j;
    }

    cont.innerHTML = '';
    dieta.bloques.forEach((b, iB) => {
      const div = document.createElement('div');
      div.className = 'editor-bloque card';
      div.innerHTML = `
        <header class="editor-bloque-head">
          <input type="text" class="editor-bloque-name" value="${esc(b.nombre)}" data-i="${iB}" placeholder="Nombre de la comida (ej: Desayuno)">
          <div class="editor-bloque-actions">
            <button type="button" class="bloque-arrow" data-act="up" data-i="${iB}" ${iB === 0 ? 'disabled' : ''} title="Subir">↑</button>
            <button type="button" class="bloque-arrow" data-act="down" data-i="${iB}" ${iB === dieta.bloques.length - 1 ? 'disabled' : ''} title="Bajar">↓</button>
            <button type="button" class="bloque-arrow bloque-arrow--del" data-act="del-bloque" data-i="${iB}" title="Borrar bloque">🗑️</button>
          </div>
        </header>
        <ul class="editor-alis" data-i="${iB}">
          ${b.alimentos.map((a, iA) => renderAlimento(a, iB, iA, b.alimentos.length)).join('')}
        </ul>
        <button type="button" class="btn btn-outline btn-mini" data-act="add-ali" data-i="${iB}">+ Añadir alimento</button>
        <footer class="editor-bloque-foot">
          <span class="text-muted">Subtotal:</span> ${renderMacrosLinea(calcBloque(b))}
        </footer>
      `;
      cont.appendChild(div);
    });

    cont.querySelectorAll('[data-act]').forEach(btn => btn.addEventListener('click', onAct));

    if (focusKey) {
      const [i, j] = focusKey.split('-');
      const el = cont.querySelector(`.ali-gr-input[data-i="${i}"][data-j="${j}"]`);
      if (el) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch(_){} }
    }

    recalcular();
  }

  function renderAlimento(a, iB, iA, total) {
    const al = mapAli[a.id_alimento];
    if (!al) return '';
    const baseGr = al.racion_base_gr || 100;
    const f = (a.gramos || 0) / baseGr;
    const kcal = Math.round(al.kcal * f);
    const p = al.proteinas * f, c = al.carbos * f, g = al.grasas * f;
    return `
      <li class="editor-ali">
        <div class="editor-ali-info">
          <div class="ali-name">
            <strong>${esc(al.nombre)}</strong>
            ${al.marca ? '<span class="text-muted ali-marca">'+esc(al.marca)+'</span>' : ''}
          </div>
          <div class="ali-macros" data-i="${iB}" data-j="${iA}">
            <span class="mc-k">${kcal} kcal</span>
            <span class="mc-p">${p.toFixed(1)}P</span>
            <span class="mc-c">${c.toFixed(1)}C</span>
            <span class="mc-g">${g.toFixed(1)}G</span>
          </div>
        </div>
        <div class="editor-ali-gr-wrap">
          <input type="text" inputmode="numeric" value="${a.gramos}" data-i="${iB}" data-j="${iA}" class="ali-gr-input" aria-label="Cantidad en gramos">
          <span class="ali-gr-unit">g</span>
        </div>
        <div class="editor-ali-actions">
          <button type="button" class="ali-arrow" data-act="ali-up"   data-i="${iB}" data-j="${iA}" ${iA === 0 ? 'disabled' : ''} title="Subir">↑</button>
          <button type="button" class="ali-arrow" data-act="ali-down" data-i="${iB}" data-j="${iA}" ${iA === total - 1 ? 'disabled' : ''} title="Bajar">↓</button>
          <button type="button" class="ali-arrow ali-arrow--del" data-act="del-ali" data-i="${iB}" data-j="${iA}" title="Eliminar">✕</button>
        </div>
      </li>
    `;
  }

  function actualizarMacrosDeAlimento(input, i, j) {
    const al = mapAli[dieta.bloques[i].alimentos[j].id_alimento]; if (!al) return;
    const f = (dieta.bloques[i].alimentos[j].gramos || 0) / (al.racion_base_gr || 100);
    const li = input.closest('.editor-ali'); if (!li) return;
    const wrap = li.querySelector('.ali-macros'); if (!wrap) return;
    const kcal = Math.round(al.kcal * f);
    const p = (al.proteinas * f).toFixed(1);
    const c = (al.carbos * f).toFixed(1);
    const g = (al.grasas * f).toFixed(1);
    wrap.innerHTML = `<span class="mc-k">${kcal} kcal</span> <span class="mc-p">${p}P</span> <span class="mc-c">${c}C</span> <span class="mc-g">${g}G</span>`;
  }

  function renderMacrosLinea(obj) {
    return `<span class="mc-k">${fmt(obj.k)} kcal</span> · <span class="mc-p">P ${obj.p.toFixed(1)}g</span> · <span class="mc-c">C ${obj.c.toFixed(1)}g</span> · <span class="mc-g">G ${obj.g.toFixed(1)}g</span>`;
  }

  function onAct(e) {
    const t = e.currentTarget;
    const act = t.dataset.act;
    const i = +t.dataset.i;
    const j = +t.dataset.j;

    if (act === 'up'   && i > 0) { [dieta.bloques[i-1], dieta.bloques[i]] = [dieta.bloques[i], dieta.bloques[i-1]]; render(); return; }
    if (act === 'down' && i < dieta.bloques.length - 1) { [dieta.bloques[i+1], dieta.bloques[i]] = [dieta.bloques[i], dieta.bloques[i+1]]; render(); return; }
    if (act === 'del-bloque') { if (confirm('¿Borrar este bloque?')) { dieta.bloques.splice(i, 1); render(); } return; }

    if (act === 'add-ali') { abrirModal(i); return; }

    if (act === 'del-ali')  { dieta.bloques[i].alimentos.splice(j, 1); render(); return; }
    if (act === 'ali-up'   && j > 0) {
      const arr = dieta.bloques[i].alimentos;
      [arr[j-1], arr[j]] = [arr[j], arr[j-1]];
      render(); return;
    }
    if (act === 'ali-down' && j < dieta.bloques[i].alimentos.length - 1) {
      const arr = dieta.bloques[i].alimentos;
      [arr[j+1], arr[j]] = [arr[j], arr[j+1]];
      render(); return;
    }
  }

  function addBloque() {
    dieta.bloques.push({ nombre: 'Nuevo bloque', alimentos: [] });
    render();
  }

  // ============================================================
  // MODAL ALIMENTOS — multi-select con checkboxes y gramos por item
  // ============================================================
  function abrirModal(iB) {
    modalBloqueIdx = iB;
    modalSeleccionados.clear();
    if ($('modal_search')) $('modal_search').value = '';
    actualizarBotonAdd();
    filtrarAlimentos();
    if ($('modal_alimentos')) {
      $('modal_alimentos').hidden = false;
      document.body.style.overflow = 'hidden';
    }
  }

  function cerrarModal() {
    if ($('modal_alimentos')) $('modal_alimentos').hidden = true;
    document.body.style.overflow = '';
  }

  function filtrarAlimentos() {
    const q = (($('modal_search') || {}).value || '').toLowerCase().trim();
    const lista = $('modal_results'); if (!lista) return;
    const filtrados = q
      ? bbdd.filter(a => (a.nombre + ' ' + (a.marca||'')).toLowerCase().includes(q))
      : bbdd.slice(0, 80);
    lista.innerHTML = filtrados.map(a => {
      const checked = modalSeleccionados.has(a.id);
      const gr      = modalSeleccionados.get(a.id) || 100;
      return `
        <li class="modal-ali ${checked ? 'is-checked' : ''}">
          <label class="modal-ali-check-wrap">
            <input type="checkbox" class="modal-ali-check" data-id="${a.id}" ${checked ? 'checked' : ''}>
            <div class="modal-ali-info">
              <strong>${esc(a.nombre)}</strong>
              ${a.marca ? '<span class="text-muted ali-marca">'+esc(a.marca)+'</span>' : ''}
              <div class="modal-ali-macros">
                <span class="mc-k">${a.kcal}kcal</span>
                <span class="mc-p">${a.proteinas}P</span>
                <span class="mc-c">${a.carbos}C</span>
                <span class="mc-g">${a.grasas}G</span>
                <span class="text-muted">/ ${a.racion_base_gr}g</span>
              </div>
            </div>
          </label>
          <div class="modal-ali-gr-wrap">
            <input type="text" inputmode="numeric" class="modal-ali-gr-input" data-id="${a.id}" value="${gr}" ${checked ? '' : 'disabled'}>
            <span class="ali-gr-unit">g</span>
          </div>
        </li>
      `;
    }).join('');

    lista.querySelectorAll('.modal-ali-check').forEach(cb => {
      cb.addEventListener('change', function (e) {
        const id = parseInt(e.target.dataset.id, 10);
        const li = e.target.closest('.modal-ali');
        const grInput = li.querySelector('.modal-ali-gr-input');
        if (e.target.checked) {
          const gr = parseFloat(grInput.value) || 100;
          modalSeleccionados.set(id, gr);
          grInput.disabled = false;
          li.classList.add('is-checked');
        } else {
          modalSeleccionados.delete(id);
          grInput.disabled = true;
          li.classList.remove('is-checked');
        }
        actualizarBotonAdd();
      });
    });

    lista.querySelectorAll('.modal-ali-gr-input').forEach(inp => {
      inp.addEventListener('input', function (e) {
        const id = parseInt(e.target.dataset.id, 10);
        if (!modalSeleccionados.has(id)) return;
        const v = parseFloat(String(e.target.value).replace(',', '.')) || 0;
        modalSeleccionados.set(id, v);
      });
    });
  }

  function actualizarBotonAdd() {
    const btn = $('modal_add'); if (!btn) return;
    const count = modalSeleccionados.size;
    btn.disabled = count === 0;
    const txt = $('modal_count');
    if (txt) txt.textContent = count;
    btn.innerHTML = count > 0
      ? `➕ Añadir <strong>${count}</strong> alimento${count===1?'':'s'} al bloque`
      : `Selecciona alimentos`;
  }

  function confirmarAddAlimentos() {
    if (modalSeleccionados.size === 0) return;
    modalSeleccionados.forEach((gr, id) => {
      if (gr > 0) dieta.bloques[modalBloqueIdx].alimentos.push({ id_alimento: id, gramos: gr });
    });
    cerrarModal();
    render();
  }

  // ============================================================
  function calcBloque(b) {
    let k = 0, p = 0, c = 0, g = 0;
    b.alimentos.forEach(a => {
      const al = mapAli[a.id_alimento]; if (!al) return;
      const f = (a.gramos || 0) / (al.racion_base_gr || 100);
      k += al.kcal * f; p += al.proteinas * f; c += al.carbos * f; g += al.grasas * f;
    });
    return { k, p, c, g };
  }

  function recalcular() {
    let k = 0, p = 0, c = 0, g = 0;
    dieta.bloques.forEach(b => {
      b.alimentos.forEach(a => {
        const al = mapAli[a.id_alimento]; if (!al) return;
        const f = (a.gramos || 0) / (al.racion_base_gr || 100);
        k += al.kcal * f; p += al.proteinas * f; c += al.carbos * f; g += al.grasas * f;
      });
    });

    const objK = parseFloat(($('in_kcal') || {}).value) || 0;
    const objP = parseFloat(($('in_p')    || {}).value) || 0;
    const objC = parseFloat(($('in_c')    || {}).value) || 0;
    const objG = parseFloat(($('in_g')    || {}).value) || 0;

    if ($('live_kcal'))       $('live_kcal').textContent       = Math.round(k);
    if ($('live_p'))          $('live_p').textContent          = p.toFixed(1);
    if ($('live_c'))          $('live_c').textContent          = c.toFixed(1);
    if ($('live_g'))          $('live_g').textContent          = g.toFixed(1);
    if ($('live_chart_kcal')) $('live_chart_kcal').textContent = Math.round(k);

    if ($('lbl_kcal')) $('lbl_kcal').textContent = '/ ' + Math.round(objK);
    if ($('lbl_p'))    $('lbl_p').textContent    = '/ ' + Math.round(objP) + 'g';
    if ($('lbl_c'))    $('lbl_c').textContent    = '/ ' + Math.round(objC) + 'g';
    if ($('lbl_g'))    $('lbl_g').textContent    = '/ ' + Math.round(objG) + 'g';

    setBar('bar_kcal', k, objK);
    setBar('bar_p',    p, objP);
    setBar('bar_c',    c, objC);
    setBar('bar_g',    g, objG);

    if ($('mini_kcal')) $('mini_kcal').textContent = Math.round(k) + '/' + Math.round(objK);
    if ($('mini_p'))    $('mini_p').textContent    = Math.round(p) + '/' + Math.round(objP);
    if ($('mini_c'))    $('mini_c').textContent    = Math.round(c) + '/' + Math.round(objC);
    if ($('mini_g'))    $('mini_g').textContent    = Math.round(g) + '/' + Math.round(objG);

    if (chart) {
      if (p === 0 && c === 0 && g === 0) {
        chart.data.datasets[0].data = [1, 1, 1];
        chart.data.datasets[0].backgroundColor = [COLOR_EMPTY, COLOR_EMPTY, COLOR_EMPTY];
      } else {
        chart.data.datasets[0].data = [p, c, g];
        chart.data.datasets[0].backgroundColor = [COLOR_P, COLOR_C, COLOR_G];
      }
      chart.update('none');
    }
  }

  function setBar(id, cur, obj) {
    const el = $(id); if (!el) return;
    const pct = obj > 0 ? Math.min(100, (cur / obj) * 100) : 0;
    el.style.width = pct + '%';
  }

  // Suma de macros de TODOS los bloques (para inicializar el chart con datos reales)
  function totalesMacros() {
    let p = 0, c = 0, g = 0;
    dieta.bloques.forEach(b => {
      b.alimentos.forEach(a => {
        const al = mapAli[a.id_alimento]; if (!al) return;
        const f = (a.gramos || 0) / (al.racion_base_gr || 100);
        p += al.proteinas * f; c += al.carbos * f; g += al.grasas * f;
      });
    });
    return { p, c, g };
  }

  function initChart() {
    if (typeof Chart === 'undefined') return;
    const ctx = $('macroChart'); if (!ctx) return;

    // Calcular macros reales ANTES de crear el chart (los bloques ya están cargados)
    const t = totalesMacros();
    const vacio = (t.p === 0 && t.c === 0 && t.g === 0);
    const dataIni  = vacio ? [1, 1, 1] : [t.p, t.c, t.g];
    const colorIni = vacio ? [COLOR_EMPTY, COLOR_EMPTY, COLOR_EMPTY] : [COLOR_P, COLOR_C, COLOR_G];

    chart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Proteínas', 'Carbos', 'Grasas'],
        datasets: [{
          data: dataIni,
          backgroundColor: colorIni,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        animation: { duration: 300 },
        plugins: { legend: { display: false }, tooltip: { enabled: true } }
      }
    });
  }
})();
