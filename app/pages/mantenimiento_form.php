<?php
require_once APP_DIR . '/mantenimiento.php';
$id = (int)get('id', 0);
$m = $id ? mant_obtener($id) : null;
if ($id && !$m) not_found();
require_perm('mantenimiento', $id ? 'editar' : 'crear');
$uid = (int)user()['id'];
$errors = [];
$tecnicos = array_column(usuarios_soporte(), 'nombre', 'id');
if ($m && $m['tecnico_id'] && !isset($tecnicos[$m['tecnico_id']])) $tecnicos[$m['tecnico_id']] = val('SELECT nombre FROM usuarios WHERE id = ?', [$m['tecnico_id']]);
asort($tecnicos);
$avisoDef = (int)ajuste('mant_aviso_dias', 7);

// =====================================================================
// Edición de una programación
// =====================================================================
if ($m) {
    $title = 'Mantenimiento · ' . $m['codigo'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acc = post('accion');
        if ($acc === 'guardar') {
            $fecha = valid_date(post('fecha_programada'));
            $per = (int)post('periodicidad_meses', 0);
            if (!$fecha) $errors[] = 'Indique una fecha válida.';
            if (!isset(MANT_PERIODOS[$per])) $per = 0;
            if (!$errors) {
                $d = ['fecha_programada' => $fecha, 'periodicidad_meses' => $per, 'aviso_dias' => max(0, min(90, (int)post('aviso_dias', 7))),
                    'tecnico_id' => post('tecnico_id') ? (int)post('tecnico_id') : null, 'actividades' => post('actividades'), 'observaciones' => post('observaciones'), 'actualizado' => now()];
                $cambioFecha = $fecha !== $m['fecha_programada'];
                if ($cambioFecha) $d += ['programacion_enviada' => null, 'aviso_enviado' => null, 'pendiente_enviado' => null]; // vuelve a avisar con la nueva fecha
                update('mantenimientos', $d, $id);
                if ($cambioFecha) {
                    insert('equipo_movimientos', ['equipo_id' => $m['equipo_id'], 'usuario_id' => $uid, 'fecha' => now(), 'descripcion' => 'Mantenimiento preventivo reprogramado: ' . fdate($m['fecha_programada']) . ' → ' . fdate($fecha) . '.']);
                    if ($m['estado'] === 'Programado' && post('notificar')) notificar_programacion([$id]);
                }
                flash('Programación actualizada.');
                redirect(url('mantenimiento_form', ['id' => $id]));
            }
            $m = array_merge($m, ['fecha_programada' => post('fecha_programada'), 'actividades' => post('actividades'), 'observaciones' => post('observaciones')]);
        } elseif ($acc === 'realizar') {
            require_perm('mantenimiento', 'aprobar');
            $fecha = valid_date(post('fecha_realizada')) ?: today();
            if ($fecha > today()) $errors[] = 'La fecha de realización no puede ser futura.';
            if (!$errors) {
                $nid = mant_realizar($m, $fecha, post('obs'));
                if ($nid) notificar_programacion([$nid]);
                flash('Mantenimiento registrado como realizado.' . ($nid ? ' Se programó el siguiente automáticamente.' : ''));
                redirect(url('mantenimiento'));
            }
        } elseif ($acc === 'cancelar') {
            require_perm('mantenimiento', 'aprobar');
            update('mantenimientos', ['estado' => 'Cancelado', 'actualizado' => now()], $id);
            flash('Mantenimiento cancelado.');
            redirect(url('mantenimiento'));
        } elseif ($acc === 'reabrir') {
            update('mantenimientos', ['estado' => 'Programado', 'fecha_realizada' => null, 'actualizado' => now()], $id);
            flash('La programación volvió a estado «Programado».');
            redirect(url('mantenimiento_form', ['id' => $id]));
        }
    }
    ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('mantenimiento') ?>"><i class="bi bi-arrow-left"></i> Planeador</a>
  <h2 class="h5 m-0 ms-1"><?= e($m['codigo'] . ' · ' . $m['tipo'] . ' ' . trim(($m['marca'] ?? '') . ' ' . ($m['modelo'] ?? ''))) ?></h2>
  <?= mant_badge($m) ?>
  <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= url('equipo', ['id' => $m['equipo_id']]) ?>">Ver equipo</a>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
      <div class="panel-h"><h3>Programación</h3></div>
      <div class="form-grid">
        <div><label class="form-label req">Fecha programada</label><input class="form-control" type="date" name="fecha_programada" value="<?= e($m['fecha_programada']) ?>" required></div>
        <div><label class="form-label">Se repite</label><select class="form-select" name="periodicidad_meses"><?= options(MANT_PERIODOS, (int)$m['periodicidad_meses']) ?></select></div>
        <div><label class="form-label">Avisar con … días de anticipación</label><input class="form-control" type="number" min="0" max="90" name="aviso_dias" value="<?= (int)$m['aviso_dias'] ?>"></div>
        <div><label class="form-label">Técnico responsable</label><select class="form-select" name="tecnico_id"><option value="">— Sin asignar —</option><?= options($tecnicos, $m['tecnico_id']) ?></select></div>
        <div class="full"><label class="form-label">Actividades a realizar</label><textarea class="form-control" name="actividades" rows="3"><?= e($m['actividades']) ?></textarea></div>
        <div class="full"><label class="form-label">Observaciones</label><textarea class="form-control" name="observaciones" rows="3"><?= e($m['observaciones']) ?></textarea></div>
        <?php if ($m['estado'] === 'Programado' && correo_activo()): ?><div class="full"><div class="form-check"><input class="form-check-input" type="checkbox" name="notificar" value="1" id="nf" checked><label class="form-check-label small" for="nf">Si cambio la fecha, avisar por correo a los responsables</label></div></div><?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Guardar cambios</button><a class="btn btn-outline-secondary" href="<?= url('mantenimiento') ?>">Volver</a></div>
    </form>
  </div>
  <div class="col-lg-5">
    <?php if ($m['estado'] === 'Programado' && can('mantenimiento', 'aprobar')): ?>
    <form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="accion" value="realizar">
      <div class="panel-h"><h3>Registrar como realizado</h3></div>
      <div class="mb-3"><label class="form-label">Fecha en que se realizó</label><input class="form-control" type="date" name="fecha_realizada" value="<?= today() ?>" max="<?= today() ?>"></div>
      <div class="mb-3"><label class="form-label">Observaciones del mantenimiento</label><textarea class="form-control" name="obs" rows="3" placeholder="Qué se hizo, repuestos cambiados, hallazgos…"></textarea></div>
      <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Marcar como realizado</button>
      <?php if ((int)$m['periodicidad_meses'] > 0): ?><p class="small muted mt-2 mb-0">Como se repite <?= e(mb_strtolower(MANT_PERIODOS[(int)$m['periodicidad_meses']])) ?>, se programará el siguiente automáticamente y se avisará por correo.</p><?php endif; ?>
    </form>
    <form method="post" class="panel" data-confirm="¿Cancelar este mantenimiento?"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><button class="btn btn-outline-secondary btn-sm">Cancelar esta programación</button></form>
    <?php elseif ($m['estado'] !== 'Programado'): ?>
    <div class="panel">
      <div class="panel-h"><h3><?= e($m['estado']) ?></h3></div>
      <?php if ($m['fecha_realizada']): ?><p class="mb-2">Realizado el <strong><?= fdate($m['fecha_realizada']) ?></strong>.</p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="reabrir"><button class="btn btn-outline-secondary btn-sm">Volver a programar</button></form>
    </div>
    <?php endif; ?>
    <div class="panel meta">
      <div class="panel-h"><h3>Avisos por correo</h3></div>
      <dl>
        <dt>Programación informada</dt><dd><?= fdate($m['programacion_enviada'], true) ?></dd>
        <dt>Recordatorio previo</dt><dd><?= fdate($m['aviso_enviado'], true) ?></dd>
        <dt>Último aviso de pendiente</dt><dd><?= fdate($m['pendiente_enviado'], true) ?></dd>
      </dl>
      <?php if (!correo_activo()): ?><p class="small muted mb-0">El envío de correo no está activo. <?= can('config', 'ver') ? '<a href="' . url('config_correo') . '">Configurarlo</a>' : '' ?></p><?php endif; ?>
    </div>
  </div>
</div>
<?php
    return;
}

