<?php
require_role('admin');
$anio = (int)get('anio', date('Y'));
$title = 'Entradas de dinero ' . $anio;
$propias = empresas_list('propia');
$corte = $anio < (int)date('Y') ? 12 : ($anio > (int)date('Y') ? 0 : (int)date('n'));

// Recaudos (pagos) por mes y empresa; facturación por mes y empresa
$rec = $fac = [];
foreach ($propias as $pid => $_) { $rec[$pid] = array_fill(1, 12, 0.0); $fac[$pid] = array_fill(1, 12, 0.0); }
foreach (all('SELECT f.empresa_id e, MONTH(p.fecha) m, SUM(p.valor) t FROM pagos p JOIN facturas f ON f.id = p.factura_id WHERE YEAR(p.fecha) = ? GROUP BY f.empresa_id, MONTH(p.fecha)', [$anio]) as $r) {
    if (isset($rec[$r['e']])) $rec[$r['e']][(int)$r['m']] = (float)$r['t'];
}
foreach (all('SELECT empresa_id e, MONTH(fecha_emision) m, SUM(total) t FROM facturas WHERE anulada = 0 AND YEAR(fecha_emision) = ? GROUP BY empresa_id, MONTH(fecha_emision)', [$anio]) as $r) {
    if (isset($fac[$r['e']])) $fac[$r['e']][(int)$r['m']] = (float)$r['t'];
}
$totMes = array_fill(1, 12, 0.0);
foreach ($rec as $serie) for ($m = 1; $m <= 12; $m++) $totMes[$m] += $serie[$m];
$totAnio = array_sum($totMes);
$gastoAnio = (float)val('SELECT COALESCE(SUM(valor),0) FROM gastos WHERE YEAR(fecha) = ?', [$anio]);
$gMes = array_fill(1, 12, 0.0);
foreach (all('SELECT MONTH(fecha) m, SUM(valor) t FROM gastos WHERE YEAR(fecha) = ? GROUP BY MONTH(fecha)', [$anio]) as $r) $gMes[(int)$r['m']] = (float)$r['t'];

// Detalle de pagos del mes seleccionado
$mes = get('mes', $corte ? (string)$corte : 'todos');
$pw = 'YEAR(p.fecha) = ?';
$pp = [$anio];
if ($mes !== 'todos') { $pw .= ' AND MONTH(p.fecha) = ?'; $pp[] = (int)$mes; }
if ($emp = (int)get('empresa', 0)) { $pw .= ' AND f.empresa_id = ?'; $pp[] = $emp; }
$psql = "SELECT p.*, f.numero, f.cliente_nombre, f.id AS fid, e.nombre AS empresa FROM pagos p JOIN facturas f ON f.id = p.factura_id JOIN empresas e ON e.id = f.empresa_id WHERE $pw ORDER BY p.fecha DESC, p.id DESC";

