<?php
require_role('admin');
$anio = (int)get('anio', date('Y'));
$title = 'Estadísticas de gastos ' . $anio;
$corte = $anio < (int)date('Y') ? 12 : ($anio > (int)date('Y') ? 0 : (int)date('n'));

$gMes = array_fill(1, 12, 0.0);
foreach (all('SELECT MONTH(fecha) m, SUM(valor) t FROM gastos WHERE YEAR(fecha) = ? GROUP BY MONTH(fecha)', [$anio]) as $r) $gMes[(int)$r['m']] = (float)$r['t'];
$pMes = array_fill(1, 12, 0.0);
foreach (all('SELECT mes m, SUM(valor) t FROM presupuestos WHERE anio = ? GROUP BY mes', [$anio]) as $r) $pMes[(int)$r['m']] = (float)$r['t'];
$antMes = array_fill(1, 12, 0.0);
foreach (all('SELECT MONTH(fecha) m, SUM(valor) t FROM gastos WHERE YEAR(fecha) = ? GROUP BY MONTH(fecha)', [$anio - 1]) as $r) $antMes[(int)$r['m']] = (float)$r['t'];

$gAcum = $pAcum = [];
$sg = $sp = 0;
for ($m = 1; $m <= 12; $m++) { $sg += $gMes[$m]; $sp += $pMes[$m]; $gAcum[$m] = $sg; $pAcum[$m] = $sp; }

$acumCorte = $corte ? $gAcum[$corte] : 0;
$presCorte = $corte ? $pAcum[$corte] : 0;
$presAnual = $pAcum[12];
$antCorte = 0; for ($m = 1; $m <= max(1, $corte); $m++) $antCorte += $antMes[$m];
$variacion = $antCorte > 0 && $corte ? ($acumCorte - $antCorte) / $antCorte * 100 : null;
$proyeccion = $corte ? $acumCorte / $corte * 12 : 0;
$ejec = $presCorte > 0 ? $acumCorte / $presCorte * 100 : null;

// Matriz categoría × mes
$cats = all('SELECT id, nombre FROM gasto_categorias ORDER BY nombre');
$mat = [];
foreach (all('SELECT categoria_id c, MONTH(fecha) m, SUM(valor) t FROM gastos WHERE YEAR(fecha) = ? GROUP BY categoria_id, MONTH(fecha)', [$anio]) as $r) $mat[$r['c']][(int)$r['m']] = (float)$r['t'];
$presCat = array_column(all('SELECT categoria_id, SUM(valor) t FROM presupuestos WHERE anio = ? GROUP BY categoria_id', [$anio]), 't', 'categoria_id');
$presCatCorte = $corte ? array_column(all('SELECT categoria_id, SUM(valor) t FROM presupuestos WHERE anio = ? AND mes <= ? GROUP BY categoria_id', [$anio, $corte]), 't', 'categoria_id') : [];
$anios = range((int)date('Y') + 1, (int)date('Y') - 5);
$mesNom = $corte ? MESES[$corte] : '';
?>
<div class="tabs">
  <a class="on" href="<?= url('estadisticas', ['anio' => $anio]) ?>">Estadísticas</a>
  <a href="<?= url('presupuesto', ['anio' => $anio]) ?>">Presupuesto</a>
  <form method="get" class="ms-auto pb-1"><input type="hidden" name="p" value="estadisticas"><select class="form-select form-select-sm" name="anio" data-autosubmit><?= options(array_combine($anios, $anios), $anio) ?></select></form>
</div>

