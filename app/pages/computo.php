<?php
require_once APP_DIR . '/computo.php';
$title = 'Inventario de cómputo';
[$w, $params, $act, $f] = computo_filtros($_GET);
$rows = computo_rows($w, $params);
$n = count($rows);

$cuenta = function (string $campo) use ($rows): array {
    $c = [];
    foreach ($rows as $r) { $k = $r[$campo] ?: 'Sin dato'; $c[$k] = ($c[$k] ?? 0) + 1; }
    arsort($c);
    return $c;
};
$porTipo = array_intersect_key(array_replace(array_fill_keys(COMPUTO_TIPOS, 0), $cuenta('tipo')), array_flip(COMPUTO_TIPOS));
$porSo = $cuenta('so_nombre');
$porMarca = $cuenta('marca');
$porEmpresa = $cuenta('empresa');
$renov = array_replace(['Bajo' => 0, 'Medio' => 0, 'Alto' => 0, 'Sin dato' => 0], $cuenta('renovacion'));

$lic = [];
foreach (['so' => 'Sistema operativo', 'of' => 'Ofimática', 'av' => 'Antivirus'] as $k => $lbl) {
    $c = ['Legal' => 0, 'No legal' => 0, 'Libre' => 0, 'Sin registrar' => 0];
    foreach ($rows as $r) $c[$r[$k . '_lic'] ?: 'Sin registrar']++;
    $lic[$k] = ['lbl' => $lbl, 'c' => $c];
}
$noLegal = count(array_filter($rows, fn($r) => $r['n_no_legal'] > 0));
$avVencido = count(array_filter($rows, fn($r) => $r['av_vence'] && $r['av_vence'] < today()));

// Antigüedad
$edades = ['< 1 año' => 0, '1–2 años' => 0, '3–4 años' => 0, '5–6 años' => 0, '7+ años' => 0, 'Sin fecha' => 0];
$sumA = 0; $nA = 0;
foreach ($rows as $r) {
    if (!$r['fecha_compra']) { $edades['Sin fecha']++; continue; }
    $a = (time() - strtotime($r['fecha_compra'])) / (365.25 * 86400);
    $sumA += $a; $nA++;
    $edades[$a < 1 ? '< 1 año' : ($a < 3 ? '1–2 años' : ($a < 5 ? '3–4 años' : ($a < 7 ? '5–6 años' : '7+ años')))]++;
}
if (!$edades['Sin fecha']) unset($edades['Sin fecha']);

// Software no legal más frecuente
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$topNoLegal = $ids ? all("SELECT nombre, categoria, COUNT(DISTINCT equipo_id) n FROM equipo_software WHERE licencia = 'No legal' AND equipo_id IN (" . implode(',', $ids) . ") GROUP BY nombre, categoria ORDER BY COUNT(DISTINCT equipo_id) DESC, nombre LIMIT 8") : [];
$totalSoft = $ids ? (int)val('SELECT COUNT(*) FROM equipo_software WHERE equipo_id IN (' . implode(',', $ids) . ')') : 0;

$atencion = array_values(array_filter($rows, fn($r) => $r['n_no_legal'] > 0 || $r['renovacion'] === 'Alto' || !$r['av_lic'] || ($r['av_vence'] && $r['av_vence'] < today())));
usort($atencion, fn($a, $b) => [$b['n_no_legal'] > 0, $b['renovacion'] === 'Alto'] <=> [$a['n_no_legal'] > 0, $a['renovacion'] === 'Alto']);
$atencion = array_slice($atencion, 0, 12);

