<?php
$u = user();
$title = 'Hola, ' . explode(' ', $u['nombre'])[0];
[$ts, $tp] = ticket_scope('t');
[$es, $ep] = equipo_scope('eq');
$todos = alcance() === 'todos';
$vTickets = can('tickets');
$vEquipos = can('computo') || can('inventario');
$vMant = can('mantenimiento');
$hoy = today();

if ($vTickets) {
    $tAct = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado IN ('Abierto','En proceso','En espera')", $tp);
    $tCrit = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado IN ('Abierto','En proceso','En espera') AND t.prioridad IN ('Alta','Crítica')", $tp);
    $tRes = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado = 'Resuelto'", $tp);
    $sinAsignar = $todos ? (int)val("SELECT COUNT(*) FROM tickets WHERE asignado_id IS NULL AND estado IN ('Abierto','En proceso','En espera')") : 0;
    $recientes = all("SELECT t.id, t.codigo, t.asunto, t.estado, t.prioridad, t.actualizado, t.creado, s.nombre AS solicitante, e.nombre AS empresa
        FROM tickets t LEFT JOIN usuarios s ON s.id = t.solicitante_id LEFT JOIN empresas e ON e.id = t.empresa_id
        WHERE $ts AND t.estado <> 'Cerrado' ORDER BY COALESCE(t.actualizado, t.creado) DESC LIMIT 8", $tp);
}
if ($vEquipos) $nEq = (int)val("SELECT COUNT(*) FROM equipos eq WHERE $es AND eq.estado <> 'Dado de baja'", $ep);
if ($vMant) {
    $mb = "FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id WHERE $es AND m.estado = 'Programado' AND eq.estado <> 'Dado de baja'";
    $mVenc = (int)val("SELECT COUNT(*) $mb AND m.fecha_programada < ?", array_merge($ep, [$hoy]));
    $mSem = (int)val("SELECT COUNT(*) $mb AND m.fecha_programada BETWEEN ? AND ?", array_merge($ep, [$hoy, date('Y-m-d', strtotime('+7 days'))]));
    $proxMant = all("SELECT m.id, m.fecha_programada, eq.codigo, eq.tipo, eq.marca, eq.modelo $mb AND m.fecha_programada <= ? ORDER BY m.fecha_programada LIMIT 8", array_merge($ep, [date('Y-m-d', strtotime('+15 days'))]));
}
$anio = (int)date('Y'); $mes = (int)date('n');
if (can('gastos')) {
    $gMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM gastos WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?', [$anio, $mes]);
    $pMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ? AND mes = ?', [$anio, $mes]);
}
if (can('estadisticas')) {
    $gAcum = (float)val('SELECT COALESCE(SUM(valor),0) FROM gastos WHERE YEAR(fecha) = ?', [$anio]);
    $pAcum = (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ? AND mes <= ?', [$anio, $mes]);
}
if (can('cartera') || can('ingresos')) {
    $base = 'FROM facturas f LEFT JOIN (SELECT factura_id, SUM(valor) pagado FROM pagos GROUP BY factura_id) p ON p.factura_id = f.id';
    $saldo = '(f.total - COALESCE(p.pagado,0))';
}
if (can('cartera')) {
    $cobrar = (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.anulada = 0 AND $saldo > 0.5");
    $vencido = (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento < ?", [$hoy]);
    $porVencer = all("SELECT f.id, f.numero, f.cliente_nombre, f.fecha_vencimiento, e.nombre AS empresa, $saldo AS saldo $base JOIN empresas e ON e.id = f.empresa_id
        WHERE f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento <= ? ORDER BY f.fecha_vencimiento LIMIT 8", [date('Y-m-d', strtotime('+7 days'))]);
}
if (can('porfacturar')) $pf = one("SELECT COUNT(*) n, COALESCE(SUM(valor),0) t, COALESCE(SUM(CASE WHEN fecha_prevista < ? THEN 1 ELSE 0 END),0) venc FROM por_facturar WHERE estado = 'Pendiente'", [$hoy]);
if (can('ingresos')) $recMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM pagos WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?', [$anio, $mes]);
$garantias = $vEquipos && $todos ? all("SELECT id, codigo, tipo, marca, modelo, garantia_hasta FROM equipos WHERE estado <> 'Dado de baja' AND garantia_hasta BETWEEN ? AND ? ORDER BY garantia_hasta LIMIT 6", [$hoy, date('Y-m-d', strtotime('+45 days'))]) : [];
$colDer = (can('cartera') || $garantias || $vMant);
?>
<div class="kpis">
  <?php if ($vTickets && $todos): ?>
  <a class="kpi accent text-decoration-none text-reset" href="<?= url('tickets') ?>"><div class="lbl"><i class="bi bi-life-preserver"></i>Tickets sin cerrar</div><div class="v"><?= $tAct ?></div><div class="sub"><?= $tCrit ?> alta/crítica · <?= $sinAsignar ?> sin asignar</div></a>
  <?php elseif ($vTickets): ?>
  <div class="kpi accent"><div class="lbl"><i class="bi bi-life-preserver"></i><?= alcance() === 'empresa' ? 'Tickets abiertos de la empresa' : 'Mis tickets abiertos' ?></div><div class="v"><?= $tAct ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Resueltos por confirmar</div><div class="v"><?= $tRes ?></div></div>
  <?php endif; ?>
  <?php if ($vEquipos): ?>
  <a class="kpi text-decoration-none text-reset" href="<?= url(can('computo') ? 'computo' : 'inventario') ?>"><div class="lbl"><i class="bi bi-pc-display"></i><?= $todos ? 'Equipos en inventario' : (alcance() === 'empresa' ? 'Equipos de la empresa' : 'Mis equipos') ?></div><div class="v"><?= $nEq ?></div></a>
  <?php endif; ?>
  <?php if ($vMant): ?>
  <a class="kpi <?= $mVenc ? 'danger bad' : '' ?> text-decoration-none text-reset" href="<?= url('mantenimiento', ['estado' => 'vencidos']) ?>"><div class="lbl"><i class="bi bi-calendar-check"></i>Mantenimientos vencidos</div><div class="v"><?= $mVenc ?></div><div class="sub"><?= $mSem ?> en los próximos 7 días</div></a>
  <?php endif; ?>
  <?php if (can('gastos')): ?>
  <a class="kpi <?= $pMes > 0 && $gMes > $pMes ? 'danger' : '' ?> text-decoration-none text-reset" href="<?= url('gastos') ?>"><div class="lbl"><i class="bi bi-wallet2"></i>Gastos <?= MESES[$mes] ?></div><div class="v"><?= money($gMes) ?></div><div class="sub">Presupuesto <?= money($pMes) ?><?= $pMes > 0 ? ' · ' . num($gMes / $pMes * 100, 0) . '%' : '' ?></div></a>
  <?php endif; ?>
  <?php if (can('estadisticas')): ?>
  <a class="kpi text-decoration-none text-reset" href="<?= url('estadisticas') ?>"><div class="lbl"><i class="bi bi-graph-up"></i>Gastos acumulados <?= $anio ?></div><div class="v"><?= money($gAcum) ?></div><div class="sub">Presupuesto a la fecha <?= money($pAcum) ?></div></a>
  <?php endif; ?>
  <?php if (can('cartera')): ?>
  <a class="kpi <?= $vencido > 0 ? 'warn' : '' ?> text-decoration-none text-reset" href="<?= url('cartera') ?>"><div class="lbl"><i class="bi bi-receipt"></i>Cartera por cobrar</div><div class="v"><?= money($cobrar) ?></div><div class="sub <?= $vencido > 0 ? 'text-danger' : '' ?>">Vencida <?= money($vencido) ?></div></a>
  <?php endif; ?>
  <?php if (can('porfacturar')): ?>
  <a class="kpi <?= $pf['venc'] ? 'warn' : '' ?> text-decoration-none text-reset" href="<?= url('por_facturar') ?>"><div class="lbl"><i class="bi bi-receipt-cutoff"></i>Por facturar</div><div class="v"><?= money($pf['t']) ?></div><div class="sub <?= $pf['venc'] ? 'text-danger' : '' ?>"><?= (int)$pf['n'] ?> pendiente(s)<?= $pf['venc'] ? ' · ' . (int)$pf['venc'] . ' con fecha vencida' : '' ?></div></a>
  <?php endif; ?>
  <?php if (can('ingresos')): ?>
  <a class="kpi text-decoration-none text-reset" href="<?= url('ingresos') ?>"><div class="lbl"><i class="bi bi-cash-coin"></i>Recaudado en <?= MESES[$mes] ?></div><div class="v"><?= money($recMes) ?></div></a>
  <?php endif; ?>
  <?php if ($vTickets && !$todos && can('tickets', 'crear')): ?>
  <a class="kpi text-decoration-none" href="<?= url('ticket_form') ?>" style="background:var(--accent);color:#fff;border-color:var(--accent)"><div class="lbl" style="color:#d6f3ef"><i class="bi bi-plus-circle"></i>¿Necesita ayuda?</div><div class="v" style="font-size:19px">Crear un ticket</div></a>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php if ($vTickets): ?>
  <div class="<?= $colDer ? 'col-xl-7' : 'col-12' ?>">
    <div class="panel h-100">
      <div class="panel-h"><h3>Tickets con actividad reciente</h3><div class="actions"><a class="small" href="<?= url('tickets') ?>">Ver todos</a></div></div>
      <div class="tbl-wrap"><table class="tbl">
        <tbody>
        <?php foreach ($recientes as $t): ?>
          <tr>
            <td class="nowrap"><a class="row-link" href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['codigo']) ?></a></td>
            <td><?= e($t['asunto']) ?><?php if (alcance() !== 'propios'): ?><br><small class="muted"><?= e($t['solicitante'] ?? '') ?><?= $todos && $t['empresa'] ? ' · ' . e($t['empresa']) : '' ?></small><?php endif; ?></td>
            <td><?= badge($t['prioridad']) ?></td><td><?= badge($t['estado']) ?></td>
            <td class="nowrap muted small"><?= fdate($t['actualizado'] ?: $t['creado'], true) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recientes): ?><tr><td class="empty">No hay tickets pendientes.<?= can('tickets', 'crear') ? ' <a href="' . url('ticket_form') . '">Crear uno</a>' : '' ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($colDer): ?>
  <div class="<?= $vTickets ? 'col-xl-5' : 'col-12' ?>">
    <?php if ($vMant): ?>
    <div class="panel">
      <div class="panel-h"><h3>Mantenimientos próximos y vencidos</h3><div class="actions"><a class="small" href="<?= url('mantenimiento') ?>">Planeador</a></div></div>
      <table class="tbl">
        <?php foreach ($proxMant as $pm): $venc = $pm['fecha_programada'] < $hoy; ?>
          <tr><td><a href="<?= url('mantenimiento_form', ['id' => $pm['id']]) ?>"><?= e($pm['codigo']) ?></a></td><td><?= e($pm['tipo'] . ' ' . trim(($pm['marca'] ?? '') . ' ' . ($pm['modelo'] ?? ''))) ?></td>
            <td class="nowrap r <?= $venc ? 'text-danger' : '' ?>"><?= fdate($pm['fecha_programada']) ?><br><small><?= e(mant_texto_dias(dias_hasta($pm['fecha_programada']))) ?></small></td></tr>
        <?php endforeach; ?>
        <?php if (!$proxMant): ?><tr><td class="empty">Nada programado en los próximos 15 días.</td></tr><?php endif; ?>
      </table>
    </div>
    <?php endif; ?>
    <?php if (can('cartera')): ?>
    <div class="panel">
      <div class="panel-h"><h3>Facturas vencidas y por vencer (7 días)</h3></div>
      <table class="tbl">
        <?php foreach ($porVencer as $f): $venc = $f['fecha_vencimiento'] < $hoy; ?>
          <tr><td><a href="<?= url('factura', ['id' => $f['id']]) ?>"><?= e($f['numero']) ?></a><br><small class="muted"><?= e($f['empresa']) ?></small></td><td><?= e($f['cliente_nombre']) ?></td>
            <td class="nowrap <?= $venc ? 'text-danger' : '' ?>"><?= fdate($f['fecha_vencimiento']) ?></td><td class="r"><?= money($f['saldo']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$porVencer): ?><tr><td class="empty">Nada pendiente esta semana.</td></tr><?php endif; ?>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($garantias): ?>
    <div class="panel">
      <div class="panel-h"><h3>Garantías por vencer</h3></div>
      <table class="tbl">
        <?php foreach ($garantias as $g): ?>
          <tr><td><a href="<?= url('equipo', ['id' => $g['id']]) ?>"><?= e($g['codigo']) ?></a></td><td><?= e($g['tipo'] . ' ' . trim(($g['marca'] ?? '') . ' ' . ($g['modelo'] ?? ''))) ?></td><td class="nowrap r"><?= fdate($g['garantia_hasta']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if (!$vTickets && !$vEquipos && !$vMant && !can('gastos') && !can('estadisticas') && !can('cartera') && !can('ingresos')): ?>
  <div class="empty-state"><i class="bi bi-person-lock"></i><h2>Su rol no tiene módulos habilitados</h2><p class="muted">Solicite al administrador que le asigne los permisos que necesita.</p></div>
<?php endif; ?>
