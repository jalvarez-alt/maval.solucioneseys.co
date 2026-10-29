<?php
$title = 'Mesa de ayuda';
[$scope, $params] = ticket_scope('t');
$where = [$scope];

$estado = get('estado', 'activos');
if (trim((string)get('b')) !== '' && $estado === 'activos') $estado = 'todos';   // al buscar se incluyen también los tickets cerrados
if ($estado === 'activos') { $where[] = "t.estado IN ('Abierto','En proceso','En espera')"; }
elseif (in_array($estado, TICKET_ESTADOS, true)) { $where[] = 't.estado = ?'; $params[] = $estado; }
if (($pr = get('prioridad')) && in_array($pr, TICKET_PRIORIDADES, true)) { $where[] = 't.prioridad = ?'; $params[] = $pr; }
if (($cat = get('categoria')) && in_array($cat, TICKET_CATEGORIAS, true)) { $where[] = 't.categoria = ?'; $params[] = $cat; }
if (alcance() === 'todos' && ($emp = (int)get('empresa', 0))) { $where[] = 't.empresa_id = ?'; $params[] = $emp; }
if (alcance() === 'todos' && get('asignado') === 'yo') { $where[] = 't.asignado_id = ?'; $params[] = (int)user()['id']; }
if (alcance() === 'todos' && get('asignado') === 'sin') { $where[] = 't.asignado_id IS NULL'; }
if ($b = trim((string)get('b'))) {
    $where[] = '(t.asunto LIKE ? OR t.codigo LIKE ? OR t.descripcion LIKE ? OR t.id IN (SELECT u.id FROM tickets u LEFT JOIN usuarios su ON su.id = u.solicitante_id LEFT JOIN empresas eu ON eu.id = u.empresa_id LEFT JOIN equipos qu ON qu.id = u.equipo_id WHERE su.nombre LIKE ? OR eu.nombre LIKE ? OR qu.codigo LIKE ?))';
    array_push($params, "%$b%", "%$b%", "%$b%", "%$b%", "%$b%", "%$b%");
}
$w = implode(' AND ', $where);

$per = 25;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM tickets t WHERE $w", $params);
$rows = all("SELECT t.*, s.nombre AS solicitante, a.nombre AS asignado, e.nombre AS empresa, eq.codigo AS equipo_codigo, eq.tipo AS equipo_tipo
    FROM tickets t
    LEFT JOIN usuarios s ON s.id = t.solicitante_id
    LEFT JOIN usuarios a ON a.id = t.asignado_id
    LEFT JOIN empresas e ON e.id = t.empresa_id
    LEFT JOIN equipos eq ON eq.id = t.equipo_id
    WHERE $w
    ORDER BY CASE t.estado WHEN 'Abierto' THEN 1 WHEN 'En proceso' THEN 2 WHEN 'En espera' THEN 3 ELSE 4 END,
             CASE t.prioridad WHEN 'Crítica' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Media' THEN 3 ELSE 4 END, t.id DESC
    LIMIT $per OFFSET " . (($page - 1) * $per), $params);

[$s2, $p2] = ticket_scope('t');
$cnt = array_column(all("SELECT t.estado, COUNT(*) n FROM tickets t WHERE $s2 GROUP BY t.estado", $p2), 'n', 'estado');
$criticos = (int)val("SELECT COUNT(*) FROM tickets t WHERE $s2 AND t.estado IN ('Abierto','En proceso','En espera') AND t.prioridad IN ('Alta','Crítica')", $p2);
$qs = array_filter(['p' => 'tickets', 'estado' => $estado, 'prioridad' => get('prioridad'), 'categoria' => get('categoria'), 'empresa' => get('empresa'), 'asignado' => get('asignado'), 'b' => get('b')]);
?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-inbox"></i>Abiertos</div><div class="v"><?= (int)($cnt['Abierto'] ?? 0) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-gear"></i>En proceso</div><div class="v"><?= (int)($cnt['En proceso'] ?? 0) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-hourglass-split"></i>En espera</div><div class="v"><?= (int)($cnt['En espera'] ?? 0) ?></div></div>
  <div class="kpi danger"><div class="lbl"><i class="bi bi-exclamation-triangle"></i>Alta / crítica sin cerrar</div><div class="v"><?= $criticos ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Resueltos y cerrados</div><div class="v"><?= (int)($cnt['Resuelto'] ?? 0) + (int)($cnt['Cerrado'] ?? 0) ?></div></div>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="tickets">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Buscar por código, asunto, descripción, solicitante, empresa o equipo">
    <button class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Buscar</button>
    <?php if (get('b') !== null && get('b') !== ''): ?><a class="btn btn-outline-secondary" href="<?= url('tickets') ?>">Limpiar</a><?php endif; ?>
    <select class="form-select" name="estado" data-autosubmit>
      <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Sin cerrar</option>
      <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos los estados</option>
      <?= options(TICKET_ESTADOS, $estado, false) ?>
    </select>
    <select class="form-select" name="prioridad" data-autosubmit><option value="">Toda prioridad</option><?= options(TICKET_PRIORIDADES, get('prioridad'), false) ?></select>
    <select class="form-select" name="categoria" data-autosubmit><option value="">Toda categoría</option><?= options(TICKET_CATEGORIAS, get('categoria'), false) ?></select>
    <?php if (alcance() === 'todos'): ?>
    <select class="form-select" name="empresa" data-autosubmit><option value="">Toda empresa</option><?= options(empresas_list(), get('empresa')) ?></select>
    <select class="form-select" name="asignado" data-autosubmit><option value="">Cualquier técnico</option><?= options(['yo' => 'Asignados a mí', 'sin' => 'Sin asignar'], get('asignado')) ?></select>
    <?php endif; ?>
    <div class="actions"><?php if (can('tickets', 'crear')): ?><a class="btn btn-primary" href="<?= url('ticket_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nuevo ticket</a><?php endif; ?></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Código</th><th>Asunto</th><th>Categoría</th><th>Prioridad</th><th>Estado</th><?php if (alcance() !== 'propios'): ?><th>Solicitante</th><?php endif; ?><?php if (alcance() === 'todos'): ?><th>Empresa</th><th>Asignado</th><?php endif; ?><th>Equipo</th><th>Creado</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><a class="row-link" href="<?= url('ticket', ['id' => $r['id']]) ?>"><?= e($r['codigo']) ?></a></td>
        <td><a class="row-link fw-normal" href="<?= url('ticket', ['id' => $r['id']]) ?>"><?= e($r['asunto']) ?></a></td>
        <td class="muted"><?= e($r['categoria']) ?></td>
        <td><?= badge($r['prioridad']) ?></td>
        <td><?= badge($r['estado']) ?></td>
        <?php if (alcance() !== 'propios'): ?><td><?= e($r['solicitante'] ?? '—') ?></td><?php endif; ?>
        <?php if (alcance() === 'todos'): ?><td><?= e($r['empresa'] ?? '—') ?></td><td><?= $r['asignado'] ? e($r['asignado']) : '<span class="muted">Sin asignar</span>' ?></td><?php endif; ?>
        <td class="nowrap"><?= $r['equipo_codigo'] ? '<a href="' . url('equipo', ['id' => $r['equipo_id']]) . '">' . e($r['equipo_codigo']) . '</a>' : '<span class="muted">—</span>' ?></td>
        <td class="nowrap muted"><?= fdate($r['creado'], true) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">No hay tickets con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
