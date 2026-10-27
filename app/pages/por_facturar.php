<?php
require_perm('porfacturar', 'ver');
$title = 'Por facturar';
$hoy = today();

// ---- Acciones sobre un pendiente ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    $p = one('SELECT * FROM por_facturar WHERE id = ?', [(int)post('id')]);
    if (!$p) not_found();
    if ($acc === 'facturado') {
        require_perm('porfacturar', 'aprobar');
        update('por_facturar', ['estado' => 'Facturado', 'factura_numero' => mb_substr((string)post('numero', ''), 0, 40) ?: null, 'actualizado' => now()], (int)$p['id']);
        flash('«' . $p['concepto'] . '» marcado como facturado.');
    } elseif ($acc === 'descartar') {
        require_perm('porfacturar', 'aprobar');
        update('por_facturar', ['estado' => 'Descartado', 'actualizado' => now()], (int)$p['id']);
        flash('Pendiente descartado. Puede reabrirlo cuando quiera.');
    } elseif ($acc === 'reabrir') {
        require_perm('porfacturar', 'aprobar');
        update('por_facturar', ['estado' => 'Pendiente', 'actualizado' => now()], (int)$p['id']);
        flash('El pendiente volvió a «Pendiente».');
    } elseif ($acc === 'eliminar') {
        require_perm('porfacturar', 'eliminar');
        q('DELETE FROM por_facturar WHERE id = ?', [(int)$p['id']]);
        flash('Pendiente eliminado.');
    }
    redirect('index.php?' . http_build_query($_GET));
}

// ---- Filtros ----
$propias = empresas_list('propia');
$estado = get('estado', 'pendientes');
$where = ['1=1']; $params = [];
switch ($estado) {
    case 'vencidos': $where[] = "pf.estado = 'Pendiente' AND pf.fecha_prevista < ?"; $params[] = $hoy; break;
    case 'facturados': $where[] = "pf.estado = 'Facturado'"; break;
    case 'descartados': $where[] = "pf.estado = 'Descartado'"; break;
    case 'todos': break;
    default: $estado = 'pendientes'; $where[] = "pf.estado = 'Pendiente'";
}
if (($emp = (int)get('empresa', 0)) && isset($propias[$emp])) { $where[] = 'pf.empresa_id = ?'; $params[] = $emp; }
if ($b = get('b')) { $where[] = '(pf.cliente_nombre LIKE ? OR pf.cliente_nit LIKE ? OR pf.concepto LIKE ? OR pf.referencia LIKE ? OR pf.detalle LIKE ?)'; array_push($params, "%$b%", "%$b%", "%$b%", "%$b%", "%$b%"); }
$w = implode(' AND ', $where);
$sql = "SELECT pf.*, e.nombre AS empresa, f.numero AS factura_num, c.consecutivo AS cot_num
    FROM por_facturar pf LEFT JOIN empresas e ON e.id = pf.empresa_id LEFT JOIN facturas f ON f.id = pf.factura_id LEFT JOIN cotizaciones c ON c.id = pf.cotizacion_id
    WHERE $w ORDER BY (pf.estado <> 'Pendiente'), COALESCE(pf.fecha_prevista, pf.fecha) ASC, pf.id DESC";

// Excel del filtro actual
if (get('exportar')) {
    require_once APP_DIR . '/lib/xlsx.php';
    $out = [];
    foreach (all($sql, $params) as $r) $out[] = [$r['fecha'], $r['cliente_nombre'], $r['cliente_nit'], $r['concepto'], $r['referencia'], $r['empresa'], (float)$r['valor'], $r['fecha_prevista'], $r['estado'], $r['factura_num'] ?: $r['factura_numero'], $r['detalle']];
    xlsx_download('por_facturar_' . date('Ymd_Hi') . '.xlsx', [['name' => 'Por facturar', 'headers' => ['Fecha', 'Cliente', 'NIT', 'Concepto', 'Referencia', 'Empresa que factura', 'Valor', 'Fecha prevista', 'Estado', 'Factura', 'Detalle'],
        'rows' => $out, 'widths' => [12, 30, 14, 40, 18, 24, 14, 14, 12, 14, 40]]]);
}