// =====================================================================
// Nueva programación (uno o varios equipos)
// =====================================================================
$title = 'Programar mantenimiento';
[$es, $ep] = equipo_scope('eq');
$equipos = all("SELECT eq.id, eq.codigo, eq.tipo, eq.marca, eq.modelo, eq.ubicacion, eq.responsable, emp.nombre AS empresa,
        (SELECT MAX(m.fecha_realizada) FROM mantenimientos m WHERE m.equipo_id = eq.id AND m.estado = 'Realizado') AS ultimo,
        (SELECT MIN(m.fecha_programada) FROM mantenimientos m WHERE m.equipo_id = eq.id AND m.estado = 'Programado') AS proximo
    FROM equipos eq LEFT JOIN empresas emp ON emp.id = eq.empresa_id
    WHERE $es AND eq.estado <> 'Dado de baja' ORDER BY emp.nombre, eq.tipo, eq.codigo", $ep);
$porId = array_column($equipos, null, 'id');
$sel = [];
$v = ['fecha' => date('Y-m-d', strtotime('+7 days')), 'por_dia' => '', 'finde' => 1, 'per' => 12, 'aviso' => $avisoDef, 'tec' => '', 'act' => '', 'notificar' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sel = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['equipos'] ?? [])), fn($i) => isset($porId[$i]))));
    $v = ['fecha' => post('fecha', ''), 'por_dia' => (int)post('por_dia', 0), 'finde' => post('finde') ? 1 : 0, 'per' => (int)post('periodicidad_meses', 0),
        'aviso' => max(0, min(90, (int)post('aviso_dias', $avisoDef))), 'tec' => post('tecnico_id'), 'act' => (string)post('actividades', ''), 'notificar' => post('notificar') ? 1 : 0];
    $fecha = valid_date($v['fecha']);
    if (!$sel) $errors[] = 'Seleccione al menos un equipo.';
    if (!$fecha) $errors[] = 'Indique la fecha del primer mantenimiento.';
    elseif ($fecha < today()) $errors[] = 'La fecha del primer mantenimiento no puede ser anterior a hoy.';
    if (!isset(MANT_PERIODOS[$v['per']])) $v['per'] = 0;
    if (!$errors) {
        // Orden estable (empresa, tipo, código) y reparto por días según el máximo de equipos por día
        $orden = array_flip(array_column($equipos, 'id'));
        usort($sel, fn($a, $b) => $orden[$a] <=> $orden[$b]);
        $dia = mant_fecha_habil($fecha, (bool)$v['finde']);
        $enDia = 0; $ids = []; $omitidos = 0; $ult = $dia;
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($sel as $eid) {
            if ($v['por_dia'] > 0 && $enDia >= $v['por_dia']) { $dia = mant_fecha_habil(date('Y-m-d', strtotime($dia . ' +1 day')), (bool)$v['finde']); $enDia = 0; }
            if (val("SELECT id FROM mantenimientos WHERE equipo_id = ? AND estado = 'Programado' AND fecha_programada = ?", [$eid, $dia])) { $omitidos++; continue; }
            $eq = $porId[$eid];
            $mid = insert('mantenimientos', ['equipo_id' => $eid, 'fecha_programada' => $dia, 'estado' => 'Programado', 'periodicidad_meses' => $v['per'], 'aviso_dias' => $v['aviso'],
                'tecnico_id' => $v['tec'] ? (int)$v['tec'] : null, 'actividades' => mb_substr(trim($v['act']) !== '' ? trim($v['act']) : mant_actividades_base($eq['tipo']), 0, 500), 'creado_por' => $uid, 'creado' => now()]);
            insert('equipo_movimientos', ['equipo_id' => $eid, 'usuario_id' => $uid, 'fecha' => now(), 'descripcion' => 'Mantenimiento preventivo programado para el ' . fdate($dia) . ($v['per'] ? ' (' . mb_strtolower(MANT_PERIODOS[$v['per']]) . ').' : '.')]);
            $ids[] = $mid; $enDia++; $ult = $dia;
        }
        $pdo->commit();
        if ($ids && $v['notificar']) notificar_programacion($ids);
        flash(count($ids) . ' mantenimiento(s) programado(s)' . (count($ids) > 1 ? ' entre el ' . fdate($fecha) . ' y el ' . fdate($ult) : ' para el ' . fdate($fecha))
            . ($omitidos ? ". $omitidos ya estaba(n) programado(s) ese día y se omitió." : '.') . ($ids && $v['notificar'] && correo_activo() ? ' Se informó por correo a los responsables.' : ''));
        redirect(url('mantenimiento', ['estado' => 'pendientes']));
    }
} elseif ($pre = (int)get('equipo', 0)) {
    if (isset($porId[$pre])) { $sel = [$pre]; }
}
$tiposPresentes = array_values(array_unique(array_column($equipos, 'tipo')));
$empresasPresentes = array_values(array_filter(array_unique(array_column($equipos, 'empresa'))));
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('mantenimiento') ?>"><i class="bi bi-arrow-left"></i> Planeador</a>
  <span class="muted small">Seleccione los equipos del inventario, la fecha y cada cuánto se repite; la programación se genera sola.</span>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-h"><h3>1 · Equipos</h3><div class="actions"><span class="small muted"><strong id="n-sel">0</strong> seleccionado(s)</span></div></div>
      <div class="toolbar">
        <input class="form-control grow" id="f-texto" placeholder="Buscar por código, marca, ubicación…">
        <select class="form-select" id="f-tipo"><option value="">Todo tipo</option><?= options($tiposPresentes, '', false) ?></select>
        <?php if (count($empresasPresentes) > 1): ?><select class="form-select" id="f-emp"><option value="">Toda empresa</option><?= options($empresasPresentes, '', false) ?></select><?php endif; ?>
        <label class="form-check small m-0"><input class="form-check-input" type="checkbox" id="f-sinprog"> Sin mantenimiento programado</label>
      </div>
      <div class="tbl-wrap" style="max-height:480px;overflow:auto"><table class="tbl" id="tbl-eq">
        <thead><tr><th class="c"><input class="form-check-input" type="checkbox" id="sel-todos" title="Marcar los visibles"></th><th>Equipo</th><th>Empresa / ubicación</th><th>Último</th><th>Próximo</th></tr></thead>
        <tbody>
        <?php foreach ($equipos as $q): ?>
          <tr data-t="<?= e(mb_strtolower($q['codigo'] . ' ' . $q['tipo'] . ' ' . $q['marca'] . ' ' . $q['modelo'] . ' ' . $q['ubicacion'] . ' ' . $q['responsable'])) ?>" data-tipo="<?= e($q['tipo']) ?>" data-emp="<?= e($q['empresa']) ?>" data-prog="<?= $q['proximo'] ? 1 : 0 ?>">
            <td class="c"><input class="form-check-input eq" type="checkbox" name="equipos[]" value="<?= (int)$q['id'] ?>" <?= in_array((int)$q['id'], $sel, true) ? 'checked' : '' ?>></td>
            <td><strong><?= e($q['codigo']) ?></strong><br><small class="muted"><?= e($q['tipo'] . ' ' . trim(($q['marca'] ?? '') . ' ' . ($q['modelo'] ?? ''))) ?></small></td>
            <td class="small"><?= e($q['empresa'] ?? '—') ?><br><span class="muted"><?= e($q['ubicacion'] ?: ($q['responsable'] ?: '')) ?></span></td>
            <td class="small nowrap muted"><?= $q['ultimo'] ? fdate($q['ultimo']) : '—' ?></td>
            <td class="small nowrap"><?= $q['proximo'] ? fdate($q['proximo']) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$equipos): ?><tr><td colspan="5" class="empty">No hay equipos vigentes en el inventario.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="panel">
      <div class="panel-h"><h3>2 · Fechas y responsables</h3></div>
      <div class="form-grid">
        <div><label class="form-label req">Fecha del primer mantenimiento</label><input class="form-control" type="date" name="fecha" min="<?= today() ?>" value="<?= e($v['fecha']) ?>" required></div>
        <div><label class="form-label">Máximo de equipos por día</label><input class="form-control" type="number" min="0" name="por_dia" value="<?= e($v['por_dia'] ?: '') ?>" placeholder="Todos el mismo día"><div class="form-text">Si hay más, continúan los días siguientes.</div></div>
        <div class="full"><div class="form-check"><input class="form-check-input" type="checkbox" name="finde" value="1" id="fd" <?= $v['finde'] ? 'checked' : '' ?>><label class="form-check-label small" for="fd">Saltar sábados y domingos</label></div></div>
        <div><label class="form-label">Se repite</label><select class="form-select" name="periodicidad_meses"><?= options(MANT_PERIODOS, $v['per']) ?></select></div>
        <div><label class="form-label">Avisar con … días de anticipación</label><input class="form-control" type="number" min="0" max="90" name="aviso_dias" value="<?= (int)$v['aviso'] ?>"></div>
        <div class="full"><label class="form-label">Técnico responsable</label><select class="form-select" name="tecnico_id"><option value="">— Sin asignar —</option><?= options($tecnicos, $v['tec']) ?></select></div>
        <div class="full"><label class="form-label">Actividades a realizar</label><textarea class="form-control" name="actividades" rows="3" placeholder="Vacío = actividades sugeridas según el tipo de equipo (computador, aire acondicionado, impresora…)"><?= e($v['act']) ?></textarea></div>
        <?php if (correo_activo()): ?>
        <div class="full"><div class="form-check"><input class="form-check-input" type="checkbox" name="notificar" value="1" id="nt" <?= $v['notificar'] ? 'checked' : '' ?>><label class="form-check-label small" for="nt">Informar las fechas por correo a los responsables de los equipos y al técnico</label></div></div>
        <?php else: ?>
        <div class="full small muted"><i class="bi bi-envelope-slash me-1"></i>El correo no está activo: no se enviarán avisos. <?= can('config', 'ver') ? '<a href="' . url('config_correo') . '">Configurarlo</a>' : '' ?></div>
        <?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>Generar programación</button><a class="btn btn-outline-secondary" href="<?= url('mantenimiento') ?>">Cancelar</a></div>
    </div>
  </div>
