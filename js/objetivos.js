/* /js/objetivos.js — barras de progreso animadas */
(function () {
  document.querySelectorAll('.obj-progress-bar[data-pct]').forEach(function (bar) {
    var pct = parseInt(bar.getAttribute('data-pct'), 10) || 0;
    requestAnimationFrame(function () {
      bar.style.width = pct + '%';
    });
  });
})();