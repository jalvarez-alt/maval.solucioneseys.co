<?php
require_once APP_DIR . '/ordenes.php';
$id = (int)get('id', 0);
$o = ord_obtener($id);
if (!$o) not_found();
$filas = ord_filas($id);
$cod = ord_codigo($o);
$title = 'Orden ' . $cod;
$em = cot_emisor();

if (get('pdf')) ord_pdf($o, $filas)->download(ord_archivo($o), get('pdf') === 'ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'iniciar' && in_array($o['estado'], ['Pendiente', 'Programada'], true)) {
        require_perm('ordenes', 'editar');
        update('ordenes', ['estado' => 'En curso', 'fecha_inicio' => $o['fecha_inicio'] ?: now(), 'actualizado' => now()], $id);
        flash('Orden ' . $cod . ' en curso.');
    } elseif ($acc === 'completar' && in_array($o['estado'], ORD_ABIERTAS, true)) {
        require_perm('ordenes', 'aprobar');
        if (trim((string)$o['trabajo_realizado']) === '') flash('Registre el trabajo realizado (Editar) antes de completar la orden.', 'warning');
        else {
            update('ordenes', ['estado' => 'Completada', 'fecha_inicio' => $o['fecha_inicio'] ?: now(), 'fecha_fin' => now(), 'actualizado' => now()], $id);
            if ($o['ticket_id']) insert('ticket_comentarios', ['ticket_id' => (int)$o['ticket_id'], 'usuario_id' => (int)user()['id'], 'mensaje' => 'Orden de servicio ' . $cod . ' completada.', 'interno' => 1, 'creado' => now()]);
            flash('Orden ' . $cod . ' completada.');
        }
    } elseif ($acc === 'cancelar' && in_array($o['estado'], ORD_ABIERTAS, true)) {
        require_perm('ordenes', 'aprobar');
        update('ordenes', ['estado' => 'Cancelada', 'actualizado' => now()], $id);
        flash('Orden ' . $cod . ' cancelada.');
    } elseif ($acc === 'reabrir' && in_array($o['estado'], ['Completada', 'Cancelada'], true)) {
        require_perm('ordenes', 'aprobar');
        update('ordenes', ['estado' => 'En curso', 'fecha_fin' => null, 'actualizado' => now()], $id);
        flash('Orden reabierta: vuelve a estar en curso.');
    } elseif ($acc === 'enviar') {
        require_perm('ordenes', 'aprobar');
        $para = (string)post('para', ''); $asunto = (string)post('asunto', ''); $mensaje = (string)post('mensaje', '');
        if (!correo_activo('cot')) flash('El correo de cotizaciones no está configurado o está desactivado.', 'danger');
        elseif (!preg_match('/@/', $para)) flash('Escriba al menos un correo de destino.', 'danger');
        else {
            $pdf = ord_pdf($o, $filas);
            $cuerpo = '<div style="white-space:pre-wrap;margin:0 0 12px">' . e($mensaje) . '</div>'
                . correo_ficha(['Orden' => '<strong>' . e($cod) . '</strong>', 'Servicio' => e($o['tipo']), 'Cliente' => e($o['cliente_nombre']), 'Sede' => e($o['sede'] ?? ''),
                    'Fecha' => e(fdate($o['fecha_fin'] ?: $o['fecha_programada'] ?: $o['creado'])), 'Técnico' => e($o['tecnico_nombre'] ?? '')])
                . '<p style="margin:12px 0 0;color:#64748b;font-size:13px">Adjuntamos la orden de servicio en formato PDF.</p>';
            $res = enviar_correo($para, $asunto, correo_plantilla('Orden de servicio ' . $cod, $cuerpo), [
                'cuenta' => 'cot', 'motivo' => 'orden',
                'adjuntos' => [['nombre' => ord_archivo($o), 'tipo' => 'application/pdf', 'datos' => $pdf->output()]],
            ]);
            if ($res['ok']) { update('ordenes', ['enviado' => now(), 'enviado_a' => mb_substr($para, 0, 500), 'actualizado' => now()], $id); flash('Orden enviada a ' . $para . '.'); }
            else flash('No se pudo enviar: ' . $res['error'], 'danger');
        }
    } elseif ($acc === 'eliminar') {
        require_perm('ordenes', 'eliminar');
        ord_eliminar($id);
        flash('Orden ' . $cod . ' eliminada.');
        redirect(url('ordenes'));
    }
    redirect(url('orden', ['id' => $id]));
}