</form>
<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('#tbl-eq tbody tr[data-t]'));
  var txt = document.getElementById('f-texto'), tipo = document.getElementById('f-tipo'), emp = document.getElementById('f-emp'), sp = document.getElementById('f-sinprog');
  var cnt = document.getElementById('n-sel'), all = document.getElementById('sel-todos');
  function visible(r) { return r.style.display !== 'none'; }
  function filtrar() {
    var t = txt.value.trim().toLowerCase();
    rows.forEach(function (r) {
      var ok = (!t || r.getAttribute('data-t').indexOf(t) !== -1) && (!tipo.value || r.getAttribute('data-tipo') === tipo.value)
        && (!emp || !emp.value || r.getAttribute('data-emp') === emp.value) && (!sp.checked || r.getAttribute('data-prog') === '0');
      r.style.display = ok ? '' : 'none';
    });
  }
  function contar() { cnt.textContent = rows.filter(function (r) { return r.querySelector('input.eq').checked; }).length; }
  [txt, tipo, emp, sp].forEach(function (el) { if (el) el.addEventListener('input', filtrar); if (el) el.addEventListener('change', filtrar); });
  all.addEventListener('change', function () { rows.forEach(function (r) { if (visible(r)) r.querySelector('input.eq').checked = all.checked; }); contar(); });
  document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('input.eq')) contar(); });
  contar();
})();
</script>
