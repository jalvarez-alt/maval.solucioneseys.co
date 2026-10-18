<?php
require_role('admin');
$title = 'Cartera';
$propias = empresas_list('propia');
$emp = (int)get('empresa', 0);
if ($emp && !isset($propias[$emp])) $emp = 0;
$hoy = today();

$base = "FROM facturas f
    JOIN empresas e ON e.id = f.empresa_id
    LEFT JOIN (SELECT factura_id, SUM(valor) AS pagado FROM pagos GROUP BY factura_id) p ON p.factura_id = f.id";
$saldo = '(f.total - COALESCE(p.pagado, 0))';

$where = ['1=1'];
$params = [];
if ($emp) { $where[] = 'f.empresa_id = ?'; $params[] = $emp; }
$estado = get('estado', 'pendientes');
switch ($estado) {
    case 'pendientes': $where[] = "f.anulada = 0 AND $saldo > 0.5"; break;
    case 'vencidas': $where[] = "f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento < ?"; $params[] = $hoy; break;
    case 'pagadas': $where[] = "f.anulada = 0 AND $saldo <= 0.5"; break;
    case 'anuladas': $where[] = 'f.anulada = 1'; break;
}
if ($anio = (int)get('anio', 0)) { $where[] = 'YEAR(f.fecha_emision) = ?'; $params[] = $anio; }
if ($b = get('b')) { $where[] = '(f.numero LIKE ? OR f.cliente_nombre LIKE ? OR f.cliente_nit LIKE ? OR f.concepto LIKE ?)'; array_push($params, "%$b%", "%$b%", "%$b%", "%$b%"); }
$w = implode(' AND ', $where);
$sql = "SELECT f.*, e.nombre AS empresa, COALESCE(p.pagado, 0) AS pagado, $saldo AS saldo $base WHERE $w ORDER BY f.fecha_vencimiento ASC, f.id DESC";

if (get('exportar')) {
    $rows = all($sql, $params);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartera_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Empresa', 'Factura', 'Cliente', 'NIT', 'Emisión', 'Vencimiento', 'Días vencida', 'Total', 'Abonado', 'Saldo', 'Estado', 'Concepto'], ';');
    foreach ($rows as $r) {
        $dv = $r['saldo'] > 0.5 && !$r['anulada'] ? max(0, (int)((strtotime($hoy) - strtotime($r['fecha_vencimiento'])) / 86400)) : 0;
        fputcsv($out, [$r['empresa'], $r['numero'], $r['cliente_nombre'], $r['cliente_nit'], $r['fecha_emision'], $r['fecha_vencimiento'], $dv,
            number_format((float)$r['total'], 2, ',', ''), number_format((float)$r['pagado'], 2, ',', ''), number_format((float)$r['saldo'], 2, ',', ''), factura_estado($r), $r['concepto']], ';');
    }
    exit;
}