<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-calendar-month"></i>Gasto <?= e($mesNom ?: 'del mes') ?></div><div class="v"><?= money($corte ? $gMes[$corte] : 0) ?></div><div class="sub">Presupuesto: <?= money($corte ? $pMes[$corte] : 0) ?></div></div>
  <div class="kpi <?= $ejec !== null && $ejec > 100 ? 'danger bad' : '' ?>"><div class="lbl"><i class="bi bi-graph-up"></i>Acumulado a <?= e($mesNom ?: '—') ?></div><div class="v"><?= money($acumCorte) ?></div>
    <div class="sub"><?= $ejec !== null ? num($ejec, 1) . ' % del presupuesto acumulado (' . money($presCorte) . ')' : 'Sin presupuesto definido' ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-clipboard-data"></i>Presupuesto anual</div><div class="v"><?= money($presAnual) ?></div><div class="sub">Disponible: <?= money($presAnual - $gAcum[12]) ?></div></div>
  <div class="kpi <?= $presAnual > 0 && $proyeccion > $presAnual ? 'warn' : '' ?>"><div class="lbl"><i class="bi bi-binoculars"></i>Proyección al cierre</div><div class="v"><?= money($proyeccion) ?></div><div class="sub">Promedio mensual: <?= money($corte ? $acumCorte / $corte : 0) ?></div></div>
  <div class="kpi <?= $variacion !== null && $variacion > 0 ? 'bad' : 'good' ?>"><div class="lbl"><i class="bi bi-arrow-left-right"></i>vs. <?= $anio - 1 ?> (mismo periodo)</div><div class="v"><?= $variacion !== null ? ($variacion > 0 ? '+' : '') . num($variacion, 1) . ' %' : '—' ?></div><div class="sub"><?= $anio - 1 ?>: <?= money($antCorte) ?></div></div>
</div>

<div class="row g-3">
  <div class="col-xl-7"><div class="panel h-100"><div class="panel-h"><h3>Gasto vs. presupuesto por mes</h3></div><div class="chart-box"><canvas id="chMes"></canvas></div></div></div>
  <div class="col-xl-5"><div class="panel h-100"><div class="panel-h"><h3>Acumulado del año</h3></div><div class="chart-box"><canvas id="chAcum"></canvas></div></div></div>
</div>

<div class="panel mt-3">
  <div class="panel-h"><h3>Mensual y acumulado</h3></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Mes</th><th class="r">Gasto</th><th class="r">Presupuesto</th><th class="r">Diferencia</th><th>Ejecución</th><th class="r">Gasto acumulado</th><th class="r">Presupuesto acumulado</th><th class="r"><?= $anio - 1 ?></th></tr></thead>
    <tbody>
    <?php for ($m = 1; $m <= 12; $m++): $dif = $pMes[$m] - $gMes[$m]; $pc = $pMes[$m] > 0 ? $gMes[$m] / $pMes[$m] * 100 : null; ?>
      <tr class="<?= $m > $corte ? 'muted' : '' ?>">
        <td><?= MESES[$m] ?></td>
        <td class="r"><?= money($gMes[$m]) ?></td>
        <td class="r"><?= money($pMes[$m]) ?></td>
        <td class="r <?= $dif < 0 ? 'text-danger' : '' ?>"><?= money($dif) ?></td>
        <td style="min-width:140px"><?php if ($pc !== null): ?><div class="d-flex align-items-center gap-2"><div class="bar flex-grow-1 <?= $pc > 100 ? 'over' : ($pc > 85 ? 'near' : '') ?>"><span style="width:<?= min(100, $pc) ?>%"></span></div><small><?= num($pc, 0) ?>%</small></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="r"><?= money($gAcum[$m]) ?></td>
        <td class="r"><?= money($pAcum[$m]) ?></td>
        <td class="r muted"><?= money($antMes[$m]) ?></td>
      </tr>
    <?php endfor; ?>
    </tbody>
    <tfoot><tr><td>Total año</td><td class="r"><?= money($gAcum[12]) ?></td><td class="r"><?= money($presAnual) ?></td><td class="r <?= $presAnual - $gAcum[12] < 0 ? 'text-danger' : '' ?>"><?= money($presAnual - $gAcum[12]) ?></td><td></td><td></td><td></td><td class="r"><?= money(array_sum($antMes)) ?></td></tr></tfoot>
  </table></div>
</div>

