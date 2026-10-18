<?php
require_once APP_DIR . '/computo.php';
$id = (int)get('id', 0);
[$scope, $params] = equipo_scope('eq');
$q = one("SELECT eq.*, emp.nombre AS empresa, u.nombre AS usuario, u.email AS usuario_email
    FROM equipos eq LEFT JOIN empresas emp ON emp.id = eq.empresa_id LEFT JOIN usuarios u ON u.id = eq.usuario_id
    WHERE eq.id = ? AND $scope", array_merge([$id], $params));
if (!$q) not_found();
$title = $q['codigo'] . ' · ' . $q['tipo'];
$comp = es_computo($q['tipo']);
$soft = $comp ? all('SELECT * FROM equipo_software WHERE equipo_id = ? ORDER BY CASE categoria WHEN \'so\' THEN 1 WHEN \'ofimatica\' THEN 2 WHEN \'antivirus\' THEN 3 ELSE 4 END, nombre', [$id]) : [];
$noLegal = count(array_filter($soft, fn($s) => $s['licencia'] === 'No legal'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    if (post('accion') === 'nota' && post('nota')) {
        insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => user()['id'], 'descripcion' => mb_substr((string)post('nota'), 0, 500), 'fecha' => now()]);
        flash('Nota agregada a la hoja de vida.');
    } elseif (post('accion') === 'eliminar') {
        q('DELETE FROM equipos WHERE id = ?', [$id]);
        flash('Equipo eliminado del inventario.');
        redirect(url('inventario'));
    }
    redirect(url('equipo', ['id' => $id]));
}

