/* /js/chats.js — auto-scroll, envío AJAX y polling
   InfinityFree fix: añade X-Requested-With para que su WAF no bloquee.
*/
(function () {
  'use strict';

  const stream = document.getElementById('chatStream');
  const form   = document.getElementById('chatForm');
  if (!stream || !form) return;

  const URL_BASE  = 'chats.php';
  const conId     = stream.dataset.con;
  const yo        = parseInt(stream.dataset.yo, 10);
  let lastId      = parseInt(stream.dataset.last, 10) || 0;
  let enviando    = false;
  let pollOn      = true;
  let intervaloPoll = 5000;

  // Cabeceras que algunos hostings (InfinityFree) requieren para no bloquear AJAX
  const AJAX_HEADERS = {
    'X-Requested-With': 'XMLHttpRequest',
    'Accept':           'application/json, text/plain, */*'
  };

  function scrollAlFinal() { stream.scrollTop = stream.scrollHeight; }
  scrollAlFinal();

  function escapeHTML(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function nl2br(s) { return escapeHTML(s).replace(/\n/g, '<br>'); }

  function renderMensaje(m) {
    const mio = (parseInt(m.id_remitente, 10) === yo);
    const div = document.createElement('div');
    div.className = 'chats-msg' + (mio ? ' is-mine' : '');
    div.dataset.id = m.id;
    const hora = new Date(m.fecha_hora.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    div.innerHTML = `
      <div class="chats-msg-bubble">${nl2br(m.mensaje)}</div>
      <div class="chats-msg-time">${escapeHTML(hora)}</div>
    `;
    stream.appendChild(div);
    const empty = stream.querySelector('.chats-empty-stream');
    if (empty) empty.remove();
  }

  function mostrarErrorHosting() {
    if (document.getElementById('chatHostingErr')) return;
    const aviso = document.createElement('div');
    aviso.id = 'chatHostingErr';
    aviso.className = 'alert alert-warning';
    aviso.style.margin = '8px 0';
    aviso.innerHTML = '⚠️ El hosting está bloqueando las peticiones del chat. Recarga la página y, si vuelve a pasar, contacta con el administrador.';
    stream.parentNode.insertBefore(aviso, stream);
  }

  // ---- POLLING ----
  async function poll() {
    if (!pollOn) return;
    try {
      const r = await fetch(`${URL_BASE}?ajax=1&con=${conId}&desde=${lastId}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: AJAX_HEADERS,
        cache: 'no-store'
      });
      if (r.status === 403 || r.status === 0) {
        // Hosting (InfinityFree) bloqueando
        intervaloPoll = Math.min(intervaloPoll * 2, 60000);
        if (intervaloPoll >= 60000) { pollOn = false; mostrarErrorHosting(); }
        return;
      }
      if (!r.ok) return;
      const d = await r.json();
      if (d && d.ok && Array.isArray(d.mensajes) && d.mensajes.length) {
        const cercaDelFinal = (stream.scrollHeight - stream.scrollTop - stream.clientHeight) < 80;
        d.mensajes.forEach(m => {
          renderMensaje(m);
          lastId = Math.max(lastId, parseInt(m.id, 10));
        });
        if (cercaDelFinal) scrollAlFinal();
      }
      // si todo OK, restablecer intervalo
      intervaloPoll = 5000;
    } catch (_) {}
  }

  let timer = setInterval(tick, intervaloPoll);
  function tick() {
    clearInterval(timer);
    poll().then(() => { timer = setInterval(tick, intervaloPoll); });
  }

  // ---- ENVIAR ----
  async function enviar() {
    if (enviando) return;
    const ta = form.querySelector('.chats-input');
    const msg = ta.value.trim();
    if (!msg) return;
    enviando = true;

    const fd = new FormData(form);
    fd.append('ajax', '1');

    try {
      const r = await fetch(URL_BASE, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: AJAX_HEADERS
      });
      if (r.status === 403) {
        mostrarErrorHosting();
        // Fallback: enviar el formulario normal (recarga la página) para no perder el mensaje
        form.querySelector('input[name="ajax"]')?.remove();
        form.submit();
        return;
      }
      if (!r.ok) { enviando = false; return; }
      const d = await r.json();
      if (d && d.ok) {
        ta.value = '';
        ta.style.height = '';
        await poll();
        scrollAlFinal();
      }
    } catch (_) {}
    enviando = false;
  }

  form.addEventListener('submit', e => { e.preventDefault(); enviar(); });

  const ta = form.querySelector('.chats-input');
  ta.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
  });

  ta.addEventListener('input', () => {
    ta.style.height = 'auto';
    ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
  });
})();