$activo = correo_activo('cot');
$asuntoDef = 'Orden de servicio ' . $cod . ' - ' . $o['tipo'] . ' - ' . $em['nombre'];
$msgDef = "Cordial saludo" . ($o['contacto'] ? ', ' . $o['contacto'] : '') . ".\n\nAdjuntamos la orden de servicio " . $cod . " (" . mb_strtolower($o['tipo']) . "). Quedamos atentos a cualquier inquietud.\n\nAtentamente,\n" . $em['firmante'] . "\n" . $em['nombre'];
$antes = ord_fotos($id, 'antes'); $despues = ord_fotos($id, 'despues');
$abierta = in_array($o['estado'], ORD_ABIERTAS, true);
$editable = $abierta ? can('ordenes', 'editar') : can('ordenes', 'aprobar');
$horas = ord_total_horas($filas['horas']);
$galeria = function (array $fotos) { ?>
  <div class="d-flex flex-wrap gap-3">
  <?php foreach ($fotos as $a): $img = in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); ?>
    <a class="text-decoration-none" href="archivo.php?id=<?= (int)$a['id'] ?>" target="_blank" style="width:160px">
      <?php if ($img): ?><img src="archivo.php?id=<?= (int)$a['id'] ?>" alt="" style="width:160px;height:110px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0"><?php else: ?><i class="bi bi-paperclip"></i><?php endif; ?>
      <div class="small text-truncate mt-1"><?= e($a['nombre_original']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
<?php };
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('ordenes') ?>"><i class="bi bi-arrow-left"></i> Órdenes</a>
  <h2 class="h5 m-0 ms-1"><?= e($cod) ?> · <?= e($o['cliente_nombre']) ?></h2>
  <?= ord_badge($o['estado']) ?> <?= $o['prioridad'] !== 'Normal' ? ord_prioridad_badge($o['prioridad']) : '' ?> <span class="badge text-bg-light border"><i class="bi bi-tools me-1"></i><?= e($o['tipo']) ?></span>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= url('orden', ['id' => $id, 'pdf' => 'ver']) ?>"><i class="bi bi-eye me-1"></i>Ver PDF</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('orden', ['id' => $id, 'pdf' => 'bajar']) ?>"><i class="bi bi-download me-1"></i>Descargar</a>
    <?php if ($o['estado'] === 'Completada' && can('informes', 'crear') && !$o['informe_id']): ?>
      <div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-clipboard-check me-1"></i>Crear informe</button><ul class="dropdown-menu dropdown-menu-end">
        <?php foreach (INF_TIPOS as $k => $tp): ?><li><a class="dropdown-item" href="<?= url('informe_form', ['tipo' => $k, 'orden' => $id]) ?>"><i class="bi bi-<?= e($tp['icono']) ?> me-2"></i><?= e($tp['nombre']) ?></a></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (in_array($o['estado'], ['Pendiente', 'Programada'], true) && can('ordenes', 'editar')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar"><button class="btn btn-sm btn-primary"><i class="bi bi-play-fill me-1"></i>Iniciar</button></form><?php endif; ?>
    <?php if ($abierta && can('ordenes', 'aprobar')): ?>
      <form method="post" data-confirm="¿Marcar la orden como completada?"><?= csrf_field() ?><input type="hidden" name="accion" value="completar"><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Completar</button></form>
      <form method="post" data-confirm="¿Cancelar la orden <?= e($cod) ?>?"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Cancelar</button></form>
    <?php endif; ?>
    <?php if (!$abierta && can('ordenes', 'aprobar')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="reabrir"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-unlock me-1"></i>Reabrir</button></form><?php endif; ?>
    <?php if ($editable): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('orden_form', ['id' => $id]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
    <?php if (can('ordenes', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar la orden <?= e($cod) ?> y sus fotos?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?php if (trim((string)$o['descripcion']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Trabajo a realizar</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($o['descripcion']) ?></div></div><?php endif; ?>
    <?php if (trim((string)$o['trabajo_realizado']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Trabajo realizado</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($o['trabajo_realizado']) ?></div></div><?php endif; ?>
    <?php if ($filas['materiales']): ?>
    <div class="panel"><div class="panel-h"><h3>Materiales y repuestos usados</h3></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th class="c">Cant.</th><th>Descripción</th><th>Marca / referencia</th><th>Observación</th></tr></thead><tbody>
      <?php foreach ($filas['materiales'] as $f): ?><tr><td class="c"><?= e($f['c1'] ?: '—') ?></td><td><?= e($f['c2'] ?: '—') ?></td><td><?= e($f['c3'] ?: '—') ?></td><td><?= e($f['c4'] ?: '') ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php if ($filas['horas']): ?>
    <div class="panel"><div class="panel-h"><h3>Horas de trabajo</h3><div class="actions"><strong><?= ord_horas_txt($horas) ?> h</strong></div></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Fecha</th><th>Técnico</th><th class="c">Horas</th><th>Detalle</th></tr></thead><tbody>
      <?php foreach ($filas['horas'] as $f): ?><tr><td class="nowrap"><?= e($f['c1'] ?: '—') ?></td><td><?= e($f['c2'] ?: '—') ?></td><td class="c"><?= e($f['c3'] ?: '—') ?></td><td><?= e($f['c4'] ?: '') ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php if (trim((string)$o['observaciones']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Observaciones y pendientes</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($o['observaciones']) ?></div></div><?php endif; ?>
    <?php if ($antes): ?><div class="panel"><div class="panel-h"><h3><i class="bi bi-images me-2"></i>Fotos antes</h3></div><?php $galeria($antes); ?></div><?php endif; ?>
    <?php if ($despues): ?><div class="panel"><div class="panel-h"><h3><i class="bi bi-images me-2"></i>Fotos después</h3></div><?php $galeria($despues); ?></div><?php endif; ?>

    <?php if (can('ordenes', 'aprobar')): ?>
    <div class="panel" id="enviar">
      <div class="panel-h"><h3><i class="bi bi-envelope me-2"></i>Enviar por correo</h3>
        <div class="actions"><?= $activo ? '<span class="small muted">Se envía con el PDF adjunto desde ' . e(correo_config('cot')['desde']) . '</span>' : '<span class="small text-danger">Correo de cotizaciones sin configurar</span>' ?></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="enviar">
        <div class="form-grid">
          <div class="full"><label class="form-label req">Para (varios separados por coma)</label><input class="form-control" name="para" value="<?= e($o['cliente_email']) ?>" required placeholder="cliente@empresa.com"></div>
          <div class="full"><label class="form-label">Asunto</label><input class="form-control" name="asunto" value="<?= e($asuntoDef) ?>" maxlength="200"></div>
          <div class="full"><label class="form-label">Mensaje</label><textarea class="form-control" name="mensaje" rows="7"><?= e($msgDef) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" <?= $activo ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i><?= $o['enviado'] ? 'Volver a enviar' : 'Enviar orden' ?></button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Datos</h3></div>
      <dl>
        <dt>Orden</dt><dd><strong><?= e($cod) ?></strong></dd>
        <dt>Servicio</dt><dd><?= e($o['tipo']) ?></dd>
        <dt>Cliente</dt><dd><?= e($o['cliente_nombre']) ?></dd>
        <dt>NIT / C.C.</dt><dd><?= e($o['cliente_nit'] ?: '—') ?></dd>
        <dt>Contacto</dt><dd><?= e($o['contacto'] ?: '—') ?><?= $o['telefono'] ? '<br><small class="muted">' . e($o['telefono']) . '</small>' : '' ?></dd>
        <dt>Correo</dt><dd><?= e($o['cliente_email'] ?: '—') ?></dd>
        <dt>Sede</dt><dd><?= e($o['sede'] ?: '—') ?></dd>
        <dt>Técnico</dt><dd><?= e($o['tecnico_nombre'] ?: 'Sin asignar') ?></dd>
        <dt>Programada</dt><dd><?= $o['fecha_programada'] ? fdate($o['fecha_programada']) . ($o['hora_programada'] ? ' · ' . e($o['hora_programada']) : '') : '—' ?></dd>
        <dt>Inicio</dt><dd><?= $o['fecha_inicio'] ? fdate($o['fecha_inicio'], true) : '—' ?></dd>
        <dt>Finalización</dt><dd><?= $o['fecha_fin'] ? fdate($o['fecha_fin'], true) : '—' ?></dd>
        <?php if ($o['ticket_id']): ?><dt>Ticket</dt><dd><a href="<?= url('ticket', ['id' => $o['ticket_id']]) ?>"><?= e($o['ticket_codigo']) ?></a></dd><?php endif; ?>
        <?php if ($o['cotizacion_id']): ?><dt>Cotización</dt><dd><a href="<?= url('cotizacion', ['id' => $o['cotizacion_id']]) ?>"><?= e($o['referencia'] ?: 'Ver cotización') ?></a></dd>
        <?php elseif ($o['referencia'] && !$o['ticket_id']): ?><dt>Referencia</dt><dd><?= e($o['referencia']) ?></dd><?php endif; ?>
        <?php if ($o['informe_id']): ?><dt>Informe</dt><dd><a href="<?= url('informe', ['id' => $o['informe_id']]) ?>">IS-<?= str_pad((string)$o['informe_consecutivo'], 4, '0', STR_PAD_LEFT) ?></a></dd><?php endif; ?>
        <dt>Recibe</dt><dd><?= e($o['firma_nombre'] ?: '—') ?><?= $o['firma_cargo'] ? '<br><small class="muted">' . e($o['firma_cargo']) . '</small>' : '' ?></dd>
        <dt>Creada por</dt><dd><?= e($o['creador'] ?: '—') ?><br><small class="muted"><?= fdate($o['creado'], true) ?></small></dd>
        <dt>Último envío</dt><dd><?= $o['enviado'] ? fdate($o['enviado'], true) . '<br><small class="muted">' . e($o['enviado_a']) . '</small>' : '—' ?></dd>
      </dl>
    </div>
  </div>
</div>
