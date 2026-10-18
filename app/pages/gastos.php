<?php
require_perm('gastos', 'ver');
$title = 'Gastos';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'eliminar') {
    require_perm('gastos', 'eliminar');
    $gid = (int)post('id');
    foreach (adjuntos('gasto', $gid) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
    q("DELETE FROM adjuntos WHERE entidad = 'gasto' AND entidad_id = ?", [$gid]);
    q('DELETE FROM gastos WHERE id = ?', [$gid]);
    flash('Gasto eliminado.');
    $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
    redirect($ref ? 'index.php?' . $ref : url('gastos'));
}

$anio = (int)get('anio', date('Y'));
$mes = get('mes', (string)(int)date('n'));
$where = ['YEAR(g.fecha) = ?'];
$params = [$anio];
if ($mes !== 'todos') { $where[] = 'MONTH(g.fecha) = ?'; $params[] = (int)$mes; }
if ($cat = (int)get('cat', 0)) { $where[] = 'g.categoria_id = ?'; $params[] = $cat; }
if ($emp = (int)get('empresa', 0)) { $where[] = 'g.empresa_id = ?'; $params[] = $emp; }
if ($b = get('b')) { $where[] = '(g.concepto LIKE ? OR g.proveedor LIKE ? OR g.referencia LIKE ?)'; array_push($params, "%$b%", "%$b%", "%$b%"); }
$w = implode(' AND ', $where);
$sql = "SELECT g.*, c.nombre AS categoria, e.nombre AS empresa,
        (SELECT COUNT(*) FROM adjuntos a WHERE a.entidad = 'gasto' AND a.entidad_id = g.id) AS n_adj
    FROM gastos g JOIN gasto_categorias c ON c.id = g.categoria_id LEFT JOIN empresas e ON e.id = g.empresa_id
    WHERE $w ORDER BY g.fecha DESC, g.id DESC";

if (get('exportar')) {
    $rows = all($sql, $params);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gastos_' . $anio . ($mes !== 'todos' ? '_' . str_pad($mes, 2, '0', STR_PAD_LEFT) : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Empresa', 'Categoría', 'Proveedor', 'Concepto', 'Valor', 'Método de pago', 'Referencia', 'Notas'], ';');
    foreach ($rows as $r) fputcsv($out, [$r['fecha'], $r['empresa'], $r['categoria'], $r['proveedor'], $r['concepto'], number_format((float)$r['valor'], 2, ',', ''), $r['metodo_pago'], $r['referencia'], $r['notas']], ';');
    exit;
}

$rows = all($sql, $params);
$total = array_sum(array_map(fn($r) => (float)$r['valor'], $rows));
$porCat = [];
foreach ($rows as $r) $porCat[$r['categoria']] = ($porCat[$r['categoria']] ?? 0) + (float)$r['valor'];
arsort($porCat);