if (get('exportar')) {
    $rows = all($psql, $pp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="entradas_' . $anio . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Empresa', 'Factura', 'Cliente', 'Método', 'Referencia', 'Valor'], ';');
    foreach ($rows as $r) fputcsv($out, [$r['fecha'], $r['empresa'], $r['numero'], $r['cliente_nombre'], $r['metodo'], $r['referencia'], number_format((float)$r['valor'], 2, ',', '')], ';');
    exit;
}
$pagos = all($psql . ' LIMIT 300', $pp);
$anios = range((int)date('Y'), (int)date('Y') - 5);
$colores = ['#0f8b7d', '#1d5fa8', '#b7791f', '#7a4fb5'];
$i = 0;
?>
<div class="toolbar">
  <form method="get"><input type="hidden" name="p" value="ingresos"><select class="form-select" name="anio" data-autosubmit><?= options(array_combine($anios, $anios), $anio) ?></select></form>
</div>

<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-cash-coin"></i>Total recaudado <?= $anio ?></div><div class="v"><?= money($totAnio) ?></div><div class="sub">Promedio mensual: <?= money($corte ? $totAnio / $corte : 0) ?></div></div>
  <?php foreach ($propias as $pid => $pnom): ?>
    <div class="kpi"><div class="lbl"><i class="bi bi-building"></i><?= e($pnom) ?></div><div class="v"><?= money(array_sum($rec[$pid])) ?></div><div class="sub">Facturado: <?= money(array_sum($fac[$pid])) ?></div></div>
  <?php endforeach; ?>
  <div class="kpi <?= $totAnio - $gastoAnio < 0 ? 'danger bad' : 'good' ?>"><div class="lbl"><i class="bi bi-arrow-down-up"></i>Recaudo menos gastos</div><div class="v"><?= money($totAnio - $gastoAnio) ?></div><div class="sub">Gastos del año: <?= money($gastoAnio) ?></div></div>
</div>

<div class="panel">
  <div class="panel-h"><h3>Recaudo mensual por empresa</h3></div>
  <div class="chart-box"><canvas id="chIng"></canvas></div>
</div>

<div class="panel">
  <div class="panel-h"><h3>Mensual y acumulado</h3></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Mes</th><?php foreach ($propias as $pnom): ?><th class="r"><?= e($pnom) ?></th><?php endforeach; ?><th class="r">Total recaudado</th><th class="r">Acumulado</th><th class="r">Facturado</th><th class="r">Gastos</th><th class="r">Neto</th></tr></thead>
    <tbody>
    <?php $acum = 0; for ($m = 1; $m <= 12; $m++): $acum += $totMes[$m]; $fm = 0; foreach ($fac as $s) $fm += $s[$m]; $neto = $totMes[$m] - $gMes[$m]; ?>
      <tr class="<?= $m > $corte ? 'muted' : '' ?>">
        <td><a href="<?= url('ingresos', ['anio' => $anio, 'mes' => $m]) ?>#detalle"><?= MESES[$m] ?></a></td>
        <?php foreach ($propias as $pid => $_): ?><td class="r"><?= money($rec[$pid][$m]) ?></td><?php endforeach; ?>
        <td class="r fw-semibold"><?= money($totMes[$m]) ?></td>
        <td class="r"><?= money($acum) ?></td>
        <td class="r muted"><?= money($fm) ?></td>
        <td class="r muted"><?= money($gMes[$m]) ?></td>
        <td class="r <?= $neto < 0 ? 'text-danger' : '' ?>"><?= money($neto) ?></td>
      </tr>
    <?php endfor; ?>
    </tbody>
    <tfoot><tr><td>Total</td><?php foreach ($propias as $pid => $_): ?><td class="r"><?= money(array_sum($rec[$pid])) ?></td><?php endforeach; ?><td class="r"><?= money($totAnio) ?></td><td></td><td class="r"><?php $tf = 0; foreach ($fac as $s) $tf += array_sum($s); echo money($tf); ?></td><td class="r"><?= money($gastoAnio) ?></td><td class="r <?= $totAnio - $gastoAnio < 0 ? 'text-danger' : '' ?>"><?= money($totAnio - $gastoAnio) ?></td></tr></tfoot>
  </table></div>
</div>

<div class="panel" id="detalle">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="ingresos"><input type="hidden" name="anio" value="<?= $anio ?>">
    <h3 class="h6 m-0 me-2 align-self-center">Pagos recibidos</h3>
    <select class="form-select" name="mes" data-autosubmit><option value="todos" <?= $mes === 'todos' ? 'selected' : '' ?>>Todo el año</option><?= options(MESES, $mes) ?></select>
    <select class="form-select" name="empresa" data-autosubmit><option value="">Ambas empresas</option><?= options($propias, get('empresa')) ?></select>
    <div class="actions"><a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query(array_filter(['p' => 'ingresos', 'anio' => $anio, 'mes' => $mes, 'empresa' => get('empresa'), 'exportar' => 1]))) ?>"><i class="bi bi-download me-1"></i>Excel</a></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Fecha</th><th>Empresa</th><th>Factura</th><th>Cliente</th><th>Método</th><th>Referencia</th><th class="r">Valor</th></tr></thead>
    <tbody>
    <?php $sum = 0; foreach ($pagos as $p): $sum += (float)$p['valor']; ?>
      <tr><td class="nowrap"><?= fdate($p['fecha']) ?></td><td class="muted"><?= e($p['empresa']) ?></td><td><a href="<?= url('factura', ['id' => $p['fid']]) ?>"><?= e($p['numero']) ?></a></td><td><?= e($p['cliente_nombre']) ?></td><td><?= e($p['metodo'] ?: '—') ?></td><td class="muted"><?= e($p['referencia'] ?: '—') ?></td><td class="r"><?= money($p['valor']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pagos): ?><tr><td colspan="7" class="empty">Sin pagos en este periodo.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($pagos): ?><tfoot><tr><td colspan="6">Total</td><td class="r"><?= money($sum) ?></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = '#64707d';
  var short = function (v) { return v >= 1e6 ? (v / 1e6).toLocaleString('es-CO', {maximumFractionDigits: 1}) + ' M' : (v >= 1e3 ? Math.round(v / 1e3) + ' mil' : v); };
  new Chart(document.getElementById('chIng'), {
    type: 'bar',
    data: {labels: <?= json_encode(array_values(MESES_CORTOS)) ?>, datasets: [
      <?php foreach ($propias as $pid => $pnom): ?>
      {label: <?= json_encode($pnom, JSON_HEX_TAG) ?>, data: <?= json_encode(array_values($rec[$pid])) ?>, backgroundColor: '<?= $colores[$i++ % 4] ?>', borderRadius: 3, stack: 'r', maxBarThickness: 34},
      <?php endforeach; ?>
      {label: 'Gastos', type: 'line', data: <?= json_encode(array_values($gMes)) ?>, borderColor: '#c9372c', backgroundColor: '#c9372c', borderWidth: 2, pointRadius: 2, tension: .3}
    ]},
    options: {
      responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
      plugins: {legend: {position: 'bottom', labels: {boxWidth: 12, usePointStyle: true}}, tooltip: {callbacks: {label: function (c) { return ' ' + c.dataset.label + ': $ ' + Math.round(c.parsed.y).toLocaleString('es-CO'); }}}},
      scales: {x: {stacked: true, grid: {display: false}}, y: {stacked: true, beginAtZero: true, grid: {color: '#eef1f4'}, ticks: {callback: short}}}
    }
  });
})();
</script>
