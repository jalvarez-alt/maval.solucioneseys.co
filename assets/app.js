(function () {
  'use strict';

  // Confirmación antes de acciones destructivas: <form data-confirm="¿Seguro?">
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Formato de moneda en campos .money (separador de miles con punto, decimales con coma)
  function fmt(v) {
    var s = String(v).replace(/[^\d,]/g, '');
    var parts = s.split(',');
    var ent = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? ent + ',' + parts[1].slice(0, 2) : ent;
  }
  window.parseMoney = function (v) {
    var s = String(v || '').replace(/\./g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  };
  window.fmtMoney = function (n) {
    return '$ ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  };
  document.querySelectorAll('input.money').forEach(function (el) {
    if (/^\d+(\.\d+)?$/.test(el.value)) el.value = fmt(el.value.replace(/\.0+$/, '').replace('.', ','));
    el.addEventListener('input', function () {
      var pos = el.value.length - el.selectionStart;
      el.value = fmt(el.value);
      var p = Math.max(0, el.value.length - pos);
      el.setSelectionRange(p, p);
    });
  });

  // Mostrar/ocultar campos según el valor de un select: data-show-when="campo:valor1,valor2"
  function toggleDeps() {
    document.querySelectorAll('[data-show-when]').forEach(function (el) {
      var spec = el.getAttribute('data-show-when').split(':');
      var src = document.querySelector('[name="' + spec[0] + '"]');
      if (!src) return;
      var show = spec[1].split(',').indexOf(src.value) !== -1;
      el.style.display = show ? '' : 'none';
    });
  }
  document.addEventListener('change', toggleDeps);
  toggleDeps();


  // Selección masiva de equipos
  var bulk = document.getElementById('masivo');
  if (bulk) {
    var count = bulk.querySelector('[data-bulk-count]'), allBtn = bulk.querySelector('[data-bulk-all]');
    var todos = bulk.querySelector('input[name=todos]'), total = parseInt(bulk.dataset.total || '0', 10);
    var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('input.sel')); };
    var head = document.querySelector('input.sel-all');
    function refresh() {
      var marcados = checks().filter(function (c) { return c.checked; }).length;
      var n = todos.value === '1' ? total : marcados;
      count.textContent = n;
      bulk.hidden = n === 0;
      document.body.classList.toggle('bulk-open', n > 0);
      if (head) head.checked = marcados > 0 && marcados === checks().length;
      if (allBtn) allBtn.hidden = !(todos.value !== '1' && marcados === checks().length && total > marcados);
      if (allBtn && todos.value === '1') { allBtn.hidden = false; allBtn.textContent = 'Se seleccionaron los ' + total + ' equipos del filtro'; allBtn.disabled = true; }
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches('input.sel-all')) { checks().forEach(function (c) { c.checked = e.target.checked; }); todos.value = '0'; if (allBtn) { allBtn.disabled = false; allBtn.textContent = 'Seleccionar los ' + total + ' equipos del filtro'; } refresh(); }
      else if (e.target.matches('input.sel')) { if (!e.target.checked) { todos.value = '0'; if (allBtn) { allBtn.disabled = false; allBtn.textContent = 'Seleccionar los ' + total + ' equipos del filtro'; } } refresh(); }
    });
    if (allBtn) allBtn.addEventListener('click', function () { todos.value = '1'; refresh(); });
    bulk.querySelector('[data-bulk-clear]').addEventListener('click', function () { checks().forEach(function (c) { c.checked = false; }); todos.value = '0'; if (allBtn) allBtn.disabled = false; refresh(); });
    bulk.addEventListener('submit', function (e) {
      var op = e.submitter ? e.submitter.value : '';
      var n = count.textContent;
      if (op === 'asignar' && !bulk.querySelector('#bulk-usr').value) { e.preventDefault(); alert('Seleccione el usuario de la mesa de ayuda.'); return; }
      if (op === 'estado' && !bulk.querySelector('#bulk-est').value) { e.preventDefault(); alert('Seleccione el nuevo estado.'); return; }
      if (op === 'asignar' || op === 'estado') { if (!confirm('¿Aplicar a ' + n + ' equipo(s)?')) e.preventDefault(); return; }
      if (op === 'eliminar') {
        var t = prompt('Se eliminarán ' + n + ' equipo(s) con su software e historial. Los tickets se conservan.\nEsta acción no se puede deshacer.\n\nEscriba ELIMINAR para confirmar:');
        if (!t || t.trim().toUpperCase() !== 'ELIMINAR') { e.preventDefault(); return; }
        bulk.querySelector('input[name=confirmacion]').value = 'ELIMINAR';
      }
    });
    refresh();
  }

  // Envío automático de filtros: <select data-autosubmit>
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
})();
