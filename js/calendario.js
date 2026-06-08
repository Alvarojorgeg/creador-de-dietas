/* /js/calendario.js
   Calendario real-time dietista (drag-paint) + cliente (vista).
   - Paleta compacta. Click en una dieta = seleccionada. Click en la misma = deseleccionada (modo borrar).
   - Predicción kg/semana y kg/mes basada en (kcal dieta − TDEE).
   - Estado interno silencioso (sin pill visible). Solo log a consola en errores.
*/
(function () {
  'use strict';

  const D = window.CAL_DATA;
  if (!D) { console.error('[Calendario] window.CAL_DATA no definido'); return; }
  const isDietista = D.mode === 'dietista';

  const state = {
    mes:              D.mes_inicial,
    primer_dow:       1,
    dias_mes:         30,
    today:            '',
    asignaciones:     {},
    dietas_disp:      [],
    tdee:             { entreno: 0, descanso: 0, pond: 0 },
    selectedDietaId:  null,
  };

  let isDragging   = false;
  let dragMoved    = false;
  let dragStartXY  = null;
  let dragTouched  = new Set();
  let dragMode     = 'paint';

  const $  = id => document.getElementById(id);
  const escapeHtml = s => String(s == null ? '' : s).replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));

  function hexToRgba(hex, alpha) {
    if (!hex || typeof hex !== 'string') return `rgba(47,158,115,${alpha})`;
    hex = hex.replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c+c).join('');
    if (!/^[0-9a-f]{6}$/i.test(hex)) return `rgba(47,158,115,${alpha})`;
    const r = parseInt(hex.substr(0,2), 16);
    const g = parseInt(hex.substr(2,2), 16);
    const b = parseInt(hex.substr(4,2), 16);
    return `rgba(${r},${g},${b},${alpha})`;
  }

  // Sin pill visible: solo log en consola si algo va mal
  function reportarError(msg) { console.warn('[Calendario]', msg); }

  const MESES_ES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  function nombreMes(mesStr) {
    const [y, m] = mesStr.split('-').map(Number);
    return MESES_ES[m-1].charAt(0).toUpperCase() + MESES_ES[m-1].slice(1) + ' ' + y;
  }
  function mesActual() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
  }
  function urlAjax(accion) {
    return D.base_url + 'ajax/ajax_calendario.php?accion=' + accion + '&id_cliente=' + D.id_cliente;
  }

  async function fetchJson(url, opts) {
    let res;
    try { res = await fetch(url, opts); }
    catch (e) { console.error('[Calendario] red:', url, e); throw new Error('red'); }

    const status = res.status;
    let bodyText = '';
    try { bodyText = await res.text(); } catch (_) {}
    let j;
    try { j = JSON.parse(bodyText); }
    catch (_) {
      console.error('[Calendario] respuesta no es JSON (HTTP '+status+'):', url, bodyText.slice(0,2000));
      throw new Error('HTTP ' + status);
    }
    if (!res.ok) { console.error('[Calendario] HTTP '+status+':', url, j); throw new Error((j.error||'http')+(j.detail?' · '+j.detail:'')); }
    if (!j.ok)   { console.error('[Calendario] ok=false:', url, j); throw new Error((j.error||'server')+(j.detail?' · '+j.detail:'')); }
    return j;
  }

  // ============================================================
  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    bindEventos();
    await cargarMes(state.mes);
  }

  function bindEventos() {
    if ($('calPrev'))  $('calPrev').addEventListener('click', () => irMes(-1));
    if ($('calNext'))  $('calNext').addEventListener('click', () => irMes(1));
    if ($('calMonthLabel')) $('calMonthLabel').addEventListener('click', () => cargarMes(mesActual()));

    if (isDietista) {
      if ($('btnClearAll')) $('btnClearAll').addEventListener('click', clearAll);
      const grid = $('calGrid');
      if (grid) {
        grid.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerUp);
        grid.addEventListener('selectstart', e => { if (isDragging) e.preventDefault(); });
      }
    } else {
      const grid = $('calGrid');
      if (grid) {
        grid.addEventListener('click', e => {
          const cell = e.target.closest('.cal-cell');
          if (!cell || cell.classList.contains('cal-cell--empty')) return;
          if (cell.dataset.fecha) openDayModal(cell.dataset.fecha);
        });
      }

      if ($('btnAbrirCalendario') && $('calModal')) {
        $('btnAbrirCalendario').addEventListener('click', () => {
          $('calModal').hidden = false;
          document.body.style.overflow = 'hidden';
        });
        $('calModalClose').addEventListener('click', cerrarModalCalendario);
        $('calModal').addEventListener('click', e => { if (e.target === $('calModal')) cerrarModalCalendario(); });
      }
    }

    const modalDia = $('modalDia');
    if (modalDia) {
      $('modalDiaClose').addEventListener('click', closeDayModal);
      modalDia.addEventListener('click', e => { if (e.target === modalDia) closeDayModal(); });
    }

    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;
      if (modalDia && !modalDia.hidden) closeDayModal();
      else if (!isDietista && $('calModal') && !$('calModal').hidden) cerrarModalCalendario();
    });
  }

  function cerrarModalCalendario() {
    if (!$('calModal')) return;
    $('calModal').hidden = true;
    document.body.style.overflow = '';
  }

  // ============================================================
  // CARGAR MES
  // ============================================================
  function irMes(delta) {
    const [y, m] = state.mes.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    cargarMes(next);
  }

  async function cargarMes(mes) {
    try {
      const j = await fetchJson(urlAjax('mes') + '&mes=' + mes);
      state.mes          = j.mes;
      state.primer_dow   = j.primer_dow;
      state.dias_mes     = j.dias_mes;
      state.today        = j.today;
      state.asignaciones = j.asignaciones || {};
      state.dietas_disp  = j.dietas_disp  || [];
      state.tdee         = j.tdee || { entreno:0, descanso:0, pond:0 };

      if (state.selectedDietaId !== null &&
          !state.dietas_disp.some(d => d.id === state.selectedDietaId)) {
        state.selectedDietaId = null;
      }

      renderAll();
    } catch (err) {
      reportarError('cargarMes: ' + err.message);
    }
  }

  function renderAll() {
    renderMes();
    if (isDietista) renderPaleta();
    renderPrediccion();

    // Evento custom para que otros widgets (p.ej. tarjeta de predicciones del dashboard)
    // puedan reaccionar al cambio de mes.
    console.log('[CAL] dispatch calendar:mes-cambiado mes=' + state.mes);
    document.dispatchEvent(new CustomEvent('calendar:mes-cambiado', {
      detail: {
        mes:    state.mes,
        today:  state.today,
        modo:   state.modo
      }
    }));
  }

  // ============================================================
  // PREDICCIÓN kg/semana y kg/mes
  // ============================================================
  function renderPrediccion() {
    const wrap = $('calPred');
    if (!wrap) return;

    const tdeePond = parseFloat(state.tdee.pond) || 0;
    if (tdeePond <= 0) { wrap.hidden = true; return; }

    // ===== MES (el mes que se está viendo en pantalla) =====
    let balanceMes = 0;
    let diasMes = 0;
    for (const fecha in state.asignaciones) {
      const asigns = state.asignaciones[fecha] || [];
      if (asigns.length === 0) continue;
      const kcal = asigns[0].kcal_objetivo || 0;
      balanceMes += (kcal - tdeePond);
      diasMes++;
    }
    const kgMes = balanceMes / 7700;

    // ===== SEMANA (lunes-domingo de HOY) =====
    const hoy = new Date(state.today + 'T00:00:00');
    const dow = (hoy.getDay() + 6) % 7;  // 0=lunes
    const lunes = new Date(hoy);
    lunes.setDate(lunes.getDate() - dow);
    let balanceSem = 0;
    let diasSem = 0;
    for (let i = 0; i < 7; i++) {
      const d = new Date(lunes);
      d.setDate(d.getDate() + i);
      const fechaStr = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      const asigns = state.asignaciones[fechaStr] || [];
      if (asigns.length === 0) continue;
      const kcal = asigns[0].kcal_objetivo || 0;
      balanceSem += (kcal - tdeePond);
      diasSem++;
    }
    const kgSem = balanceSem / 7700;

    wrap.hidden = false;
    const fmtKg = v => {
      if (Math.abs(v) < 0.005) return '0 kg';
      const sign = v > 0 ? '+' : '−';
      return sign + Math.abs(v).toFixed(2).replace('.', ',') + ' kg';
    };
    const elSem = $('calPredSem');
    const elMes = $('calPredMes');
    elSem.textContent = fmtKg(kgSem);
    elMes.textContent = fmtKg(kgMes);
    elSem.className = 'cal-pred-num ' + (kgSem < 0 ? 'is-down' : (kgSem > 0 ? 'is-up' : ''));
    elMes.className = 'cal-pred-num ' + (kgMes < 0 ? 'is-down' : (kgMes > 0 ? 'is-up' : ''));

    $('calPredSemMeta').textContent = diasSem + ' día' + (diasSem===1?'':'s') + ' asignado' + (diasSem===1?'':'s') + (diasSem>0 ? ' · ' + Math.round(balanceSem/Math.max(1,diasSem)) + ' kcal/día' : '');
    $('calPredMesMeta').textContent = diasMes + ' día' + (diasMes===1?'':'s') + ' asignado' + (diasMes===1?'':'s') + (diasMes>0 ? ' · ' + Math.round(balanceMes/Math.max(1,diasMes)) + ' kcal/día' : '');

    const hint = $('calPredHint');
    if (hint) {
      hint.innerHTML = 'TDEE estimado: <strong>' + Math.round(tdeePond).toLocaleString('es-ES') + ' kcal/día</strong> · Los días sin dieta no se cuentan.';
    }
  }

  // ============================================================
  // RENDER MES
  // ============================================================
  // ============================================================
  // RENDER MES — MINIMALISTA: día + nombre de dieta (sin píldoras)
  // ============================================================
  function renderMes() {
    if ($('calMonthLabel')) $('calMonthLabel').innerHTML = '<strong>' + nombreMes(state.mes) + '</strong>';
    const grid = $('calGrid');
    if (!grid) return;

    [...grid.querySelectorAll('.cal-cell, .cal-cell--empty')].forEach(el => el.remove());

    const huecos = state.primer_dow - 1;
    for (let i = 0; i < huecos; i++) {
      const e = document.createElement('div');
      e.className = 'cal-cell cal-cell--empty';
      e.setAttribute('aria-hidden', 'true');
      grid.appendChild(e);
    }

    for (let d = 1; d <= state.dias_mes; d++) {
      const fecha = state.mes + '-' + String(d).padStart(2, '0');
      const asigns = state.asignaciones[fecha] || [];

      const cell = document.createElement('div');
      cell.className = 'cal-cell cal-cell--minimal';
      cell.dataset.fecha = fecha;
      cell.setAttribute('role', 'gridcell');
      cell.tabIndex = 0;

      if (fecha === state.today) cell.classList.add('is-today');

      if (asigns.length > 0) {
        cell.classList.add('has-asigns');
        const primaria = asigns[0];
        const color = primaria.color || '#2F9E73';
        cell.style.setProperty('--diet-color', color);
        // Fondo: tinte muy sutil del color de la dieta (10%)
        cell.style.backgroundColor = hexToRgba(color, 0.10);

        // Si hay varias dietas, gradiente diagonal con el segundo color
        if (asigns.length > 1) {
          cell.classList.add('has-multiple');
          const color2 = asigns[1].color || '#F2A03D';
          cell.style.setProperty('--diet-color-2', color2);
          cell.style.backgroundImage = 'linear-gradient(135deg, ' +
            hexToRgba(color, 0.12) + ' 0%, ' +
            hexToRgba(color, 0.12) + ' 50%, ' +
            hexToRgba(color2, 0.12) + ' 50%, ' +
            hexToRgba(color2, 0.12) + ' 100%)';
        }
      }

      // Número del día (esquina superior derecha)
      const dayNum = document.createElement('div');
      dayNum.className = 'cal-cell-day';
      dayNum.textContent = String(d);
      cell.appendChild(dayNum);

      // Nombre de la dieta (centrado, color de la dieta)
      if (asigns.length > 0) {
        const primaria = asigns[0];
        const nameEl = document.createElement('div');
        nameEl.className = 'cal-cell-name';
        nameEl.textContent = primaria.nombre || '';
        nameEl.title = primaria.nombre || '';
        cell.appendChild(nameEl);

        if (asigns.length > 1) {
          const more = document.createElement('span');
          more.className = 'cal-cell-more';
          more.textContent = '+' + (asigns.length - 1);
          cell.appendChild(more);
        }
      }

      grid.appendChild(cell);
    }
  }

  // ============================================================
  // RENDER PALETA — compacta, sin botón borrar explícito
  // ============================================================
  function renderPaleta() {
    const grid = $('calPaletteGrid');
    if (!grid) return;
    grid.innerHTML = '';

    if (state.dietas_disp.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-muted cal-palette-empty';
      empty.textContent = 'Aún no tienes dietas para este cliente ni plantillas. Crea una en "Dietas".';
      grid.appendChild(empty);
      return;
    }

    state.dietas_disp.forEach(d => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cal-chip' + (state.selectedDietaId === d.id ? ' is-active' : '');
      btn.dataset.id = d.id;
      btn.style.setProperty('--diet-color', d.color || '#2F9E73');

      const icon = document.createElement('span');
      icon.className = 'cal-chip-icon';
      icon.style.backgroundColor = d.color || '#2F9E73';
      icon.textContent = d.icono || '🍽️';

      const name = document.createElement('span');
      name.className = 'cal-chip-name';
      name.textContent = d.nombre;

      btn.appendChild(icon);
      btn.appendChild(name);

      btn.addEventListener('click', () => {
        // toggle: si ya estaba seleccionada, deseleccionar (modo borrar implícito)
        if (state.selectedDietaId === d.id) selectDieta(null);
        else selectDieta(d.id);
      });
      grid.appendChild(btn);
    });

    actualizarHelpPaleta();
  }

  function selectDieta(id) {
    state.selectedDietaId = id;
    renderPaleta();
    actualizarHelpPaleta();
  }

  function actualizarHelpPaleta() {
    const help = $('calPaletteHelp');
    if (!help) return;
    if (state.selectedDietaId === null) {
      help.innerHTML = '🗑️ <strong>Modo borrar activo</strong> (ninguna dieta seleccionada): arrastra sobre los días con dieta para eliminarlos.';
    } else {
      const d = state.dietas_disp.find(x => x.id === state.selectedDietaId);
      if (d) {
        help.innerHTML = '🎨 <strong style="color:' + (d.color || '#2F9E73') + '">' + escapeHtml(d.icono || '🍽️') + ' ' + escapeHtml(d.nombre) + '</strong> seleccionada — arrastra sobre los días.';
      }
    }
  }

  // ============================================================
  // DRAG
  // ============================================================
  function cellFromPoint(x, y) {
    const el = document.elementFromPoint(x, y);
    if (!el) return null;
    const cell = el.closest('.cal-cell');
    if (!cell || cell.classList.contains('cal-cell--empty')) return null;
    return cell;
  }

  function onPointerDown(e) {
    if (!isDietista) return;
    if (e.button !== undefined && e.button !== 0) return;
    const cell = e.target.closest('.cal-cell');
    if (!cell || cell.classList.contains('cal-cell--empty')) return;

    e.preventDefault();
    isDragging  = true;
    dragMoved   = false;
    dragStartXY = { x: e.clientX, y: e.clientY };
    dragTouched = new Set();
    dragMode    = (state.selectedDietaId === null) ? 'erase' : 'paint';
    marcarCelda(cell);
  }

  function onPointerMove(e) {
    if (!isDragging) return;
    const dx = Math.abs(e.clientX - dragStartXY.x);
    const dy = Math.abs(e.clientY - dragStartXY.y);
    if (!dragMoved && (dx > 4 || dy > 4)) dragMoved = true;

    const cell = cellFromPoint(e.clientX, e.clientY);
    if (cell) marcarCelda(cell);
  }

  function marcarCelda(cell) {
    const fecha = cell.dataset.fecha;
    if (!fecha || dragTouched.has(fecha)) return;
    dragTouched.add(fecha);
    cell.classList.add(dragMode === 'paint' ? 'is-painting' : 'is-erasing');
  }

  async function onPointerUp() {
    if (!isDragging) return;
    isDragging = false;
    const touched = [...dragTouched];

    [...document.querySelectorAll('.cal-cell.is-painting, .cal-cell.is-erasing')]
      .forEach(c => c.classList.remove('is-painting', 'is-erasing'));

    if (!dragMoved && touched.length === 1) { openDayModal(touched[0]); return; }
    if (touched.length === 0) return;

    try {
      const fd = new FormData();
      fd.append('_csrf', D.csrf);
      touched.forEach(f => fd.append('fechas[]', f));
      let url;
      if (dragMode === 'paint') { fd.append('id_dieta', state.selectedDietaId); url = urlAjax('paint'); }
      else url = urlAjax('erase');
      await fetchJson(url, { method: 'POST', body: fd });
      await cargarMes(state.mes);
    } catch (err) { reportarError('drag: ' + err.message); }
  }

  // ============================================================
  // MODAL DETALLE DÍA
  // ============================================================
  function openDayModal(fecha) {
    const modal = $('modalDia');
    if (!modal) return;

    const asigns = state.asignaciones[fecha] || [];
    const d = new Date(fecha + 'T00:00:00');
    $('modalDiaTitle').textContent = '📌 ' + d.toLocaleDateString('es-ES', {
      weekday: 'long', day: '2-digit', month: 'long', year: 'numeric'
    });

    const body = $('modalDiaBody');
    body.innerHTML = isDietista ? renderDietistaDay(fecha, asigns) : renderClienteDay(fecha, asigns);
    body.querySelectorAll('[data-act]').forEach(btn => btn.addEventListener('click', onDayAction));

    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeDayModal() {
    const modal = $('modalDia');
    if (!modal) return;
    modal.hidden = true;
    if (!(!isDietista && $('calModal') && !$('calModal').hidden)) document.body.style.overflow = '';
  }

  function renderDietistaDay(fecha, asigns) {
    let html = '';
    if (asigns.length === 0) {
      html += '<p class="text-muted">Sin dietas asignadas este día.</p>';
    } else {
      html += '<ul class="cal-asigns" role="list">';
      asigns.forEach(a => {
        html += `
          <li class="cal-asign" style="border-left:4px solid ${escapeHtml(a.color || '#2F9E73')}; background:${hexToRgba(a.color, 0.10)};">
            <span class="dash-diet-icon" style="background:${escapeHtml(a.color || '#2F9E73')};">${escapeHtml(a.icono || '🍽️')}</span>
            <div class="cal-asign-info">
              <div class="cal-asign-name">${escapeHtml(a.nombre)}</div>
              <div class="text-muted">${a.kcal_objetivo} kcal</div>
            </div>
            <button type="button" class="btn btn-ghost btn-mini" data-act="del_uno" data-id="${a.id}" data-fecha="${escapeHtml(fecha)}" aria-label="Quitar esta dieta">🗑️</button>
          </li>
        `;
      });
      html += '</ul>';
      html += `<button type="button" class="btn btn-outline btn-block" data-act="del_dia" data-fecha="${escapeHtml(fecha)}">🗑️ Quitar TODAS las dietas de este día</button>`;
    }

    if (state.dietas_disp.length > 0) {
      html += '<div class="cal-add-form"><p class="field-label">➕ Asignar otra dieta a este día</p>';
      html += '<label class="field"><select class="field-select" id="modalDietaSel">';
      html += '<option value="">— Elegir —</option>';
      state.dietas_disp.forEach(d => {
        html += `<option value="${d.id}">${escapeHtml(d.icono || '🍽️')} ${escapeHtml(d.nombre)} (${d.kcal_objetivo} kcal)</option>`;
      });
      html += '</select></label>';
      html += `<button type="button" class="btn btn-primary btn-block" data-act="add_dia" data-fecha="${escapeHtml(fecha)}">Asignar</button>`;
      html += '</div>';
    }

    return html;
  }

  function renderClienteDay(fecha, asigns) {
    let html = '';
    if (asigns.length === 0) {
      html += '<p class="text-muted text-center">Sin dieta asignada este día.</p>';
    } else {
      html += '<ul class="cal-asigns" role="list">';
      asigns.forEach(a => {
        html += `
          <li class="cal-asign" style="border-left:4px solid ${escapeHtml(a.color || '#2F9E73')}; background:${hexToRgba(a.color, 0.10)};">
            <span class="dash-diet-icon" style="background:${escapeHtml(a.color || '#2F9E73')};">${escapeHtml(a.icono || '🍽️')}</span>
            <div class="cal-asign-info">
              <div class="cal-asign-name">${escapeHtml(a.nombre)}</div>
              <div class="text-muted">${a.kcal_objetivo} kcal · P ${Math.round(a.prot_objetivo)}g · C ${Math.round(a.carb_objetivo)}g · G ${Math.round(a.grasas_objetivo)}g</div>
            </div>
          </li>
        `;
      });
      html += '</ul>';
      html += `<a class="btn btn-primary btn-block" href="${D.base_url}roles/cliente/cliente_dieta.php?fecha=${encodeURIComponent(fecha)}">Ver este día completo →</a>`;
    }
    return html;
  }

  async function onDayAction(e) {
    const btn = e.currentTarget;
    const act = btn.dataset.act;
    const fecha = btn.dataset.fecha;

    try {
      if (act === 'del_uno') {
        const id = btn.dataset.id;
        const fd = new FormData();
        fd.append('_csrf', D.csrf);
        fd.append('id', id);
        await fetchJson(urlAjax('del_uno'), { method: 'POST', body: fd });
        await cargarMes(state.mes);
        if (state.asignaciones[fecha]) openDayModal(fecha); else closeDayModal();
      }
      else if (act === 'del_dia') {
        if (!confirm('¿Quitar todas las dietas de este día?')) return;
        const fd = new FormData();
        fd.append('_csrf', D.csrf);
        fd.append('fechas[]', fecha);
        await fetchJson(urlAjax('erase'), { method: 'POST', body: fd });
        await cargarMes(state.mes);
        closeDayModal();
      }
      else if (act === 'add_dia') {
        const sel = $('modalDietaSel');
        const id = parseInt(sel.value, 10);
        if (!id) { sel.focus(); return; }
        const fd = new FormData();
        fd.append('_csrf', D.csrf);
        fd.append('id_dieta', id);
        fd.append('fechas[]', fecha);
        await fetchJson(urlAjax('paint'), { method: 'POST', body: fd });
        await cargarMes(state.mes);
        openDayModal(fecha);
      }
    } catch (err) { reportarError('onDayAction: ' + err.message); }
  }

  async function clearAll() {
    if (!confirm('⚠️ ¿Borrar TODAS las asignaciones del calendario de este cliente?\n\nEsto eliminará TODAS las dietas asignadas en TODOS los días.')) return;
    if (!confirm('Confirma de nuevo: esta acción NO se puede deshacer. ¿Continuar?')) return;

    const fd = new FormData();
    fd.append('_csrf', D.csrf);
    try {
      await fetchJson(urlAjax('clear_all'), { method: 'POST', body: fd });
      await cargarMes(state.mes);
    } catch (err) { reportarError('clearAll: ' + err.message); }
  }
})();
