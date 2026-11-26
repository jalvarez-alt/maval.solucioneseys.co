<?php
require_once APP_DIR . '/ordenes.php';
$title = 'Órdenes de servicio';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'eliminar') {
    require_perm('ordenes', 'eliminar');
    $oid = (int)post('id');
    if ($x = one('SELECT consecutivo FROM ordenes WHERE id = ?', [$oid])) { ord_eliminar($oid); flash('Orden ' . ord_codigo($x) . ' eliminada.'); }
    redirect('index.php?' . http_build_query($_GET));
}

$where = ['1=1']; $params = [];
$estado = get('estado', ''); $tipo = get('tipo', ''); $tec = (int)get('tecnico', 0);
if (in_array($estado, ORD_ESTADOS, true)) { $where[] = 'o.estado = ?'; $params[] = $estado; }
if (in_array($tipo, ORD_TIPOS, true)) { $where[] = 'o.tipo = ?'; $params[] = $tipo; }
if ($tec) { $where[] = 'o.tecnico_id = ?'; $params[] = $tec; }
if ($b = get('b')) {
    $where[] = '(o.cliente_nombre LIKE ? OR o.cliente_nit LIKE ? OR o.sede LIKE ? OR o.tecnico_nombre LIKE ? OR o.referencia LIKE ? OR o.descripcion LIKE ? OR CAST(o.consecutivo AS CHAR) = ?)';
    array_push($params, "%$b%", "%$b%", "%$b%", "%$b%", "%$b%", "%$b%", ltrim(preg_replace('/^OS-?/i', '', $b), '0'));
}
$w = implode(' AND ', $where);
$per = 30; $page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM ordenes o WHERE $w", $params);
$rows = all("SELECT o.* FROM ordenes o WHERE $w ORDER BY (o.estado IN ('Completada','Cancelada')), o.consecutivo DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$kAb = (int)val("SELECT COUNT(*) FROM ordenes WHERE estado IN ('Pendiente','Programada')");
$kCurso = (int)val("SELECT COUNT(*) FROM ordenes WHERE estado = 'En curso'");
$kAtr = (int)val("SELECT COUNT(*) FROM ordenes WHERE estado IN ('Pendiente','Programada','En curso') AND fecha_programada < ?", [today()]);
$kMes = (int)val("SELECT COUNT(*) FROM ordenes WHERE estado = 'Completada' AND YEAR(fecha_fin) = ? AND MONTH(fecha_fin) = ?", [(int)date('Y'), (int)date('n')]);
$tecnicos = all('SELECT DISTINCT tecnico_id AS id, tecnico_nombre AS nombre FROM ordenes WHERE tecnico_id IS NOT NULL ORDER BY tecnico_nombre');
$qs = array_filter(['p' => 'ordenes', 'estado' => $estado, 'tipo' => $tipo, 'tecnico' => $tec ?: '', 'b' => get('b')]);
?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-hourglass-split"></i>Por atender</div><div class="v"><?= $kAb ?></div><div class="sub">Pendientes y programadas</div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-play-circle"></i>En curso</div><div class="v"><?= $kCurso ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-exclamation-triangle"></i>Atrasadas</div><div class="v" <?= $kAtr ? 'style="color:#b91c1c"' : '' ?>><?= $kAtr ?></div><div class="sub">Con fecha programada vencida</div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Completadas en <?= MESES[(int)date('n')] ?></div><div class="v"><?= $kMes ?></div></div>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="ordenes">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Número, cliente, NIT, sede, técnico o referencia">
    <select class="form-select" name="estado" data-autosubmit><option value="">Todo estado</option><?= options(ORD_ESTADOS, $estado, false) ?></select>
    <select class="form-select" name="tipo" data-autosubmit><option value="">Todo servicio</option><?= options(ORD_TIPOS, $tipo, false) ?></select>
    <select class="form-select" name="tecnico" data-autosubmit><option value="">Todo técnico</option><?= options(array_column($tecnicos, 'nombre', 'id'), $tec) ?></select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if (get('b') !== null || $estado || $tipo || $tec): ?><a class="btn btn-outline-secondary" href="<?= url('ordenes') ?>">Limpiar</a><?php endif; ?>
    <div class="actions"><?php if (can('ordenes', 'crear')): ?><a class="btn btn-primary" href="<?= url('orden_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nueva orden</a><?php endif; ?></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>No.</th><th>Programada</th><th>Cliente</th><th>Servicio</th><th>Técnico</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $atras = in_array($r['estado'], ORD_ABIERTAS, true) && $r['fecha_programada'] && $r['fecha_programada'] < today(); ?>
      <tr>
        <td class="nowrap"><a class="row-link" href="<?= url('orden', ['id' => $r['id']]) ?>"><?= e(ord_codigo($r)) ?></a></td>
        <td class="nowrap <?= $atras ? 'text-danger fw-semibold' : 'muted' ?>"><?= $r['fecha_programada'] ? fdate($r['fecha_programada']) . ($r['hora_programada'] ? '<br><small>' . e($r['hora_programada']) . '</small>' : '') : '—' ?></td>
        <td><a class="row-link fw-normal" href="<?= url('orden', ['id' => $r['id']]) ?>"><?= e($r['cliente_nombre']) ?></a><?= $r['sede'] ? '<br><small class="muted">' . e($r['sede']) . '</small>' : '' ?></td>
        <td class="small"><?= e($r['tipo']) ?><?= $r['prioridad'] !== 'Normal' ? ' ' . ord_prioridad_badge($r['prioridad']) : '' ?></td>
        <td class="small muted"><?= e($r['tecnico_nombre'] ?: 'Sin asignar') ?></td>
        <td><?= ord_badge($r['estado']) ?></td>
        <td class="r nowrap">
          <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('orden', ['id' => $r['id'], 'pdf' => 'ver']) ?>" title="Ver PDF"><i class="bi bi-file-earmark-pdf"></i></a>
          <?php if (can('ordenes', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar la orden <?= e(ord_codigo($r)) ?> y sus fotos?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No hay órdenes con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