$link = fn(array $cambios) => computo_qs($f, $cambios);
$colLic = ['Legal' => '#0b6e63', 'No legal' => '#c2410c', 'Libre' => '#9aa5b1', 'Sin registrar' => '#dfe4e9'];
$licKey = ['so' => 'lic_so', 'of' => 'lic_of', 'av' => 'lic_av'];
$pct = fn($v, $t) => $t ? round($v / $t * 100) : 0;
?>
<div class="tabs">
  <a class="on" href="<?= e(computo_qs($f, [], 'computo')) ?>">Dashboard</a>
  <a href="<?= e($link([])) ?>">Equipos</a>
  <span class="ms-auto d-flex gap-2 pb-1 flex-wrap">
    <a class="btn btn-sm btn-outline-primary" href="<?= e($link(['exportar' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e($link(['exportar' => 'pdf'])) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Informe PDF</a>
    <?php if (is_admin()): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('importar', ['inv' => 'computo']) ?>"><i class="bi bi-upload me-1"></i>Importar</a>
    <a class="btn btn-sm btn-primary" href="<?= url('computo_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nuevo equipo</a><?php endif; ?>
  </span>
</div>

<?= computo_filtros_form($f, $act, 'computo', true) ?>

<?php if (!$n): ?>
  <div class="panel empty-state"><i class="bi bi-pc-display"></i><h2><?= $act ? 'Ningún equipo cumple los filtros.' : 'Aún no hay equipos de cómputo registrados.' ?></h2>
    <?php if (is_admin() && !$act): ?><div class="d-flex gap-2 justify-content-center mt-2"><a class="btn btn-primary" href="<?= url('computo_form') ?>">Registrar el primero</a><a class="btn btn-outline-primary" href="<?= url('importar', ['inv' => 'computo']) ?>"><i class="bi bi-upload me-1"></i>Importar desde Excel</a></div><?php endif; ?></div>
<?php else: ?>

