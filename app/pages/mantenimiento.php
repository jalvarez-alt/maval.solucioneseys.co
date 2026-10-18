<?php
require_once APP_DIR . '/mantenimiento.php';
$title = 'Planeador de mantenimiento';
[$es, $ep] = equipo_scope('eq');
$volver = 'index.php?' . http_build_query($_GET);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'recordatorios') {
        require_perm('mantenimiento', 'administrar');
        if (!correo_activo()) flash('Primero active y configure el correo en «Correo y notificaciones».', 'warning');
        else {
            $r = enviar_recordatorios_mantenimiento(true);
            flash("Recordatorios enviados: {$r['proximos']} mantenimiento(s) próximo(s) y {$r['pendientes']} pendiente(s), en {$r['correos']} correo(s).", $r['correos'] ? 'success' : 'info');
        }
        redirect($volver);
    }
    $m = mant_obtener((int)post('id'));
    if (!$m) not_found();
    if ($acc === 'realizar' && $m['estado'] === 'Programado') {
        require_perm('mantenimiento', 'aprobar');
        $nid = mant_realizar($m, today(), null);
        if ($nid) notificar_programacion([$nid]);
        flash('Mantenimiento de ' . $m['codigo'] . ' registrado como realizado.' . ($nid ? ' Se programó el siguiente.' : ''));
    } elseif ($acc === 'cancelar' && $m['estado'] === 'Programado') {
        require_perm('mantenimiento', 'aprobar');
        update('mantenimientos', ['estado' => 'Cancelado', 'actualizado' => now()], (int)$m['id']);
        flash('Mantenimiento cancelado.');
    } elseif ($acc === 'eliminar') {
        require_perm('mantenimiento', 'eliminar');
        q('DELETE FROM mantenimientos WHERE id = ?', [(int)$m['id']]);
        flash('Programación eliminada.');
    }
    redirect($volver);
}

// ---- Indicadores (no dependen de los filtros) ----
$base = 'FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id';
$vivos = "m.estado = 'Programado' AND eq.estado <> 'Dado de baja'";
$hoy = today();
$cnt = function (string $cond, array $p = []) use ($base, $es, $ep): int { return (int)val("SELECT COUNT(*) $base WHERE $es AND $cond", array_merge($ep, $p)); };
$kVenc = $cnt("$vivos AND m.fecha_programada < ?", [$hoy]);
$kSem = $cnt("$vivos AND m.fecha_programada BETWEEN ? AND ?", [$hoy, date('Y-m-d', strtotime('+7 days'))]);
$kMes = $cnt("$vivos AND m.fecha_programada BETWEEN ? AND ?", [$hoy, date('Y-m-d', strtotime('+30 days'))]);
$kProg = $cnt($vivos);
$kHechos = $cnt("m.estado = 'Realizado' AND YEAR(m.fecha_realizada) = ?", [(int)date('Y')]);

// ---- Filtros ----
$estado = get('estado', 'pendientes');
$where = [$es]; $params = $ep;
switch ($estado) {
    case 'vencidos': $where[] = "$vivos AND m.fecha_programada < ?"; $params[] = $hoy; break;
    case 'proximos': $where[] = "$vivos AND m.fecha_programada BETWEEN ? AND ?"; array_push($params, $hoy, date('Y-m-d', strtotime('+30 days'))); break;
    case 'realizados': $where[] = "m.estado = 'Realizado'"; break;
    case 'cancelados': $where[] = "m.estado = 'Cancelado'"; break;
    case 'todos': break;
    default: $estado = 'pendientes'; $where[] = $vivos;
}
if (($tipo = get('tipo')) && in_array($tipo, EQUIPO_TIPOS, true)) { $where[] = 'eq.tipo = ?'; $params[] = $tipo; }
if (alcance() === 'todos' && ($emp = (int)get('empresa', 0))) { $where[] = 'eq.empresa_id = ?'; $params[] = $emp; }
if (($tec = (int)get('tecnico', 0))) { $where[] = 'm.tecnico_id = ?'; $params[] = $tec; }
if (preg_match('/^(\d{4})-(\d{2})$/', (string)get('mes', ''), $mm)) {
    $ini = "{$mm[1]}-{$mm[2]}-01";
    $where[] = 'COALESCE(m.fecha_realizada, m.fecha_programada) BETWEEN ? AND ?'; array_push($params, $ini, date('Y-m-t', strtotime($ini)));
}
if ($b = get('b')) {
    $where[] = '(eq.codigo LIKE ? OR eq.marca LIKE ? OR eq.modelo LIKE ? OR eq.serial LIKE ? OR eq.ubicacion LIKE ? OR eq.responsable LIKE ? OR m.actividades LIKE ?)';
    for ($i = 0; $i < 7; $i++) $params[] = "%$b%";
}
$w = implode(' AND ', $where);
$orden = in_array($estado, ['realizados', 'todos'], true) ? 'COALESCE(m.fecha_realizada, m.fecha_programada) DESC' : 'm.fecha_programada ASC';
$sql = "SELECT m.*, eq.codigo, eq.tipo, eq.marca, eq.modelo, eq.ubicacion, eq.responsable, eq.estado AS eq_estado, emp.nombre AS empresa, t.nombre AS tecnico
    FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id LEFT JOIN empresas emp ON emp.id = eq.empresa_id LEFT JOIN usuarios t ON t.id = m.tecnico_id
    WHERE $w ORDER BY $orden, eq.codigo";

