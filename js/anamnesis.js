/* /js/anamnesis.js — Cálculo TDEE en tiempo real (Mifflin-St Jeor + NEAT + EAT + TEF) */
(function () {
  'use strict';

  const $  = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const fmt = v => Math.round(v).toLocaleString('es-ES');
  const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

  // Mapas
  const NEAT_TRABAJO = { sentado: 200, de_pie: 400, caminando: 600, fisico_leve: 800, fisico_intenso: 1200 };
  const MET_ENTRENO  = { fuerza: 5.0, cardio: 8.0, mixto: 6.5, calistenia: 6.0, otro: 5.5 };

  function leer(name) {
    const el = document.querySelector(`[name="${name}"]`);
    if (!el) return '';
    if (el.type === 'radio') {
      const checked = document.querySelector(`[name="${name}"]:checked`);
      return checked ? checked.value : '';
    }
    return el.value;
  }

  function edadDesde(fnac) {
    if (!fnac) return 0;
    const hoy = new Date(); const nac = new Date(fnac);
    let edad = hoy.getFullYear() - nac.getFullYear();
    if (hoy < new Date(hoy.getFullYear(), nac.getMonth(), nac.getDate())) edad--;
    return Math.max(0, edad);
  }

  function calc() {
    const peso     = parseFloat(window.PESO_REFERENCIA || 0);
    const altura   = parseFloat(leer('altura_cm')) || 0;
    const fnac     = leer('fecha_nacimiento');
    const sexo     = leer('sexo') || 'Hombre';
    const pasos    = parseInt(leer('pasos_diarios'), 10)  || 7000;
    const diasGym  = parseInt(leer('dias_gym'), 10)       || 0;
    const minSes   = parseInt(leer('min_sesion'), 10)     || 60;
    const tipoT    = leer('tipo_trabajo') || 'sentado';
    const tipoE    = leer('tipo_entreno') || 'mixto';
    const factorP  = parseFloat(leer('factor_p')) || 2.0;
    const factorG  = parseFloat(leer('factor_g')) || 0.9;

    // Actualizar valor visible de sliders siempre
    ['pasos_diarios','dias_gym','min_sesion','factor_p','factor_g'].forEach(n => {
      const el = document.querySelector(`[name="${n}"]`);
      const out = document.getElementById('val_' + n);
      if (el && out) out.textContent = el.value;
    });

    if (peso <= 0 || altura < 50 || !fnac) {
      setText('tdee_avisos', '⚠️ Necesitamos peso, altura y fecha de nacimiento para calcular tu gasto.');
      const aviso = document.getElementById('tdee_avisos'); if (aviso) aviso.hidden = false;
      const card  = document.getElementById('tdee_card');   if (card)  card.hidden  = true;
      return;
    }
    const aviso = document.getElementById('tdee_avisos'); if (aviso) aviso.hidden = true;
    const card  = document.getElementById('tdee_card');   if (card)  card.hidden  = false;

    const edad = edadDesde(fnac);

    // BMR Mifflin-St Jeor
    const bmr = sexo === 'Hombre'
      ? (10 * peso + 6.25 * altura - 5 * edad + 5)
      : (10 * peso + 6.25 * altura - 5 * edad - 161);

    // Componentes
    const neatPasos   = pasos * 0.045;
    const neatTrabajo = NEAT_TRABAJO[tipoT] || 200;
    const met         = MET_ENTRENO[tipoE]  || 5.5;
    const eat         = met * peso * (minSes / 60);
    const tef         = bmr * 0.10;

    const tdeeEntreno   = bmr + neatPasos + neatTrabajo + eat + tef;
    const tdeeDescanso  = bmr + neatPasos + neatTrabajo + tef;
    const tdeePonderado = (tdeeEntreno * diasGym + tdeeDescanso * (7 - diasGym)) / 7;

    // Factor de actividad equivalente
    const factorEquiv = tdeePonderado / bmr;

    // Macros derivados (objetivo del cliente)
    const gramosP = peso * factorP;
    const gramosG = peso * factorG;
    const kcalP = gramosP * 4;
    const kcalG = gramosG * 9;
    const kcalC = Math.max(0, tdeePonderado - kcalP - kcalG);
    const gramosC = kcalC / 4;

    // Render
    setText('val_edad',         edad + ' años');
    setText('prev_bmr',         fmt(bmr) + ' kcal');
    setText('prev_neat_pasos',  '+' + fmt(neatPasos) + ' kcal');
    setText('prev_neat_trabajo','+' + fmt(neatTrabajo) + ' kcal');
    setText('prev_eat',         '+' + fmt(eat) + ' kcal');
    setText('prev_tef',         '+' + fmt(tef) + ' kcal');
    setText('prev_tdee_entreno',  fmt(tdeeEntreno) + ' kcal');
    setText('prev_tdee_descanso', fmt(tdeeDescanso) + ' kcal');
    setText('prev_tdee_total',    fmt(tdeePonderado) + ' kcal');
    setText('prev_factor_eq',     '×' + factorEquiv.toFixed(2));

    setText('prev_macro_p',  Math.round(gramosP) + ' g');
    setText('prev_macro_c',  Math.round(gramosC) + ' g');
    setText('prev_macro_g',  Math.round(gramosG) + ' g');
    setText('prev_macro_kp', fmt(kcalP) + ' kcal');
    setText('prev_macro_kc', fmt(kcalC) + ' kcal');
    setText('prev_macro_kg', fmt(kcalG) + ' kcal');

    // Inputs ocultos para enviar al submit
    const hidden = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    hidden('hidden_obj_kcal', Math.round(tdeePonderado));
    hidden('hidden_obj_p',    Math.round(gramosP * 10) / 10);
    hidden('hidden_obj_c',    Math.round(gramosC * 10) / 10);
    hidden('hidden_obj_g',    Math.round(gramosG * 10) / 10);
    hidden('hidden_factor_actividad', factorEquiv.toFixed(2));
  }

  // Listeners
  document.addEventListener('DOMContentLoaded', () => {
    calc();
    const campos = ['sexo','fecha_nacimiento','altura_cm','pasos_diarios','dias_gym','min_sesion',
                    'tipo_trabajo','tipo_entreno','factor_p','factor_g'];
    campos.forEach(n => {
      $$(`[name="${n}"]`).forEach(el => {
        el.addEventListener('input',  calc);
        el.addEventListener('change', calc);
      });
    });
  });
})();