<div class="panel">
  <div class="panel-h"><h3>Por categoría</h3><div class="actions"><span class="small muted">Ejecución calculada contra el presupuesto acumulado a <?= e($mesNom ?: '—') ?></span></div></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Categoría</th><?php foreach (MESES_CORTOS as $mc): ?><th class="r"><?= $mc ?></th><?php endforeach; ?><th class="r">Total</th><th class="r">Presup. año</th><th>Ejecución</th></tr></thead>
    <tbody>
    <?php $colT = array_fill(1, 12, 0.0);
    foreach ($cats as $c): $row = $mat[$c['id']] ?? []; $tot = array_sum($row); $pa = (float)($presCat[$c['id']] ?? 0);
        if (!$tot && !$pa) continue;
        $pcc = (float)($presCatCorte[$c['id']] ?? 0); $acc = 0; for ($m = 1; $m <= $corte; $m++) $acc += $row[$m] ?? 0;
        $pc = $pcc > 0 ? $acc / $pcc * 100 : null; ?>
      <tr>
        <td class="nowrap"><a href="<?= url('gastos', ['anio' => $anio, 'mes' => 'todos', 'cat' => $c['id']]) ?>"><?= e($c['nombre']) ?></a></td>
        <?php for ($m = 1; $m <= 12; $m++): $v = $row[$m] ?? 0; $colT[$m] += $v; ?><td class="r <?= $v ? '' : 'muted' ?>"><?= $v ? num($v) : '·' ?></td><?php endfor; ?>
        <td class="r fw-semibold"><?= money($tot) ?></td>
        <td class="r muted"><?= money($pa) ?></td>
        <td style="min-width:130px"><?php if ($pc !== null): ?><div class="d-flex align-items-center gap-2"><div class="bar flex-grow-1 <?= $pc > 100 ? 'over' : ($pc > 85 ? 'near' : '') ?>"><span style="width:<?= min(100, $pc) ?>%"></span></div><small><?= num($pc, 0) ?>%</small></div><?php else: ?><span class="muted small">Sin presupuesto</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><?php for ($m = 1; $m <= 12; $m++): ?><td class="r"><?= num($colT[$m]) ?></td><?php endfor; ?><td class="r"><?= money(array_sum($colT)) ?></td><td class="r"><?= money($presAnual) ?></td><td></td></tr></tfoot>
  </table></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = '#64707d';
  var labels = <?= json_encode(array_values(MESES_CORTOS)) ?>;
  var money = function (v) { return '$ ' + Math.round(v).toLocaleString('es-CO'); };
  var short = function (v) { return v >= 1e6 ? (v / 1e6).toLocaleString('es-CO', {maximumFractionDigits: 1}) + ' M' : (v >= 1e3 ? Math.round(v / 1e3) + ' mil' : v); };
  var common = {
    responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
    plugins: {legend: {position: 'bottom', labels: {boxWidth: 12, usePointStyle: true}}, tooltip: {callbacks: {label: function (c) { return ' ' + c.dataset.label + ': ' + money(c.parsed.y); }}}},
    scales: {y: {beginAtZero: true, grid: {color: '#eef1f4'}, ticks: {callback: short}}, x: {grid: {display: false}}}
  };
  new Chart(document.getElementById('chMes'), {
    type: 'bar',
    data: {labels: labels, datasets: [
      {label: 'Gasto', data: <?= json_encode(array_values($gMes)) ?>, backgroundColor: '#0f8b7d', borderRadius: 4, maxBarThickness: 28},
      {label: 'Presupuesto', data: <?= json_encode(array_values($pMes)) ?>, backgroundColor: '#cdd6de', borderRadius: 4, maxBarThickness: 28},
      {label: '<?= $anio - 1 ?>', type: 'line', data: <?= json_encode(array_values($antMes)) ?>, borderColor: '#b7791f', backgroundColor: '#b7791f', borderWidth: 2, pointRadius: 2, tension: .3}
    ]},
    options: common
  });
  var corte = <?= (int)$corte ?>;
  var gA = <?= json_encode(array_values($gAcum)) ?>.map(function (v, i) { return i < corte ? v : null; });
  new Chart(document.getElementById('chAcum'), {
    type: 'line',
    data: {labels: labels, datasets: [
      {label: 'Gasto acumulado', data: gA, borderColor: '#0f8b7d', backgroundColor: 'rgba(15,139,125,.12)', fill: true, borderWidth: 2.5, pointRadius: 3, tension: .25},
      {label: 'Presupuesto acumulado', data: <?= json_encode(array_values($pAcum)) ?>, borderColor: '#64707d', borderDash: [6, 4], borderWidth: 2, pointRadius: 0, tension: .25}
    ]},
    options: common
  });
})();
</script>
