<?php
require_perm('estadisticas', 'editar');
$anio = (int)get('anio', date('Y'));
$title = 'Presupuesto ' . $anio;
$cats = all('SELECT id, nombre, activo FROM gasto_categorias ORDER BY activo DESC, nombre');

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
  <p class="small muted">Escriba el valor mensual presupuestado por categoría. Use <i class="bi bi-arrow-right-square"></i> para repetir el valor de enero en todos los meses.</p>
  <form method="post" id="fpres">
    <?= csrf_field() ?>
    <div class="tbl-wrap"><table class="tbl budget">
      <thead><tr><th>Categoría</th><?php foreach (MESES_CORTOS as $m): ?><th class="r"><?= $m ?></th><?php endforeach; ?><th class="r">Total año</th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): $rowTot = array_sum($pres[$c['id']] ?? []); if (!$c['activo'] && !$rowTot) continue; ?>
        <tr data-row>
          <td class="nowrap"><button type="button" class="btn btn-sm btn-link p-0 me-1" data-fill title="Repetir enero en todos los meses"><i class="bi bi-arrow-right-square"></i></button><?= e($c['nombre']) ?></td>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <td><input class="form-control money" name="v[<?= (int)$c['id'] ?>][<?= $m ?>]" value="<?= isset($pres[$c['id']][$m]) ? e($pres[$c['id']][$m]) : '' ?>" inputmode="decimal"></td>
          <?php endfor; ?>
          <td class="r fw-semibold" data-rowtot><?= money($rowTot) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><?php for ($m = 1; $m <= 12; $m++): ?><td class="r" data-coltot="<?= $m ?>"></td><?php endfor; ?><td class="r" data-grand></td></tr></tfoot>
    </table></div>
    <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar presupuesto <?= $anio ?></button></div>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('fpres');
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
  f.querySelectorAll('[data-fill]').forEach(function (b) {
    b.addEventListener('click', function () {
      var ins = b.closest('tr').querySelectorAll('input.money');
      ins.forEach(function (i) { i.value = ins[0].value; });
      recalc();
    });
  });
  recalc();
});
</script>
