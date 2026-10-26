<?php
require_perm('estadisticas', 'editar');
$anio = (int)get('anio', date('Y'));
$title = 'Presupuesto ' . $anio;
$cats = all('SELECT id, nombre, activo FROM gasto_categorias ORDER BY activo DESC, nombre');

// Administrar las categorías del presupuesto sin salir de la página (renombrar, agregar, quitar). Responde JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)post('accion', ''), 'cat_')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => 'Acción no válida.'];
    $acc = post('accion');
    $nombre = mb_substr(trim((string)post('nombre', '')), 0, 80);
    $cid = (int)post('id', 0);
    $perm = ['cat_crear' => 'crear', 'cat_renombrar' => 'editar', 'cat_quitar' => 'eliminar'][$acc] ?? null;
    if (!$perm || !can('categorias', $perm)) $out = ['ok' => false, 'error' => 'No tiene permiso para esta acción.'];
    elseif ($acc === 'cat_crear') {
        if ($nombre === '') $out = ['ok' => false, 'error' => 'Escriba el nombre de la categoría.'];
        elseif (val('SELECT id FROM gasto_categorias WHERE LOWER(nombre) = LOWER(?)', [$nombre])) $out = ['ok' => false, 'error' => 'Ya existe una categoría con ese nombre.'];
        else { $id = insert('gasto_categorias', ['nombre' => $nombre, 'activo' => 1]); $out = ['ok' => true, 'id' => $id, 'nombre' => $nombre]; }
    } elseif ($acc === 'cat_renombrar') {
        if (!$cid || !val('SELECT id FROM gasto_categorias WHERE id = ?', [$cid])) $out = ['ok' => false, 'error' => 'La categoría no existe.'];
        elseif ($nombre === '') $out = ['ok' => false, 'error' => 'El nombre no puede quedar vacío.'];
        elseif (val('SELECT id FROM gasto_categorias WHERE LOWER(nombre) = LOWER(?) AND id <> ?', [$nombre, $cid])) $out = ['ok' => false, 'error' => 'Ya existe otra categoría con ese nombre.'];
        else { update('gasto_categorias', ['nombre' => $nombre], $cid); $out = ['ok' => true, 'id' => $cid, 'nombre' => $nombre]; }
    } elseif ($acc === 'cat_quitar') {
        if (!$cid || !val('SELECT id FROM gasto_categorias WHERE id = ?', [$cid])) $out = ['ok' => false, 'error' => 'La categoría no existe.'];
        elseif ((int)val('SELECT COUNT(*) FROM gastos WHERE categoria_id = ?', [$cid]) > 0) {
            // Tiene gastos registrados: no se borra (se perdería el historial), se desactiva
            q('UPDATE gasto_categorias SET activo = 0 WHERE id = ?', [$cid]);
            $out = ['ok' => true, 'id' => $cid, 'resultado' => 'desactivada', 'mensaje' => 'La categoría tiene gastos registrados: se desactivó (ya no aparece al registrar gastos). Puede reactivarla en Categorías de gasto.'];
        } else {
            q('DELETE FROM gasto_categorias WHERE id = ?', [$cid]);   // sus valores de presupuesto se borran en cascada
            $out = ['ok' => true, 'id' => $cid, 'resultado' => 'eliminada', 'mensaje' => 'Categoría eliminada.'];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    if (post('accion') === 'copiar') {
        $origen = $anio - 1;
        $n = (int)val('SELECT COUNT(*) FROM presupuestos WHERE anio = ?', [$origen]);
        if (!$n) {
            flash('El año ' . $origen . ' no tiene presupuesto para copiar.', 'warning');
        } else {
            $factor = 1 + ((float)str_replace(',', '.', (string)post('incremento', '0')) / 100);
            $pdo->beginTransaction();
            q('DELETE FROM presupuestos WHERE anio = ?', [$anio]);
            foreach (all('SELECT mes, categoria_id, valor FROM presupuestos WHERE anio = ?', [$origen]) as $p) {
                insert('presupuestos', ['anio' => $anio, 'mes' => $p['mes'], 'categoria_id' => $p['categoria_id'], 'valor' => round((float)$p['valor'] * $factor, 0)]);
            }
            $pdo->commit();
            flash('Presupuesto copiado desde ' . $origen . ($factor != 1 ? ' con ajuste de ' . num(($factor - 1) * 100, 1) . ' %' : '') . '.');
        }
    } else {
        $vals = $_POST['v'] ?? [];
        $pdo->beginTransaction();
        q('DELETE FROM presupuestos WHERE anio = ?', [$anio]);
        foreach ($cats as $c) {
            for ($m = 1; $m <= 12; $m++) {
                $v = parse_money($vals[$c['id']][$m] ?? '0');
                if ($v > 0) insert('presupuestos', ['anio' => $anio, 'mes' => $m, 'categoria_id' => $c['id'], 'valor' => $v]);
            }
        }
        $pdo->commit();
        flash('Presupuesto ' . $anio . ' guardado.');
    }
    redirect(url('presupuesto', ['anio' => $anio]));
}

$pres = [];
foreach (all('SELECT categoria_id, mes, valor FROM presupuestos WHERE anio = ?', [$anio]) as $p) $pres[$p['categoria_id']][$p['mes']] = (float)$p['valor'];
$anios = range((int)date('Y') + 1, (int)date('Y') - 3);
$puedeCrearCat = can('categorias', 'crear'); $puedeEditarCat = can('categorias', 'editar'); $puedeQuitarCat = can('categorias', 'eliminar');
?>
<div class="tabs">
  <a href="<?= url('estadisticas', ['anio' => $anio]) ?>">Estadísticas</a>
  <a class="on" href="<?= url('presupuesto', ['anio' => $anio]) ?>">Presupuesto</a>
</div>
<div class="panel">
  <div class="toolbar">
    <form method="get" class="d-flex gap-2"><input type="hidden" name="p" value="presupuesto"><select class="form-select" name="anio" data-autosubmit><?= options(array_combine($anios, $anios), $anio) ?></select></form>
    <form method="post" class="d-flex gap-2 align-items-center ms-auto" data-confirm="Esto reemplaza el presupuesto de <?= $anio ?> con el de <?= $anio - 1 ?>. ¿Continuar?">
      <?= csrf_field() ?><input type="hidden" name="accion" value="copiar">
      <span class="small muted text-nowrap">Copiar <?= $anio - 1 ?> con ajuste</span>
      <input class="form-control" name="incremento" value="0" style="width:80px;min-width:0" inputmode="decimal"><span class="small muted">%</span>
      <button class="btn btn-outline-secondary text-nowrap"><i class="bi bi-files me-1"></i>Copiar</button>
    </form>
  </div>
  <p class="small muted">Escriba el valor mensual presupuestado por categoría. Use <i class="bi bi-arrow-right-square"></i> para repetir el valor de enero en todos los meses<?= $puedeEditarCat || $puedeCrearCat ? ', <i class="bi bi-pencil"></i> para cambiar el nombre de una categoría' . ($puedeCrearCat ? ' y «Agregar categoría» para crear una nueva' : '') : '' ?>. Los cambios de categorías se aplican al instante; los valores del presupuesto se guardan con el botón de abajo.</p>
<style>.cat-acciones { opacity: .45; transition: opacity .15s; } tr[data-row]:hover .cat-acciones, .cat-acciones:focus-within { opacity: 1; }</style>
  <form method="post" id="fpres">
    <?= csrf_field() ?>
    <div class="tbl-wrap"><table class="tbl budget">
      <thead><tr><th>Categoría</th><?php foreach (MESES_CORTOS as $m): ?><th class="r"><?= $m ?></th><?php endforeach; ?><th class="r">Total año</th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): $rowTot = array_sum($pres[$c['id']] ?? []); if (!$c['activo'] && !$rowTot) continue; ?>
        <tr data-row data-id="<?= (int)$c['id'] ?>">
          <td class="nowrap cat-celda"><button type="button" class="btn btn-sm btn-link p-0 me-1" data-fill title="Repetir enero en todos los meses"><i class="bi bi-arrow-right-square"></i></button><span class="cat-nombre"><?= e($c['nombre']) ?></span><?= $c['activo'] ? '' : ' <small class="muted">(inactiva)</small>' ?>
            <span class="cat-acciones"><?php if ($puedeEditarCat): ?><button type="button" class="btn btn-sm btn-link p-0 ms-2" data-renombrar title="Cambiar el nombre"><i class="bi bi-pencil"></i></button><?php endif; ?><?php if ($puedeQuitarCat): ?><button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-quitar title="Quitar esta categoría"><i class="bi bi-trash"></i></button><?php endif; ?></span></td>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <td><input class="form-control money" name="v[<?= (int)$c['id'] ?>][<?= $m ?>]" value="<?= isset($pres[$c['id']][$m]) ? e($pres[$c['id']][$m]) : '' ?>" inputmode="decimal"></td>
          <?php endfor; ?>
          <td class="r fw-semibold" data-rowtot><?= money($rowTot) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><?php for ($m = 1; $m <= 12; $m++): ?><td class="r" data-coltot="<?= $m ?>"></td><?php endfor; ?><td class="r" data-grand></td></tr></tfoot>
    </table></div>
    <?php if ($puedeCrearCat): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-3" id="cat-nueva">
      <input class="form-control" id="cat-nombre-nuevo" maxlength="80" placeholder="Nueva categoría de presupuesto (ej.: Publicidad)" style="max-width:340px" autocomplete="off">
      <button type="button" class="btn btn-outline-primary" id="cat-agregar"><i class="bi bi-plus-lg me-1"></i>Agregar categoría</button>
      <span class="small muted" id="cat-msg"></span>
    </div>
    <?php endif; ?>
    <template id="fila-tpl">
      <tr data-row data-id="__ID__">
        <td class="nowrap cat-celda"><button type="button" class="btn btn-sm btn-link p-0 me-1" data-fill title="Repetir enero en todos los meses"><i class="bi bi-arrow-right-square"></i></button><span class="cat-nombre"></span>
          <span class="cat-acciones"><?php if ($puedeEditarCat): ?><button type="button" class="btn btn-sm btn-link p-0 ms-2" data-renombrar title="Cambiar el nombre"><i class="bi bi-pencil"></i></button><?php endif; ?><?php if ($puedeQuitarCat): ?><button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-quitar title="Quitar esta categoría"><i class="bi bi-trash"></i></button><?php endif; ?></span></td>
        <?php for ($m = 1; $m <= 12; $m++): ?><td><input class="form-control money" name="v[__ID__][<?= $m ?>]" value="" inputmode="decimal"></td><?php endfor; ?>
        <td class="r fw-semibold" data-rowtot>$ 0</td>
      </tr>
    </template>
    <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar presupuesto <?= $anio ?></button></div>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('fpres'), body = f.querySelector('tbody');
  var csrf = f.querySelector('input[name=_csrf]').value;
  function recalc() {
    var cols = new Array(13).fill(0), grand = 0;
    f.querySelectorAll('tr[data-row]').forEach(function (tr) {
      var tot = 0;
      tr.querySelectorAll('input.money').forEach(function (inp, i) { var v = parseMoney(inp.value); tot += v; cols[i + 1] += v; });
      tr.querySelector('[data-rowtot]').textContent = fmtMoney(tot);
      grand += tot;
    });
    for (var m = 1; m <= 12; m++) f.querySelector('[data-coltot="' + m + '"]').textContent = fmtMoney(cols[m]);
    f.querySelector('[data-grand]').textContent = fmtMoney(grand);
  }
  f.addEventListener('input', recalc);
  function msg(t, ok) { var el = document.getElementById('cat-msg'); if (!el) { if (t) alert(t); return; } el.textContent = t || ''; el.className = 'small ' + (ok === false ? 'text-danger' : 'text-success'); }
  function llamar(datos) {
    var fd = new FormData(); fd.append('_csrf', csrf);
    Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
    return fetch('index.php?p=presupuesto&anio=<?= $anio ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'No se pudo completar la acción. Intente de nuevo.' }; });
  }
  // Repetir enero, renombrar y quitar (delegación: también funciona en las categorías recién agregadas)
  f.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var tr = b.closest('tr[data-row]'); if (!tr) return;
    if (b.hasAttribute('data-fill')) {
      var ins = tr.querySelectorAll('input.money'); ins.forEach(function (i) { i.value = ins[0].value; }); recalc();
    } else if (b.hasAttribute('data-renombrar')) {
      var span = tr.querySelector('.cat-nombre'), actual = span.textContent;
      var nuevo = prompt('Nuevo nombre de la categoría:', actual);
      if (nuevo === null || nuevo.trim() === '' || nuevo.trim() === actual) return;
      llamar({ accion: 'cat_renombrar', id: tr.getAttribute('data-id'), nombre: nuevo.trim() }).then(function (j) {
        if (j.ok) { span.textContent = j.nombre; msg('Categoría renombrada.', true); } else msg(j.error, false);
      });
    } else if (b.hasAttribute('data-quitar')) {
      var nom = tr.querySelector('.cat-nombre').textContent;
      if (!confirm('¿Quitar la categoría «' + nom + '»?\n\nSi no tiene gastos registrados se elimina (junto con su presupuesto de todos los años). Si ya tiene gastos, solo se desactiva para conservar el historial.')) return;
      llamar({ accion: 'cat_quitar', id: tr.getAttribute('data-id') }).then(function (j) {
        if (!j.ok) { msg(j.error, false); return; }
        var total = 0; tr.querySelectorAll('input.money').forEach(function (i) { total += parseMoney(i.value); });
        if (j.resultado === 'eliminada' || total === 0) tr.remove(); else tr.querySelector('.cat-nombre').insertAdjacentHTML('afterend', ' <small class="muted">(inactiva)</small>');
        recalc(); msg(j.mensaje, true);
      });
    }
  });
  // Agregar una categoría nueva
  var btnAgregar = document.getElementById('cat-agregar'), campo = document.getElementById('cat-nombre-nuevo');
  function agregar() {
    var nombre = campo.value.trim(); if (!nombre) { msg('Escriba el nombre de la categoría.', false); campo.focus(); return; }
    btnAgregar.disabled = true;
    llamar({ accion: 'cat_crear', nombre: nombre }).then(function (j) {
      btnAgregar.disabled = false;
      if (!j.ok) { msg(j.error, false); return; }
      var tr = document.getElementById('fila-tpl').content.firstElementChild.cloneNode(true);
      tr.setAttribute('data-id', j.id);
      tr.innerHTML = tr.innerHTML.replace(/__ID__/g, j.id);
      tr.querySelector('.cat-nombre').textContent = j.nombre;
      body.appendChild(tr);
      campo.value = ''; recalc(); msg('Categoría «' + j.nombre + '» agregada. Escriba sus valores y guarde el presupuesto.', true);
      var primero = tr.querySelector('input.money'); if (primero) primero.focus();
    });
  }
  if (btnAgregar) { btnAgregar.addEventListener('click', agregar); campo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); agregar(); } }); }
  recalc();
});
</script>