$per = 30;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) $base WHERE $w", $params);
$rows = all($sql . " LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$sumas = one("SELECT COALESCE(SUM(f.total),0) total, COALESCE(SUM(COALESCE(p.pagado,0)),0) pagado, COALESCE(SUM($saldo),0) saldo $base WHERE $w", $params);

// Resumen por empresa propia
$resumen = [];
$anioAct = (int)date('Y');
foreach ($propias as $pid => $pnom) {
    $resumen[$pid] = [
        'nombre' => $pnom,
        'cobrar' => (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.empresa_id = ? AND f.anulada = 0 AND $saldo > 0.5", [$pid]),
        'vencido' => (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.empresa_id = ? AND f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento < ?", [$pid, $hoy]),
        'facturado' => (float)val('SELECT COALESCE(SUM(total),0) FROM facturas WHERE empresa_id = ? AND anulada = 0 AND YEAR(fecha_emision) = ?', [$pid, $anioAct]),
        'recaudado' => (float)val('SELECT COALESCE(SUM(pg.valor),0) FROM pagos pg JOIN facturas f ON f.id = pg.factura_id WHERE f.empresa_id = ? AND YEAR(pg.fecha) = ?', [$pid, $anioAct]),
    ];
}

// Edades de cartera
$edades = ['Por vencer' => [null, 0], '1 – 30 días' => [1, 30], '31 – 60 días' => [31, 60], '61 – 90 días' => [61, 90], 'Más de 90 días' => [91, null]];
$pend = all("SELECT f.empresa_id, f.fecha_vencimiento, $saldo AS saldo $base WHERE f.anulada = 0 AND $saldo > 0.5" . ($emp ? ' AND f.empresa_id = ' . $emp : ''));
$ed = array_fill_keys(array_keys($edades), 0.0);
foreach ($pend as $pp) {
    $dias = (int)floor((strtotime($hoy) - strtotime($pp['fecha_vencimiento'])) / 86400);
    foreach ($edades as $k => [$min, $max]) {
        if (($min === null && $dias <= 0) || ($min !== null && $dias >= $min && ($max === null || $dias <= $max))) { $ed[$k] += (float)$pp['saldo']; break; }
    }
}
$totPend = array_sum($ed);
$topClientes = all("SELECT f.cliente_nombre, COUNT(*) n, SUM($saldo) saldo $base WHERE f.anulada = 0 AND $saldo > 0.5" . ($emp ? ' AND f.empresa_id = ' . $emp : '') . " GROUP BY f.cliente_nombre ORDER BY SUM($saldo) DESC LIMIT 6");
$qs = array_filter(['p' => 'cartera', 'empresa' => $emp ?: null, 'estado' => $estado, 'anio' => get('anio'), 'b' => get('b')]);
$anios = range((int)date('Y'), (int)date('Y') - 5);
?>
<div class="tabs">
  <a class="<?= !$emp ? 'on' : '' ?>" href="<?= url('cartera', array_filter(['estado' => $estado])) ?>">Ambas empresas</a>
  <?php foreach ($propias as $pid => $pnom): ?><a class="<?= $emp === $pid ? 'on' : '' ?>" href="<?= url('cartera', array_filter(['empresa' => $pid, 'estado' => $estado])) ?>"><?= e($pnom) ?></a><?php endforeach; ?>
</div>

<div class="kpis">
<?php foreach ($resumen as $pid => $rs): if ($emp && $emp !== $pid) continue; ?>
  <div class="kpi accent">
    <div class="lbl"><i class="bi bi-building"></i><?= e($rs['nombre']) ?> · por cobrar</div>
    <div class="v"><?= money($rs['cobrar']) ?></div>
    <div class="sub"><span class="<?= $rs['vencido'] > 0 ? 'text-danger' : '' ?>">Vencido: <?= money($rs['vencido']) ?></span></div>
  </div>
  <div class="kpi">
    <div class="lbl"><i class="bi bi-cash-stack"></i><?= e($rs['nombre']) ?> · <?= $anioAct ?></div>
    <div class="v" style="font-size:19px"><?= money($rs['recaudado']) ?></div>
    <div class="sub">recaudado de <?= money($rs['facturado']) ?> facturado</div>
  </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="panel h-100 mb-0">
    <div class="panel-h"><h3>Edades de cartera</h3><div class="actions"><span class="muted small">Total pendiente <?= money($totPend) ?></span></div></div>
    <table class="tbl">
      <?php foreach ($ed as $k => $v): $p = $totPend > 0 ? $v / $totPend * 100 : 0; $cls = str_starts_with($k, 'Por') ? '' : (str_starts_with($k, '1 ') ? 'near' : 'over'); ?>
      <tr><td style="width:150px"><?= e($k) ?></td><td><div class="bar <?= $cls ?>"><span style="width:<?= $p ?>%"></span></div></td><td class="r" style="width:60px"><?= num($p, 0) ?>%</td><td class="r" style="width:150px"><?= money($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div></div>
  <div class="col-lg-5"><div class="panel h-100 mb-0">
    <div class="panel-h"><h3>Clientes con mayor saldo</h3></div>
    <table class="tbl">
      <?php foreach ($topClientes as $tc): ?>
      <tr><td><a href="<?= url('cartera', array_filter(['empresa' => $emp ?: null, 'b' => $tc['cliente_nombre']])) ?>"><?= e($tc['cliente_nombre']) ?></a> <small class="muted">(<?= (int)$tc['n'] ?>)</small></td><td class="r"><?= money($tc['saldo']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$topClientes): ?><tr><td class="empty">Sin saldos pendientes.</td></tr><?php endif; ?>
    </table>
  </div></div>
</div>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="cartera"><?php if ($emp): ?><input type="hidden" name="empresa" value="<?= $emp ?>"><?php endif; ?>
    <select class="form-select" name="estado" data-autosubmit><?= options(['pendientes' => 'Con saldo pendiente', 'vencidas' => 'Vencidas', 'pagadas' => 'Pagadas', 'anuladas' => 'Anuladas', 'todas' => 'Todas'], $estado) ?></select>
    <select class="form-select" name="anio" data-autosubmit><option value="">Todo año de emisión</option><?= options(array_combine($anios, $anios), get('anio')) ?></select>
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="N.º factura, cliente, NIT o concepto">
    <div class="actions">
      <a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 1])) ?>"><i class="bi bi-download me-1"></i>Excel</a>
      <a class="btn btn-primary" href="<?= url('factura_form', array_filter(['empresa' => $emp ?: null])) ?>"><i class="bi bi-plus-lg me-1"></i>Registrar factura</a>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Factura</th><?php if (!$emp): ?><th>Empresa</th><?php endif; ?><th>Cliente</th><th>Emisión</th><th>Vence</th><th class="r">Total</th><th class="r">Abonado</th><th class="r">Saldo</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $est = factura_estado($r); $dv = (int)floor((strtotime($hoy) - strtotime($r['fecha_vencimiento'])) / 86400); ?>
      <tr>
        <td class="nowrap"><a class="row-link" href="<?= url('factura', ['id' => $r['id']]) ?>"><?= e($r['numero']) ?></a></td>
        <?php if (!$emp): ?><td class="muted"><?= e($r['empresa']) ?></td><?php endif; ?>
        <td><?= e($r['cliente_nombre']) ?><?= $r['cliente_nit'] ? '<br><small class="muted">NIT ' . e($r['cliente_nit']) . '</small>' : '' ?></td>
        <td class="nowrap"><?= fdate($r['fecha_emision']) ?></td>
        <td class="nowrap"><?= fdate($r['fecha_vencimiento']) ?><?= $est === 'Vencida' ? '<br><small class="text-danger">' . $dv . ' días</small>' : '' ?></td>
        <td class="r"><?= money($r['total']) ?></td>
        <td class="r"><?= money($r['pagado']) ?></td>
        <td class="r fw-semibold"><?= $r['anulada'] ? '—' : money(max(0, (float)$r['saldo'])) ?></td>
        <td><?= badge($est) ?></td>
        <td class="r"><?php if (in_array($est, ['Pendiente', 'Parcial', 'Vencida'], true)): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= url('factura', ['id' => $r['id']]) ?>#pago"><i class="bi bi-cash"></i> Abono</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">No hay facturas con esos filtros.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><td colspan="<?= $emp ? 4 : 5 ?>">Totales del filtro (<?= $total ?> facturas)</td><td class="r"><?= money($sumas['total']) ?></td><td class="r"><?= money($sumas['pagado']) ?></td><td class="r"><?= money($estado === 'anuladas' ? 0 : $sumas['saldo']) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