$presu = $mes !== 'todos'
    ? (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ? AND mes = ?' . ($cat ? ' AND categoria_id = ' . $cat : ''), [$anio, (int)$mes])
    : (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ?' . ($cat ? ' AND categoria_id = ' . $cat : ''), [$anio]);
$pct = $presu > 0 ? $total / $presu * 100 : null;
$cats = array_column(all('SELECT id, nombre FROM gasto_categorias ORDER BY nombre'), 'nombre', 'id');
$anios = range((int)date('Y') + 1, (int)date('Y') - 5);
$qs = array_filter(['p' => 'gastos', 'anio' => $anio, 'mes' => $mes, 'cat' => get('cat'), 'empresa' => get('empresa'), 'b' => get('b')]);
$periodo = $mes === 'todos' ? 'Año ' . $anio : MESES[(int)$mes] . ' ' . $anio;
?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-wallet2"></i>Total gastado · <?= e($periodo) ?></div><div class="v"><?= money($total) ?></div><div class="sub"><?= count($rows) ?> registros</div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-clipboard-data"></i>Presupuesto del periodo</div><div class="v"><?= money($presu) ?></div><div class="sub"><?= $presu > 0 ? 'Disponible: ' . money($presu - $total) : 'Sin presupuesto definido' ?></div></div>
  <div class="kpi <?= $pct !== null && $pct > 100 ? 'danger bad' : ($pct !== null && $pct > 85 ? 'warn' : '') ?>"><div class="lbl"><i class="bi bi-speedometer2"></i>Ejecución</div><div class="v"><?= $pct !== null ? num($pct, 1) . ' %' : '—' ?></div>
    <?php if ($pct !== null): ?><div class="bar mt-2 <?= $pct > 100 ? 'over' : ($pct > 85 ? 'near' : '') ?>"><span style="width:<?= min(100, $pct) ?>%"></span></div><?php endif; ?></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-tag"></i>Mayor categoría</div><div class="v" style="font-size:18px"><?= $porCat ? e(array_key_first($porCat)) : '—' ?></div><div class="sub"><?= $porCat ? money(reset($porCat)) : '' ?></div></div>
</div>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="gastos">
    <select class="form-select" name="anio" data-autosubmit><?= options(array_combine($anios, $anios), $anio) ?></select>
    <select class="form-select" name="mes" data-autosubmit><option value="todos" <?= $mes === 'todos' ? 'selected' : '' ?>>Todo el año</option><?= options(MESES, $mes) ?></select>
    <select class="form-select" name="cat" data-autosubmit><option value="">Toda categoría</option><?= options($cats, get('cat')) ?></select>
    <select class="form-select" name="empresa" data-autosubmit><option value="">Toda empresa</option><?= options(empresas_list('propia'), get('empresa')) ?></select>
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Concepto, proveedor, referencia">
    <div class="actions">
      <a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 1])) ?>"><i class="bi bi-download me-1"></i>Excel</a>
      <?php if (can('gastos', 'crear')): ?><a class="btn btn-primary" href="<?= url('gasto_form') ?>"><i class="bi bi-plus-lg me-1"></i>Registrar gasto</a><?php endif; ?>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Fecha</th><th>Categoría</th><th>Concepto</th><th>Proveedor</th><th>Empresa</th><th>Método</th><th class="r">Valor</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= fdate($r['fecha']) ?></td>
        <td><?= e($r['categoria']) ?></td>
        <td><?= can('gastos', 'editar') ? '<a class="row-link fw-normal" href="' . url('gasto_form', ['id' => $r['id']]) . '">' . e($r['concepto']) . '</a>' : e($r['concepto']) ?><?= $r['n_adj'] ? ' <i class="bi bi-paperclip muted" title="Tiene soporte"></i>' : '' ?><?= $r['referencia'] ? '<br><small class="muted">Ref. ' . e($r['referencia']) . '</small>' : '' ?></td>
        <td><?= e($r['proveedor'] ?: '—') ?></td>
        <td class="muted"><?= e($r['empresa'] ?? '—') ?></td>
        <td class="muted"><?= e($r['metodo_pago'] ?: '—') ?></td>
        <td class="r"><?= money($r['valor']) ?></td>
        <td class="r nowrap">
          <?php if (can('gastos', 'editar')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('gasto_form', ['id' => $r['id']]) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
          <?php if (can('gastos', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar este gasto?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No hay gastos registrados en <?= e($periodo) ?>.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><td colspan="6">Total</td><td class="r"><?= money($total) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<?php if (count($porCat) > 1): ?>
<div class="panel">
  <div class="panel-h"><h3>Distribución por categoría · <?= e($periodo) ?></h3></div>
  <table class="tbl">
    <?php foreach ($porCat as $c => $v): $p = $total ? $v / $total * 100 : 0; ?>
      <tr><td style="width:30%"><?= e($c) ?></td><td><div class="bar"><span style="width:<?= $p ?>%"></span></div></td><td class="r" style="width:90px"><?= num($p, 1) ?> %</td><td class="r" style="width:150px"><?= money($v) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
