<?php
$u = user();
$id = (int)get('id', 0);
[$scope, $params] = ticket_scope('t');
$t = one("SELECT t.*, s.nombre AS solicitante, s.email AS solicitante_email, s.telefono AS solicitante_tel, a.nombre AS asignado,
        e.nombre AS empresa, eq.codigo AS equipo_codigo, eq.tipo AS equipo_tipo, eq.marca AS equipo_marca, eq.modelo AS equipo_modelo, eq.serial AS equipo_serial
    FROM tickets t
    LEFT JOIN usuarios s ON s.id = t.solicitante_id
    LEFT JOIN usuarios a ON a.id = t.asignado_id
    LEFT JOIN empresas e ON e.id = t.empresa_id
    LEFT JOIN equipos eq ON eq.id = t.equipo_id
    WHERE t.id = ? AND $scope", array_merge([$id], $params));
if (!$t) not_found();
$title = $t['codigo'];

// «Atender» = cambiar estado, asignar y escribir notas internas; «aprobar» = marcar como resuelto o cerrar
$staff = can('tickets', 'administrar');
$puedeAprobar = can('tickets', 'aprobar');

function cambiar_estado(array $t, string $nuevo, int $uid): bool
{
    if ($nuevo === $t['estado']) return false;
    $d = ['estado' => $nuevo, 'actualizado' => now()];
    $d['cerrado'] = in_array($nuevo, ['Resuelto', 'Cerrado'], true) ? ($t['cerrado'] ?: now()) : null;
    update('tickets', $d, (int)$t['id']);
    insert('ticket_comentarios', ['ticket_id' => $t['id'], 'usuario_id' => $uid, 'mensaje' => 'Estado cambiado de «' . $t['estado'] . '» a «' . $nuevo . '».', 'interno' => 0, 'creado' => now()]);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = post('accion');
    if ($accion === 'responder') {
        $msg = (string)post('mensaje', '');
        $interno = $staff && post('interno') ? 1 : 0;
        $hayArchivos = !empty($_FILES['adjuntos']['name'][0]);
        $pedido = $staff ? (string)post('estado', $t['estado']) : $t['estado'];
        if ($msg === '' && !$hayArchivos && !($staff && $pedido !== $t['estado'])) {
            flash('Escriba un mensaje o adjunte un archivo.', 'warning');
        } elseif ($t['estado'] === 'Cerrado' && !$staff) {
            flash('El ticket está cerrado. Cree uno nuevo si el problema persiste.', 'warning');
        } elseif ($staff && $pedido !== $t['estado'] && in_array($pedido, ['Resuelto', 'Cerrado'], true) && !$puedeAprobar) {
            flash('No tiene permiso para marcar tickets como resueltos o cerrarlos.', 'warning');
        } else {
            if ($msg !== '' || $hayArchivos) {
                $cid = insert('ticket_comentarios', ['ticket_id' => $id, 'usuario_id' => $u['id'], 'mensaje' => $msg !== '' ? $msg : '(archivo adjunto)', 'interno' => $interno, 'creado' => now()]);
                save_uploads('adjuntos', 'ticket', $id);
                update('tickets', ['actualizado' => now()], $id);
            }
            $cambio = null;
            if ($staff) {
                if (in_array($pedido, TICKET_ESTADOS, true) && cambiar_estado($t, $pedido, (int)$u['id'])) $cambio = $pedido;
                if ($t['estado'] === 'Abierto' && $pedido === 'Abierto' && !$interno && $msg !== '' && cambiar_estado($t, 'En proceso', (int)$u['id'])) $cambio = 'En proceso';
                if (!$t['asignado_id']) update('tickets', ['asignado_id' => $u['id']], $id);
            } elseif (in_array($t['estado'], ['Resuelto', 'En espera'], true)) {
                // El solicitante respondió: vuelve a la cola del técnico
                if (cambiar_estado($t, 'En proceso', (int)$u['id'])) $cambio = 'En proceso';
            }
            // Correo: un solo aviso por envío (el cambio de estado lleva el mensaje; las notas internas no se envían)
            $publico = $interno ? '' : ($msg !== '' ? $msg : ($hayArchivos ? '(archivo adjunto)' : ''));
            if ($cambio) notificar_ticket($id, 'estado', ['anterior' => $t['estado'], 'nuevo' => $cambio, 'mensaje' => $publico]);
            elseif ($publico !== '') notificar_ticket($id, 'respuesta', ['mensaje' => $publico]);
            flash('Respuesta registrada.');
        }
    } elseif ($accion === 'cerrar' && !$staff && $t['estado'] === 'Resuelto') {
        if (cambiar_estado($t, 'Cerrado', (int)$u['id'])) notificar_ticket($id, 'estado', ['anterior' => 'Resuelto', 'nuevo' => 'Cerrado']);
        flash('Gracias. El ticket quedó cerrado.');
    } elseif ($accion === 'eliminar' && can('tickets', 'eliminar')) {
        foreach (adjuntos('ticket', $id) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
        q("DELETE FROM adjuntos WHERE entidad = 'ticket' AND entidad_id = ?", [$id]);
        q('DELETE FROM tickets WHERE id = ?', [$id]);
        flash('Ticket eliminado.');
        redirect(url('tickets'));
    }
    redirect(url('ticket', ['id' => $id]));
}

$coms = all("SELECT c.*, u.nombre, u.rol,
        (ro.codigo = 'admin' OR u.rol_id IN (SELECT rol_id FROM rol_permisos WHERE modulo = 'tickets' AND accion = 'administrar')) AS es_soporte
    FROM ticket_comentarios c LEFT JOIN usuarios u ON u.id = c.usuario_id LEFT JOIN roles ro ON ro.id = u.rol_id
    WHERE c.ticket_id = ? " . ($staff ? '' : 'AND c.interno = 0') . ' ORDER BY c.id', [$id]);
$adj = adjuntos('ticket', $id);
$otros = $t['equipo_id'] ? all("SELECT id, codigo, asunto, estado, creado FROM tickets WHERE equipo_id = ? AND id <> ? ORDER BY id DESC LIMIT 6", [$t['equipo_id'], $id]) : [];

function duracion(string $desde, ?string $hasta): string
{
    $s = (strtotime($hasta ?: now()) - strtotime($desde));
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    return $d ? "{$d} d {$h} h" : ($h ? "{$h} h {$m} min" : "{$m} min");
}
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('tickets') ?>"><i class="bi bi-arrow-left"></i> Tickets</a>
  <h2 class="h5 m-0 ms-1"><?= e($t['asunto']) ?></h2>
  <?= badge($t['estado']) ?> <?= badge($t['prioridad']) ?>
  <?php if (can('tickets', 'editar') || can('tickets', 'eliminar')): ?>
  <div class="ms-auto d-flex gap-2">
    <?php if (can('tickets', 'editar')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('ticket_form', ['id' => $id]) ?>"><i class="bi bi-pencil"></i> Editar</a><?php endif; ?>
    <?php if (can('tickets', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar este ticket y todo su historial? Esta acción no se puede deshacer."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="detail-grid">
  <div>
    <div class="thread">
      <div class="msg">
        <div class="msg-h"><strong><?= e($t['solicitante'] ?? 'Usuario') ?></strong> abrió el ticket · <?= fdate($t['creado'], true) ?></div>
        <div class="msg-b"><?= e($t['descripcion']) ?></div>
        <?= render_adjuntos($adj) ?>
      </div>
      <?php foreach ($coms as $c):
        $sistema = str_starts_with($c['mensaje'], 'Estado cambiado') || str_starts_with($c['mensaje'], 'Ticket asignado'); ?>
        <?php if ($sistema): ?>
          <div class="small muted ps-2"><i class="bi bi-arrow-repeat me-1"></i><?= e($c['nombre'] ?? '') ?>: <?= e($c['mensaje']) ?> · <?= fdate($c['creado'], true) ?></div>
        <?php else: ?>
          <div class="msg <?= $c['interno'] ? 'interno' : '' ?> <?= (int)$c['usuario_id'] === (int)$u['id'] ? 'mine' : '' ?>">
            <div class="msg-h"><strong><?= e($c['nombre'] ?? 'Usuario eliminado') ?></strong>
              <?= $c['es_soporte'] ? badge('Soporte', 'primary') : '' ?>
              <?= $c['interno'] ? badge('Nota interna', 'warning') : '' ?>
              <span class="ms-auto"><?= fdate($c['creado'], true) ?></span></div>
            <div class="msg-b"><?= e($c['mensaje']) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($t['estado'] !== 'Cerrado' || $staff): ?>
    <div class="panel mt-3">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="accion" value="responder">
        <label class="form-label">Responder</label>
        <textarea class="form-control mb-2" name="mensaje" rows="4" placeholder="<?= $staff ? 'Respuesta para el solicitante o nota interna…' : 'Agregue información o responda al técnico…' ?>"></textarea>
        <input class="form-control form-control-sm mb-3" type="file" name="adjuntos[]" multiple>
        <div class="d-flex flex-wrap gap-3 align-items-center">
          <?php if ($staff): ?>
            <div class="d-flex align-items-center gap-2"><span class="small muted">Estado:</span><select class="form-select form-select-sm" name="estado" style="width:auto"><?= options(array_filter(TICKET_ESTADOS, fn($e) => $puedeAprobar || $e === $t['estado'] || !in_array($e, ['Resuelto', 'Cerrado'], true)), $t['estado'], false) ?></select></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="interno" value="1" id="int"><label class="form-check-label small" for="int">Nota interna (no la ve el solicitante)</label></div>
          <?php endif; ?>
          <button class="btn btn-primary ms-auto"><i class="bi bi-send me-1"></i>Enviar</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if (!$staff && $t['estado'] === 'Resuelto'): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 mt-3">
      <span>Soporte marcó este ticket como resuelto. ¿Quedó solucionado?</span>
      <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="accion" value="cerrar"><button class="btn btn-sm btn-success">Sí, cerrar ticket</button></form>
      <span class="small">Si no, responda arriba y se reabrirá.</span>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Detalles</h3></div>
      <dl>
        <dt>Código</dt><dd><?= e($t['codigo']) ?></dd>
        <dt>Categoría</dt><dd><?= e($t['categoria']) ?></dd>
        <dt>Solicitante</dt><dd><?= e($t['solicitante'] ?? '—') ?><?php if ($staff && $t['solicitante_email']): ?><br><small class="muted"><?= e($t['solicitante_email']) ?><?= $t['solicitante_tel'] ? ' · ' . e($t['solicitante_tel']) : '' ?></small><?php endif; ?></dd>
        <dt>Empresa</dt><dd><?= e($t['empresa'] ?? '—') ?></dd>
        <dt>Asignado a</dt><dd><?= e($t['asignado'] ?? 'Sin asignar') ?></dd>
        <dt>Creado</dt><dd><?= fdate($t['creado'], true) ?></dd>
        <dt>Actualizado</dt><dd><?= fdate($t['actualizado'], true) ?></dd>
        <dt><?= $t['cerrado'] ? 'Tiempo de solución' : 'Tiempo abierto' ?></dt><dd><?= duracion($t['creado'], $t['cerrado']) ?></dd>
      </dl>
    </div>
    <?php if ($t['equipo_id']): ?>
    <div class="panel meta">
      <div class="panel-h"><h3>Equipo</h3><div class="actions"><a class="small" href="<?= url('equipo', ['id' => $t['equipo_id']]) ?>">Ver hoja de vida</a></div></div>
      <dl>
        <dt>Código</dt><dd><?= e($t['equipo_codigo']) ?></dd>
        <dt>Tipo</dt><dd><?= e($t['equipo_tipo']) ?></dd>
        <dt>Marca / modelo</dt><dd><?= e(trim(($t['equipo_marca'] ?? '') . ' ' . ($t['equipo_modelo'] ?? '')) ?: '—') ?></dd>
        <dt>Serial</dt><dd><?= e($t['equipo_serial'] ?: '—') ?></dd>
      </dl>
      <?php if ($otros): ?>
        <h6 class="sec mb-2">Otros tickets de este equipo</h6>
        <ul class="list-unstyled small mb-0">
        <?php foreach ($otros as $o): ?>
          <li class="mb-1"><a href="<?= url('ticket', ['id' => $o['id']]) ?>"><?= e($o['codigo']) ?></a> <?= e(mb_strimwidth($o['asunto'], 0, 40, '…')) ?> <?= badge($o['estado']) ?></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