[$ts, $tp] = ticket_scope('t');
$tickets = all("SELECT t.id, t.codigo, t.asunto, t.estado, t.prioridad, t.creado, t.cerrado FROM tickets t WHERE t.equipo_id = ? AND $ts ORDER BY t.id DESC", array_merge([$id], $tp));
$movs = all('SELECT m.*, u.nombre FROM equipo_movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.equipo_id = ? ORDER BY m.id DESC', [$id]);
$garantia = $q['garantia_hasta'] ? ($q['garantia_hasta'] >= today() ? badge('Vigente hasta ' . fdate($q['garantia_hasta']), 'success') : badge('Vencida ' . fdate($q['garantia_hasta']), 'secondary')) : '—';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url($comp ? 'computo_lista' : 'inventario') ?>"><i class="bi bi-arrow-left"></i> <?= $comp ? 'Equipos de cómputo' : 'Otros equipos' ?></a>
  <h2 class="h5 m-0 ms-1"><?= e($comp && $q['responsable'] ? $q['responsable'] . ' · ' . trim(($q['marca'] ?? '') . ' ' . ($q['modelo'] ?? '')) : (trim(($q['marca'] ?? '') . ' ' . ($q['modelo'] ?? '')) ?: $q['tipo'])) ?></h2>
  <?= badge($q['estado']) ?>
  <?php if ($comp && $q['renovacion']): ?><?= badge('Renovación ' . mb_strtolower($q['renovacion']), ['Bajo' => 'success', 'Medio' => 'warning', 'Alto' => 'danger'][$q['renovacion']]) ?><?php endif; ?>
  <?php if ($noLegal): ?><?= badge($noLegal . ' software no legal', 'danger') ?><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= url('ticket_form', ['equipo' => $id]) ?>"><i class="bi bi-life-preserver me-1"></i>Reportar falla</a>
    <?php if (is_admin()): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= url($comp ? 'computo_form' : 'equipo_form', ['id' => $id]) ?>"><i class="bi bi-pencil"></i> Editar</a>
      <form method="post" data-confirm="¿Eliminar el equipo? Los tickets se conservarán sin equipo vinculado. Si solo salió de servicio, mejor cambie su estado a 'Dado de baja'."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?php if ($comp): ?>
    <div class="panel">
      <div class="panel-h"><h3>Software y licencias</h3><div class="actions"><span class="small muted"><?= count($soft) ?> registrado(s)</span></div></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Categoría</th><th>Software</th><th>Versión</th><th>Tipo de licencia</th><th>Estado</th><th>Vence</th></tr></thead>
        <tbody>
        <?php foreach ($soft as $s): $venc = $s['vence'] && $s['vence'] < today(); ?>
          <tr><td class="muted"><?= e((SOFT_BASE + ['otro' => 'Otro'])[$s['categoria']] ?? $s['categoria']) ?></td><td class="fw-semibold"><?= e($s['nombre']) ?></td><td><?= e($s['version'] ?: '—') ?></td>
            <td><?= e($s['tipo_licencia'] ?: '—') ?></td><td><?= badge($s['licencia']) ?></td><td class="nowrap <?= $venc ? 'text-danger' : '' ?>"><?= $s['vence'] ? fdate($s['vence']) . ($venc ? ' (vencida)' : '') : '—' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$soft): ?><tr><td colspan="6" class="empty">Sin software registrado.<?= is_admin() ? ' <a href="' . url('computo_form', ['id' => $id]) . '">Registrar</a>' : '' ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-h"><h3>Tickets de soporte</h3><div class="actions"><span class="muted small"><?= count($tickets) ?> en total</span></div></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Código</th><th>Asunto</th><th>Prioridad</th><th>Estado</th><th>Creado</th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
          <tr><td><a class="row-link" href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['codigo']) ?></a></td><td><?= e($t['asunto']) ?></td><td><?= badge($t['prioridad']) ?></td><td><?= badge($t['estado']) ?></td><td class="nowrap muted"><?= fdate($t['creado']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$tickets): ?><tr><td colspan="5" class="empty">Este equipo no tiene tickets.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php if ($q['especificaciones'] || $q['notas']): ?>
    <div class="panel">
      <?php if ($q['especificaciones']): ?><div class="panel-h"><h3>Especificaciones</h3></div><div class="msg-b mb-3"><?= e($q['especificaciones']) ?></div><?php endif; ?>
      <?php if ($q['notas']): ?><div class="panel-h"><h3><?= $comp ? 'Observación' : 'Notas' ?></h3></div><div class="msg-b"><?= e($q['notas']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-h"><h3>Hoja de vida</h3></div>
      <?php if (is_admin()): ?>
      <form method="post" class="d-flex gap-2 mb-3"><?= csrf_field() ?><input type="hidden" name="accion" value="nota">
        <input class="form-control" name="nota" maxlength="500" placeholder="Registrar mantenimiento, cambio de pieza, préstamo…" required>
        <button class="btn btn-outline-primary text-nowrap">Agregar</button>
      </form>
      <?php endif; ?>
      <ul class="timeline">
        <?php foreach ($movs as $m): ?><li><?= e($m['descripcion']) ?><small><?= fdate($m['fecha'], true) ?><?= $m['nombre'] ? ' · ' . e($m['nombre']) : '' ?></small></li><?php endforeach; ?>
        <?php if (!$movs): ?><li class="muted">Sin movimientos.</li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Ficha</h3></div>
      <dl>
        <dt>Código</dt><dd><strong><?= e($q['codigo']) ?></strong></dd>
        <dt>Tipo</dt><dd><?= e($q['tipo']) ?></dd>
        <?php if ($comp): ?>
        <dt>Usuario</dt><dd><?= e($q['responsable'] ?: ($q['usuario'] ?: '—')) ?><?= $q['usuario'] && $q['responsable'] && $q['usuario'] !== $q['responsable'] ? '<br><small class="muted">Usuario del sistema: ' . e($q['usuario']) . '</small>' : '' ?></dd>
        <dt>Cargo</dt><dd><?= e($q['cargo'] ?: '—') ?></dd>
        <dt>Correos</dt><dd><?= $q['correos'] ? implode('<br>', array_map(fn($c) => '<a href="mailto:' . e(trim($c)) . '">' . e(trim($c)) . '</a>', explode(',', $q['correos']))) : '—' ?></dd>
        <dt>Propietario</dt><dd><?= e($q['empresa'] ?? '—') ?></dd>
        <dt>Procesador</dt><dd><?= e($q['procesador'] ?: '—') ?></dd>
        <dt>RAM</dt><dd><?= e($q['ram'] ?: '—') ?></dd>
        <dt>DD</dt><dd><?= e($q['disco'] ?: '—') ?></dd>
        <dt>Renovación</dt><dd><?= $q['renovacion'] ? badge($q['renovacion']) : '—' ?><?php $sg = renovacion_sugerida($q['fecha_compra']); if ($sg && $sg !== $q['renovacion']): ?><br><small class="muted">Sugerido por antigüedad: <?= $sg ?></small><?php endif; ?></dd>
        <?php endif; ?>
        <dt>Marca</dt><dd><?= e($q['marca'] ?: '—') ?></dd>
        <dt>Modelo</dt><dd><?= e($q['modelo'] ?: '—') ?></dd>
        <dt>Serial</dt><dd><?= e($q['serial'] ?: '—') ?></dd>
        <?php if ($q['imei']): ?><dt>IMEI</dt><dd><?= e($q['imei']) ?></dd><?php endif; ?>
        <?php if ($q['linea']): ?><dt>Línea</dt><dd><?= e($q['linea']) ?></dd><?php endif; ?>
        <?php if ($q['ip']): ?><dt>IP</dt><dd><?= e($q['ip']) ?></dd><?php endif; ?>
        <?php if (!$comp): ?>
        <dt>Empresa</dt><dd><?= e($q['empresa'] ?? '—') ?></dd>
        <dt>Asignado a</dt><dd><?= e($q['usuario'] ?: ($q['responsable'] ?: '—')) ?></dd>
        <?php endif; ?>
        <dt>Ubicación</dt><dd><?= e($q['ubicacion'] ?: '—') ?></dd>
        <dt>Compra</dt><dd><?= fdate($q['fecha_compra']) ?><?= $q['valor'] ? ' · ' . money($q['valor']) : '' ?></dd>
        <dt>Garantía</dt><dd><?= $garantia ?></dd>
        <dt>Registrado</dt><dd><?= fdate($q['creado']) ?></dd>
      </dl>
    </div>
  </div>
</div>