<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-pc-display"></i>Equipos</div><div class="v"><?= $n ?></div>
    <div class="sub"><?= e(implode(' · ', array_map(fn($k, $v) => $v . ' ' . mb_strtolower($k), array_keys(array_filter($porTipo)), array_filter($porTipo)))) ?></div><a class="stretched" href="<?= e($link([])) ?>" aria-label="Ver equipos"></a></div>
  <div class="kpi <?= $noLegal ? 'danger bad' : 'good' ?>"><div class="lbl"><i class="bi bi-shield-exclamation"></i>Con software no legal</div><div class="v"><?= $noLegal ?></div>
    <div class="sub"><?= $pct($noLegal, $n) ?>% de los equipos</div><a class="stretched" href="<?= e($link(['lic' => 'no_legal'])) ?>" aria-label="Ver equipos con software no legal"></a></div>
  <div class="kpi <?= $renov['Alto'] ? 'warn' : '' ?>"><div class="lbl"><i class="bi bi-arrow-repeat"></i>Renovación alta</div><div class="v"><?= $renov['Alto'] ?></div>
    <div class="sub">Planear reemplazo</div><a class="stretched" href="<?= e($link(['renov' => 'Alto'])) ?>" aria-label="Ver renovación alta"></a></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-shield-check"></i>Antivirus legal</div><div class="v"><?= $lic['av']['c']['Legal'] ?> <small class="fs-6 muted">de <?= $n ?></small></div>
    <div class="sub <?= $avVencido ? 'text-danger' : '' ?>"><?= $avVencido ?> con licencia vencida</div><a class="stretched" href="<?= e($link(['lic' => 'av_vencido'])) ?>" aria-label="Ver antivirus vencido"></a></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-calendar3"></i>Antigüedad promedio</div><div class="v"><?= $nA ? num($sumA / $nA, 1) : '—' ?> <small class="fs-6 muted">años</small></div>
    <div class="sub">según fecha de compra</div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6"><div class="panel h-100 mb-0">
    <div class="panel-h"><h3>Licenciamiento de software base</h3><div class="actions"><span class="small muted">Clic en una barra para filtrar</span></div></div>
    <?php foreach ($lic as $k => $L): ?>
      <div class="lic-row">
        <div class="lbl"><strong><?= e($L['lbl']) ?></strong><span class="muted"><?= $L['c']['Legal'] ?> legal · <?= $L['c']['No legal'] ?> no legal · <?= $L['c']['Libre'] ?> libre<?= $L['c']['Sin registrar'] ? ' · ' . $L['c']['Sin registrar'] . ' sin registrar' : '' ?></span></div>
        <div class="stack">
          <?php foreach ($L['c'] as $estado => $v): if (!$v) continue; ?>
            <a href="<?= e($link([$licKey[$k] => $estado])) ?>" style="width:<?= $v / $n * 100 ?>%;background:<?= $colLic[$estado] ?>" title="<?= e($L['lbl'] . ' · ' . $estado . ': ' . $v) ?>" aria-label="<?= e($L['lbl'] . ' ' . $estado . ': ' . $v . ' equipos') ?>"></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="legend mt-2"><?php foreach ($colLic as $k => $c): ?><span><i style="background:<?= $c ?>"></i><?= $k ?></span><?php endforeach; ?></div>
    <?php if ($topNoLegal): ?>
      <h4 class="sec mb-2">Software no legal más frecuente</h4>
      <table class="tbl"><?php foreach ($topNoLegal as $t): ?><tr><td><?= e($t['nombre']) ?> <small class="muted"><?= e((SOFT_BASE + ['otro' => 'Otro'])[$t['categoria']] ?? '') ?></small></td><td class="r"><?= (int)$t['n'] ?> equipo<?= $t['n'] > 1 ? 's' : '' ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
    <p class="small muted mt-3 mb-0"><?= $totalSoft ?> programas registrados en estos equipos.</p>
  </div></div>

  <div class="col-xl-6"><div class="panel h-100 mb-0">
    <div class="panel-h"><h3>Prioridad de renovación</h3></div>
    <div class="row g-2 mb-3">
      <?php foreach (['Bajo' => '#0b6e63', 'Medio' => '#c58a1a', 'Alto' => '#c2410c'] as $k => $c): ?>
        <div class="col-4"><a class="kpi d-block text-decoration-none text-reset" style="border-top:5px solid <?= $c ?>" href="<?= e($link(['renov' => $k])) ?>">
          <div class="lbl"><?= $k ?></div><div class="v"><?= $renov[$k] ?></div><div class="sub"><?= $pct($renov[$k], $n) ?>% del parque</div></a></div>
      <?php endforeach; ?>
    </div>
    <?php if ($renov['Sin dato']): ?><p class="small muted"><a href="<?= e($link(['renov' => 'Sin definir'])) ?>"><?= $renov['Sin dato'] ?> equipo(s) sin prioridad definida</a></p><?php endif; ?>
    <h4 class="sec mb-2">Antigüedad según fecha de compra</h4>
    <div class="chart-box sm"><canvas id="chEdad" aria-label="Equipos por antigüedad" role="img"></canvas></div>
  </div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="panel h-100 mb-0"><div class="panel-h"><h3>Por tipo</h3></div><div class="chart-box sm"><canvas id="chTipo" role="img" aria-label="Equipos por tipo"></canvas></div></div></div>
  <div class="col-lg-4"><div class="panel h-100 mb-0"><div class="panel-h"><h3>Sistema operativo</h3></div><div class="chart-box sm"><canvas id="chSo" role="img" aria-label="Equipos por sistema operativo"></canvas></div></div></div>
  <div class="col-lg-4"><div class="panel h-100 mb-0"><div class="panel-h"><h3><?= is_admin() && count($porEmpresa) > 1 ? 'Por propietario' : 'Por marca' ?></h3></div><div class="chart-box sm"><canvas id="chProp" role="img" aria-label="Distribución"></canvas></div></div></div>
</div>