$per = 30;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM por_facturar pf WHERE $w", $params);
$suma = (float)val("SELECT COALESCE(SUM(pf.valor),0) FROM por_facturar pf WHERE $w", $params);
$rows = all($sql . " LIMIT $per OFFSET " . (($page - 1) * $per), $params);

// ---- Indicadores generales ----
$k = one("SELECT COUNT(*) n, COALESCE(SUM(valor),0) t, COUNT(DISTINCT LOWER(cliente_nombre)) clientes,
    COALESCE(SUM(CASE WHEN fecha_prevista < ? THEN 1 ELSE 0 END),0) venc, COALESCE(SUM(CASE WHEN fecha_prevista < ? THEN valor ELSE 0 END),0) venc_valor FROM por_facturar WHERE estado = 'Pendiente'", [$hoy, $hoy]);
$kMes = one("SELECT COUNT(*) n, COALESCE(SUM(valor),0) t FROM por_facturar WHERE estado = 'Facturado' AND YEAR(actualizado) = ? AND MONTH(actualizado) = ?", [(int)date('Y'), (int)date('n')]);
$porCliente = all("SELECT cliente_nombre, COUNT(*) n, SUM(valor) t FROM por_facturar WHERE estado = 'Pendiente' GROUP BY cliente_nombre ORDER BY t DESC LIMIT 6");
$qs = array_filter(['p' => 'por_facturar', 'estado' => $estado, 'empresa' => get('empresa'), 'b' => get('b')]);
$puedeAprobar = can('porfacturar', 'aprobar');
?>
<div class="kpis">
  <a class="kpi accent text-decoration-none text-reset" href="<?= url('por_facturar') ?>"><div class="lbl"><i class="bi bi-receipt-cutoff"></i>Pendiente por facturar</div><div class="v"><?= money($k['t']) ?></div><div class="sub"><?= (int)$k['n'] ?> pendiente(s) · <?= (int)$k['clientes'] ?> cliente(s)</div></a>
  <a class="kpi <?= $k['venc'] ? 'danger bad' : '' ?> text-decoration-none text-reset" href="<?= url('por_facturar', ['estado' => 'vencidos']) ?>"><div class="lbl"><i class="bi bi-exclamation-triangle"></i>Con fecha vencida</div><div class="v"><?= (int)$k['venc'] ?></div><div class="sub"><?= money($k['venc_valor']) ?> · debió facturarse ya</div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('por_facturar', ['estado' => 'facturados']) ?>"><div class="lbl"><i class="bi bi-check2-circle"></i>Facturado en <?= MESES[(int)date('n')] ?></div><div class="v"><?= money($kMes['t']) ?></div><div class="sub"><?= (int)$kMes['n'] ?> pendiente(s) facturado(s)</div></a>
</div>

<div class="row g-3">
  <div class="col-xl-9">
    <div class="panel">
      <form class="toolbar" method="get">
        <input type="hidden" name="p" value="por_facturar">
        <select class="form-select" name="estado" data-autosubmit><?= options(['pendientes' => 'Pendientes', 'vencidos' => 'Con fecha vencida', 'facturados' => 'Facturados', 'descartados' => 'Descartados', 'todos' => 'Todos'], $estado) ?></select>
        <?php if (count($propias) > 1): ?><select class="form-select" name="empresa" data-autosubmit><option value="">Toda empresa</option><?= options($propias, get('empresa')) ?></select><?php endif; ?>
        <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Cliente, NIT, concepto o referencia">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
        <div class="actions">
          <a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 1])) ?>"><i class="bi bi-download me-1"></i>Excel</a>
          <?php if (can('porfacturar', 'crear')): ?><a class="btn btn-primary" href="<?= url('por_facturar_form') ?>"><i class="bi bi-plus-lg me-1"></i>Agregar pendiente</a><?php endif; ?>
        </div>
      </form>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Concepto</th><th class="r">Valor</th><th>Facturar el</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $venc = $r['estado'] === 'Pendiente' && $r['fecha_prevista'] && $r['fecha_prevista'] < $hoy; ?>
          <tr class="<?= $r['estado'] === 'Descartado' ? 'muted' : '' ?>">
            <td class="nowrap muted"><?= fdate($r['fecha']) ?></td>
            <td><?= can('porfacturar', 'editar') ? '<a class="row-link" href="' . url('por_facturar_form', ['id' => $r['id']]) . '">' . e($r['cliente_nombre']) . '</a>' : e($r['cliente_nombre']) ?><?= $r['cliente_nit'] ? '<br><small class="muted">' . e($r['cliente_nit']) . '</small>' : '' ?></td>
            <td><?= e($r['concepto']) ?>
              <?php if ($r['referencia'] || $r['cot_num'] || $r['empresa']): ?><br><small class="muted"><?= e(implode(' · ', array_filter([$r['referencia'], $r['empresa']]))) ?><?= $r['cot_num'] && !str_contains((string)$r['referencia'], (string)$r['cot_num']) ? ' · Cotización ' . (int)$r['cot_num'] : '' ?></small><?php endif; ?></td>
            <td class="r nowrap fw-semibold"><?= money($r['valor']) ?></td>
            <td class="nowrap <?= $venc ? 'text-danger' : 'muted' ?>"><?= $r['fecha_prevista'] ? fdate($r['fecha_prevista']) . ($venc ? '<br><small>vencido hace ' . (int)floor((strtotime($hoy) - strtotime($r['fecha_prevista'])) / 86400) . ' d</small>' : '') : '—' ?></td>
            <td><?= badge($r['estado']) ?><?php if ($r['estado'] === 'Facturado' && ($r['factura_id'] || $r['factura_numero'])): ?><br><small><?= $r['factura_id'] ? '<a href="' . url('factura', ['id' => $r['factura_id']]) . '">' . e($r['factura_num']) . '</a>' : e($r['factura_numero']) ?></small><?php endif; ?></td>
            <td class="r nowrap">
              <?php if ($r['estado'] === 'Pendiente'): ?>
                <?php if ($puedeAprobar && can('cartera', 'crear')): ?><a class="btn btn-sm btn-primary" href="<?= url('factura_form', ['pendiente' => $r['id']]) ?>" title="Crear la factura con estos datos"><i class="bi bi-receipt me-1"></i>Facturar</a><?php endif; ?>
                <?php if ($puedeAprobar): ?>
                <form method="post" class="d-inline" onsubmit="var n = prompt('Número de la factura (opcional):', ''); if (n === null) return false; this.numero.value = n;"><?= csrf_field() ?><input type="hidden" name="accion" value="facturado"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="numero"><button class="btn btn-sm btn-outline-success" title="Ya se facturó por fuera de la aplicación"><i class="bi bi-check2"></i></button></form>
                <form method="post" class="d-inline" data-confirm="¿Descartar este pendiente? No se factura y sale de la lista."><?= csrf_field() ?><input type="hidden" name="accion" value="descartar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Descartar"><i class="bi bi-x-lg"></i></button></form>
                <?php endif; ?>
              <?php elseif ($puedeAprobar): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="reabrir"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Volver a pendiente"><i class="bi bi-arrow-counterclockwise"></i></button></form>
              <?php endif; ?>
              <?php if (can('porfacturar', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar este pendiente?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">No hay pendientes con esos filtros.<?= can('porfacturar', 'crear') ? ' <a href="' . url('por_facturar_form') . '">Agregar el primero</a>' : '' ?></td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr><td colspan="3" class="r muted">Total del filtro (<?= $total ?>)</td><td class="r fw-bold"><?= money($suma) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
      </table></div>
      <?= pagination($total, $per, $page, $qs) ?>
    </div>
  </div>
  <div class="col-xl-3">
    <div class="panel">
      <div class="panel-h"><h3>Pendiente por cliente</h3></div>
      <table class="tbl">
        <?php foreach ($porCliente as $pc): ?>
        <tr><td><a href="<?= url('por_facturar', ['b' => $pc['cliente_nombre']]) ?>"><?= e($pc['cliente_nombre']) ?></a> <small class="muted">(<?= (int)$pc['n'] ?>)</small></td><td class="r nowrap"><?= money($pc['t']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$porCliente): ?><tr><td class="empty">Nada pendiente.</td></tr><?php endif; ?>
      </table>
    </div>
  </div>
</div>
