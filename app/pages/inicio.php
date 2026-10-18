<?php
$u = user();
$title = 'Hola, ' . explode(' ', $u['nombre'])[0];
[$ts, $tp] = ticket_scope('t');
[$es, $ep] = equipo_scope('eq');
$tAct = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado IN ('Abierto','En proceso','En espera')", $tp);
$tCrit = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado IN ('Abierto','En proceso','En espera') AND t.prioridad IN ('Alta','Crítica')", $tp);
$tRes = (int)val("SELECT COUNT(*) FROM tickets t WHERE $ts AND t.estado = 'Resuelto'", $tp);
$nEq = (int)val("SELECT COUNT(*) FROM equipos eq WHERE $es AND eq.estado <> 'Dado de baja'", $ep);
$recientes = all("SELECT t.id, t.codigo, t.asunto, t.estado, t.prioridad, t.actualizado, t.creado, s.nombre AS solicitante, e.nombre AS empresa
    FROM tickets t LEFT JOIN usuarios s ON s.id = t.solicitante_id LEFT JOIN empresas e ON e.id = t.empresa_id
    WHERE $ts AND t.estado <> 'Cerrado' ORDER BY COALESCE(t.actualizado, t.creado) DESC LIMIT 8", $tp);

if (is_admin()) {
    $anio = (int)date('Y'); $mes = (int)date('n');
    $gMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM gastos WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?', [$anio, $mes]);
    $pMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ? AND mes = ?', [$anio, $mes]);
    $gAcum = (float)val('SELECT COALESCE(SUM(valor),0) FROM gastos WHERE YEAR(fecha) = ?', [$anio]);
    $pAcum = (float)val('SELECT COALESCE(SUM(valor),0) FROM presupuestos WHERE anio = ? AND mes <= ?', [$anio, $mes]);
    $base = 'FROM facturas f LEFT JOIN (SELECT factura_id, SUM(valor) pagado FROM pagos GROUP BY factura_id) p ON p.factura_id = f.id';
    $saldo = '(f.total - COALESCE(p.pagado,0))';
    $cobrar = (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.anulada = 0 AND $saldo > 0.5");
    $vencido = (float)val("SELECT COALESCE(SUM($saldo),0) $base WHERE f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento < ?", [today()]);
    $recMes = (float)val('SELECT COALESCE(SUM(valor),0) FROM pagos WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?', [$anio, $mes]);
    $porVencer = all("SELECT f.id, f.numero, f.cliente_nombre, f.fecha_vencimiento, e.nombre AS empresa, $saldo AS saldo $base JOIN empresas e ON e.id = f.empresa_id
        WHERE f.anulada = 0 AND $saldo > 0.5 AND f.fecha_vencimiento <= ? ORDER BY f.fecha_vencimiento LIMIT 8", [date('Y-m-d', strtotime('+7 days'))]);
    $garantias = all("SELECT id, codigo, tipo, marca, modelo, garantia_hasta FROM equipos WHERE estado <> 'Dado de baja' AND garantia_hasta BETWEEN ? AND ? ORDER BY garantia_hasta LIMIT 6", [today(), date('Y-m-d', strtotime('+45 days'))]);
    $sinAsignar = (int)val("SELECT COUNT(*) FROM tickets WHERE asignado_id IS NULL AND estado IN ('Abierto','En proceso','En espera')");
}
?>
<?php if (is_admin()): ?>
<div class="kpis">
  <a class="kpi accent text-decoration-none text-reset" href="<?= url('tickets') ?>"><div class="lbl"><i class="bi bi-life-preserver"></i>Tickets sin cerrar</div><div class="v"><?= $tAct ?></div><div class="sub"><?= $tCrit ?> alta/crítica · <?= $sinAsignar ?> sin asignar</div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('inventario') ?>"><div class="lbl"><i class="bi bi-pc-display"></i>Equipos en inventario</div><div class="v"><?= $nEq ?></div></a>
  <a class="kpi <?= $pMes > 0 && $gMes > $pMes ? 'danger' : '' ?> text-decoration-none text-reset" href="<?= url('gastos') ?>"><div class="lbl"><i class="bi bi-wallet2"></i>Gastos <?= MESES[$mes] ?></div><div class="v"><?= money($gMes) ?></div><div class="sub">Presupuesto <?= money($pMes) ?><?= $pMes > 0 ? ' · ' . num($gMes / $pMes * 100, 0) . '%' : '' ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('estadisticas') ?>"><div class="lbl"><i class="bi bi-graph-up"></i>Gastos acumulados <?= $anio ?></div><div class="v"><?= money($gAcum) ?></div><div class="sub">Presupuesto a la fecha <?= money($pAcum) ?></div></a>
  <a class="kpi <?= $vencido > 0 ? 'warn' : '' ?> text-decoration-none text-reset" href="<?= url('cartera') ?>"><div class="lbl"><i class="bi bi-receipt"></i>Cartera por cobrar</div><div class="v"><?= money($cobrar) ?></div><div class="sub <?= $vencido > 0 ? 'text-danger' : '' ?>">Vencida <?= money($vencido) ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('ingresos') ?>"><div class="lbl"><i class="bi bi-cash-coin"></i>Recaudado en <?= MESES[$mes] ?></div><div class="v"><?= money($recMes) ?></div></a>
</div>
<?php else: ?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-life-preserver"></i><?= is_role('empresa') ? 'Tickets abiertos de la empresa' : 'Mis tickets abiertos' ?></div><div class="v"><?= $tAct ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Resueltos por confirmar</div><div class="v"><?= $tRes ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-pc-display"></i><?= is_role('empresa') ? 'Equipos de la empresa' : 'Mis equipos' ?></div><div class="v"><?= $nEq ?></div></div>
  <a class="kpi text-decoration-none" href="<?= url('ticket_form') ?>" style="background:var(--accent);color:#fff;border-color:var(--accent)"><div class="lbl" style="color:#d6f3ef"><i class="bi bi-plus-circle"></i>¿Necesita ayuda?</div><div class="v" style="font-size:19px">Crear un ticket</div></a>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="<?= is_admin() ? 'col-xl-7' : 'col-12' ?>">
    <div class="panel h-100">
      <div class="panel-h"><h3>Tickets con actividad reciente</h3><div class="actions"><a class="small" href="<?= url('tickets') ?>">Ver todos</a></div></div>
      <div class="tbl-wrap"><table class="tbl">
        <tbody>
        <?php foreach ($recientes as $t): ?>
          <tr>
            <td class="nowrap"><a class="row-link" href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['codigo']) ?></a></td>
            <td><?= e($t['asunto']) ?><?php if (!is_role('usuario')): ?><br><small class="muted"><?= e($t['solicitante'] ?? '') ?><?= is_admin() && $t['empresa'] ? ' · ' . e($t['empresa']) : '' ?></small><?php endif; ?></td>
            <td><?= badge($t['prioridad']) ?></td><td><?= badge($t['estado']) ?></td>
            <td class="nowrap muted small"><?= fdate($t['actualizado'] ?: $t['creado'], true) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recientes): ?><tr><td class="empty">No hay tickets pendientes. <a href="<?= url('ticket_form') ?>">Crear uno</a></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php if (is_admin()): ?>
  <div class="col-xl-5">
    <div class="panel">
      <div class="panel-h"><h3>Facturas vencidas y por vencer (7 días)</h3></div>
      <table class="tbl">
        <?php foreach ($porVencer as $f): $venc = $f['fecha_vencimiento'] < today(); ?>
          <tr><td><a href="<?= url('factura', ['id' => $f['id']]) ?>"><?= e($f['numero']) ?></a><br><small class="muted"><?= e($f['empresa']) ?></small></td><td><?= e($f['cliente_nombre']) ?></td>
            <td class="nowrap <?= $venc ? 'text-danger' : '' ?>"><?= fdate($f['fecha_vencimiento']) ?></td><td class="r"><?= money($f['saldo']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$porVencer): ?><tr><td class="empty">Nada pendiente esta semana.</td></tr><?php endif; ?>
      </table>
    </div>
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
