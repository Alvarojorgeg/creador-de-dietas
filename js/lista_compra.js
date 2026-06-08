/* /js/lista_compra.js
   Marcado de la lista de la compra. Persistencia en localStorage. */
(function () {
  'use strict';

  const lista = document.getElementById('shopList');
  if (!lista) return;
  const key = lista.dataset.key || 'lista_default';

  // Cargar estado guardado
  let estado = {};
  try { estado = JSON.parse(localStorage.getItem(key) || '{}'); } catch (_) {}

  lista.querySelectorAll('.shop-check').forEach(cb => {
    const id = cb.dataset.id;
    if (estado[id]) {
      cb.checked = true;
      cb.closest('.shop-item').classList.add('is-checked');
    }
    cb.addEventListener('change', () => {
      const it = cb.closest('.shop-item');
      if (cb.checked) {
        estado[id] = 1;
        it.classList.add('is-checked');
      } else {
        delete estado[id];
        it.classList.remove('is-checked');
      }
      localStorage.setItem(key, JSON.stringify(estado));
    });
  });

  // Limpiar todas
  const btnLimpiar = document.getElementById('btnLimpiarMarcas');
  btnLimpiar && btnLimpiar.addEventListener('click', () => {
    estado = {};
    localStorage.removeItem(key);
    lista.querySelectorAll('.shop-check').forEach(cb => {
      cb.checked = false;
      cb.closest('.shop-item').classList.remove('is-checked');
    });
  });

  // Imprimir
  const btnImp = document.getElementById('btnImprimirLista');
  btnImp && btnImp.addEventListener('click', () => window.print());
})();