<div class="panel">
  <div class="panel-h"><h3>Requieren atención</h3><div class="actions"><span class="small muted">Software no legal, renovación alta o antivirus faltante / vencido</span></div></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Usuario</th><th>Equipo</th><th>Motivo</th><th>Renovación</th></tr></thead>
    <tbody>
    <?php foreach ($atencion as $r):
        $mot = [];
        foreach (['so' => 'S.O.', 'of' => 'Ofimática', 'av' => 'Antivirus'] as $k => $l) if ($r[$k . '_lic'] === 'No legal') $mot[] = $l . ' no legal';
        $otrosNo = (int)$r['n_no_legal'] - count($mot);
        if ($otrosNo > 0) $mot[] = $otrosNo . ' programa(s) no legal';
        if (!$r['av_lic']) $mot[] = 'Sin antivirus registrado';
        elseif ($r['av_vence'] && $r['av_vence'] < today()) $mot[] = 'Antivirus vencido';
        if ($r['renovacion'] === 'Alto') $mot[] = 'Renovación alta'; ?>
      <tr>
        <td><a class="row-link" href="<?= url('equipo', ['id' => $r['id']]) ?>"><?= e(computo_usuario($r)) ?></a><br><small class="muted"><?= e($r['cargo'] ?: $r['codigo']) ?></small></td>
        <td><?= e($r['tipo'] . ' ' . trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''))) ?></td>
        <td><?= e(implode(' · ', $mot)) ?></td>
        <td><?= $r['renovacion'] ? badge($r['renovacion']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$atencion): ?><tr><td colspan="4" class="empty">Todo en orden: no hay equipos con alertas.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php
$third = is_admin() && count($porEmpresa) > 1 ? ['data' => array_slice($porEmpresa, 0, 8, true), 'key' => 'empresa']
                                                : ['data' => array_slice($porMarca, 0, 8, true), 'key' => 'marca'];
$empIds = array_flip(empresas_list());
$chartData = [
    'edad' => ['labels' => array_keys($edades), 'values' => array_values($edades)],
    'tipo' => ['labels' => array_keys($porTipo), 'values' => array_values($porTipo), 'links' => array_map(fn($t) => $link(['tipo' => $t]), array_keys($porTipo))],
    'so' => ['labels' => array_keys(array_slice($porSo, 0, 7, true)), 'values' => array_values(array_slice($porSo, 0, 7, true)),
        'links' => array_map(fn($s) => $s === 'Sin dato' ? $link(['lic_so' => 'Sin registrar']) : $link(['so' => $s]), array_keys(array_slice($porSo, 0, 7, true)))],
    'prop' => ['labels' => array_keys($third['data']), 'values' => array_values($third['data']),
        'links' => array_map(fn($k) => $k === 'Sin dato' ? null : ($third['key'] === 'empresa' ? $link(['empresa' => $empIds[$k] ?? null]) : $link(['marca' => $k])), array_keys($third['data']))],
];
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var D = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = '#56616d';
  function bar(id, d, opts) {
    opts = opts || {};
    var horizontal = !!opts.horizontal;
    var scaleVal = {beginAtZero: true, grid: {color: '#eef1f4'}, ticks: {precision: 0}};
    var scaleCat = {grid: {display: false}};
    new Chart(document.getElementById(id), {
      type: 'bar',
      data: {labels: d.labels, datasets: [{data: d.values, backgroundColor: opts.colors || '#1d5fa8', borderRadius: 4, maxBarThickness: 30}]},
      options: {
        indexAxis: horizontal ? 'y' : 'x', responsive: true, maintainAspectRatio: false,
        plugins: {legend: {display: false}, tooltip: {callbacks: {label: function (c) { return ' ' + c.parsed[horizontal ? 'x' : 'y'] + ' equipos'; }}}},
        scales: horizontal ? {x: scaleVal, y: scaleCat} : {x: scaleCat, y: scaleVal},
        onHover: function (e, el) { e.native.target.style.cursor = el.length && d.links && d.links[el[0].index] ? 'pointer' : 'default'; },
        onClick: function (e, el) { if (el.length && d.links && d.links[el[0].index]) window.location = d.links[el[0].index]; }
      }
    });
  }
  bar('chEdad', D.edad, {colors: D.edad.labels.map(function (l) { return l.indexOf('7+') === 0 ? '#c2410c' : (l.indexOf('5') === 0 ? '#c58a1a' : (l === 'Sin fecha' ? '#b8c2cc' : '#0b6e63')); })});
  bar('chTipo', D.tipo, {horizontal: true});
  bar('chSo', D.so, {horizontal: true});
  bar('chProp', D.prop, {horizontal: true});
})();
</script>
<?php endif; ?>