if (get('exportar') && can('mantenimiento', 'administrar')) {
    require_once APP_DIR . '/lib/xlsx.php';
    $rows = [];
    foreach (all($sql, $params) as $r) {
        $rows[] = [$r['fecha_programada'], $r['estado'], $r['codigo'], $r['tipo'], trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')), $r['empresa'], $r['ubicacion'], $r['responsable'],
            MANT_PERIODOS[(int)$r['periodicidad_meses']] ?? '', $r['tecnico'], $r['fecha_realizada'], $r['actividades'], $r['observaciones']];
    }
    xlsx_download('mantenimientos_' . date('Ymd_Hi') . '.xlsx', [['name' => 'Mantenimientos',
        'headers' => ['Fecha programada', 'Estado', 'Código', 'Tipo', 'Equipo', 'Empresa', 'Ubicación', 'Responsable', 'Periodicidad', 'Técnico', 'Fecha realizada', 'Actividades', 'Observaciones'],
        'rows' => $rows, 'widths' => [15, 12, 11, 18, 22, 22, 18, 20, 22, 20, 15, 40, 40]]]);
}

$per = 50;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id WHERE $w", $params);
$rows = all($sql . " LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$tecnicos = array_column(all("SELECT DISTINCT u.id, u.nombre FROM mantenimientos m JOIN usuarios u ON u.id = m.tecnico_id ORDER BY u.nombre"), 'nombre', 'id');
$qs = array_filter(['p' => 'mantenimiento', 'estado' => $estado, 'tipo' => get('tipo'), 'empresa' => get('empresa'), 'tecnico' => get('tecnico'), 'mes' => get('mes'), 'b' => get('b')]);
$puedeAprobar = can('mantenimiento', 'aprobar');
$mesAct = null;
?>
<div class="kpis">
  <a class="kpi <?= $kVenc ? 'danger bad' : '' ?> text-decoration-none text-reset" href="<?= url('mantenimiento', ['estado' => 'vencidos']) ?>"><div class="lbl"><i class="bi bi-exclamation-octagon"></i>Vencidos</div><div class="v"><?= $kVenc ?></div><div class="sub">Pendientes de realizar</div></a>
  <a class="kpi <?= $kSem ? 'warn' : '' ?> text-decoration-none text-reset" href="<?= url('mantenimiento', ['estado' => 'proximos']) ?>"><div class="lbl"><i class="bi bi-calendar-event"></i>Próximos 7 días</div><div class="v"><?= $kSem ?></div></a>
  <a class="kpi accent text-decoration-none text-reset" href="<?= url('mantenimiento', ['estado' => 'proximos']) ?>"><div class="lbl"><i class="bi bi-calendar-range"></i>Próximos 30 días</div><div class="v"><?= $kMes ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('mantenimiento') ?>"><div class="lbl"><i class="bi bi-calendar-check"></i>Programados</div><div class="v"><?= $kProg ?></div></a>
  <a class="kpi text-decoration-none text-reset" href="<?= url('mantenimiento', ['estado' => 'realizados']) ?>"><div class="lbl"><i class="bi bi-check2-circle"></i>Realizados <?= date('Y') ?></div><div class="v"><?= $kHechos ?></div></a>
</div>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="mantenimiento">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Código, marca, ubicación, responsable…">
    <select class="form-select" name="estado" data-autosubmit>
      <?= options(['pendientes' => 'Pendientes (programados)', 'vencidos' => 'Vencidos', 'proximos' => 'Próximos 30 días', 'realizados' => 'Realizados', 'cancelados' => 'Cancelados', 'todos' => 'Todos'], $estado) ?>
    </select>
    <select class="form-select" name="tipo" data-autosubmit><option value="">Todo tipo de equipo</option><?= options(EQUIPO_TIPOS, get('tipo'), false) ?></select>
    <?php if (alcance() === 'todos'): ?><select class="form-select" name="empresa" data-autosubmit><option value="">Toda empresa</option><?= options(empresas_list(), get('empresa')) ?></select><?php endif; ?>
    <?php if ($tecnicos): ?><select class="form-select" name="tecnico" data-autosubmit><option value="">Todo técnico</option><?= options($tecnicos, get('tecnico')) ?></select><?php endif; ?>
    <input class="form-control" type="month" name="mes" value="<?= e(get('mes')) ?>" style="max-width:165px" title="Filtrar por mes" onchange="this.form.submit()">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <div class="actions">
      <?php if (can('mantenimiento', 'administrar')): ?>
        <a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 1])) ?>"><i class="bi bi-download me-1"></i>Excel</a>
        <button class="btn btn-outline-primary" form="f-recordar" title="Envía ahora los avisos de próximos mantenimientos y de pendientes"><i class="bi bi-bell me-1"></i>Enviar recordatorios</button>
      <?php endif; ?>
      <?php if (can('mantenimiento', 'crear')): ?><a class="btn btn-primary" href="<?= url('mantenimiento_form') ?>"><i class="bi bi-calendar-plus me-1"></i>Programar</a><?php endif; ?>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Fecha</th><th>Equipo</th><?php if (alcance() !== 'empresa'): ?><th>Empresa</th><?php endif; ?><th>Ubicación</th><th>Periodicidad</th><th>Técnico</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $f = $r['fecha_realizada'] ?: $r['fecha_programada'];
        $ym = date('Y-m', strtotime($f));
        if ($ym !== $mesAct): $mesAct = $ym; ?>
      <tr class="table-light"><td colspan="8" class="fw-semibold small text-uppercase muted" style="letter-spacing:.04em"><?= e(MESES[(int)date('n', strtotime($f))] . ' ' . date('Y', strtotime($f))) ?></td></tr>
    <?php endif; ?>
      <tr class="<?= $r['estado'] === 'Cancelado' ? 'muted' : '' ?>">
        <td class="nowrap"><?= fdate($f) ?><?php if ($r['estado'] === 'Realizado' && $r['fecha_realizada'] !== $r['fecha_programada']): ?><br><small class="muted">Prog. <?= fdate($r['fecha_programada']) ?></small><?php endif; ?></td>
        <td><a class="row-link" href="<?= url('equipo', ['id' => $r['equipo_id']]) ?>"><?= e($r['codigo']) ?></a><br><small class="muted"><?= e($r['tipo'] . ' ' . trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''))) ?></small></td>
        <?php if (alcance() !== 'empresa'): ?><td><?= e($r['empresa'] ?? '—') ?></td><?php endif; ?>
        <td class="muted"><?= e($r['ubicacion'] ?: ($r['responsable'] ?: '—')) ?></td>
        <td class="small muted"><?= e(MANT_PERIODOS[(int)$r['periodicidad_meses']] ?? '—') ?></td>
        <td><?= e($r['tecnico'] ?: '—') ?></td>
        <td><?= mant_badge($r) ?></td>
        <td class="r nowrap">
          <?php if ($r['estado'] === 'Programado'): ?>
            <?php if ($puedeAprobar): ?><form method="post" class="d-inline" data-confirm="¿Registrar el mantenimiento de <?= e($r['codigo']) ?> como realizado hoy?"><?= csrf_field() ?><input type="hidden" name="accion" value="realizar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-success" title="Marcar como realizado hoy"><i class="bi bi-check2"></i></button></form><?php endif; ?>
          <?php endif; ?>
          <?php if (can('mantenimiento', 'editar')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('mantenimiento_form', ['id' => $r['id']]) ?>" title="Editar / reprogramar / registrar"><i class="bi bi-pencil"></i></a><?php endif; ?>
          <?php if ($r['estado'] === 'Programado' && $puedeAprobar): ?><form method="post" class="d-inline" data-confirm="¿Cancelar este mantenimiento?"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Cancelar"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
          <?php if (can('mantenimiento', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar esta programación?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No hay mantenimientos con esos filtros.<?= can('mantenimiento', 'crear') ? ' <a href="' . url('mantenimiento_form') . '">Programar el primero</a>' : '' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
<form method="post" id="f-recordar" hidden data-confirm="¿Enviar ahora por correo los avisos de mantenimientos próximos y pendientes a sus responsables?"><?= csrf_field() ?><input type="hidden" name="accion" value="recordatorios"></form>
