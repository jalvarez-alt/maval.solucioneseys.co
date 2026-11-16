<?php
require_once APP_DIR . '/informes.php';
$id = (int)get('id', 0);
$i = inf_obtener($id);
if (!$i) not_found();
$t = INF_TIPOS[$i['tipo']] ?? INF_TIPOS['software'];
$filas = inf_filas($id);
$datos = inf_datos($i);
$cod = inf_codigo($i);
$title = 'Informe ' . $cod;
$em = cot_emisor();

if (get('pdf')) inf_pdf($i, $filas)->download(inf_archivo($i), get('pdf') === 'ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'finalizar' && $i['estado'] === 'Borrador') {
        require_perm('informes', 'aprobar');
        if (!$filas['pruebas'] && !$filas['equipos']) flash('Agregue al menos un equipo o una prueba antes de finalizar el informe.', 'warning');
        else { update('informes', ['estado' => 'Finalizado', 'fecha_fin' => $i['fecha_fin'] ?: today(), 'actualizado' => now()], $id); flash('Informe ' . $cod . ' finalizado.'); }
    } elseif ($acc === 'reabrir' && $i['estado'] === 'Finalizado') {
        require_perm('informes', 'aprobar');
        update('informes', ['estado' => 'Borrador', 'actualizado' => now()], $id);
        flash('Informe reabierto: vuelve a quedar en borrador.');
    } elseif ($acc === 'enviar') {
        require_perm('informes', 'aprobar');
        $para = (string)post('para', ''); $asunto = (string)post('asunto', ''); $mensaje = (string)post('mensaje', '');
        if (!correo_activo('cot')) flash('El correo de cotizaciones no está configurado o está desactivado.', 'danger');
        elseif (!preg_match('/@/', $para)) flash('Escriba al menos un correo de destino.', 'danger');
        else {
            $pdf = inf_pdf($i, $filas);
            $cuerpo = '<div style="white-space:pre-wrap;margin:0 0 12px">' . e($mensaje) . '</div>'
                . correo_ficha(['Informe' => '<strong>' . e($cod) . '</strong>', 'Servicio' => e($t['nombre']), 'Cliente' => e($i['cliente_nombre']), 'Sede' => e($i['sede'] ?? ''),
                    'Fecha' => e(fdate($i['fecha_fin'] ?: $i['fecha_inicio'])), 'Técnico' => e($i['tecnico_nombre'] ?? '')])
                . '<p style="margin:12px 0 0;color:#64748b;font-size:13px">Adjuntamos el informe del servicio en formato PDF.</p>';
            $res = enviar_correo($para, $asunto, correo_plantilla('Informe de servicio ' . $cod, $cuerpo), [
                'cuenta' => 'cot', 'motivo' => 'informe',
                'adjuntos' => [['nombre' => inf_archivo($i), 'tipo' => 'application/pdf', 'datos' => $pdf->output()]],
            ]);
            if ($res['ok']) { update('informes', ['enviado' => now(), 'enviado_a' => mb_substr($para, 0, 500), 'actualizado' => now()], $id); flash('Informe enviado a ' . $para . '.'); }
            else flash('No se pudo enviar: ' . $res['error'], 'danger');
        }
    } elseif ($acc === 'eliminar') {
        require_perm('informes', 'eliminar');
        foreach (adjuntos('informe', $id) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
        q("DELETE FROM adjuntos WHERE entidad = 'informe' AND entidad_id = ?", [$id]);
        q('DELETE FROM informes WHERE id = ?', [$id]);
        flash('Informe ' . $cod . ' eliminado.');
        redirect(url('informes'));
    }
    redirect(url('informe', ['id' => $id]));
}

$activo = correo_activo('cot');
$asuntoDef = 'Informe de servicio ' . $cod . ' - ' . $t['nombre'] . ' - ' . $em['nombre'];
$msgDef = "Cordial saludo" . ($i['contacto'] ? ', ' . $i['contacto'] : '') . ".\n\nAdjuntamos el informe del servicio realizado: " . mb_strtolower($t['nombre']) . ". Quedamos atentos a cualquier inquietud.\n\nAtentamente,\n" . $em['firmante'] . "\n" . $em['nombre'];
$fotos = adjuntos('informe', $id);
$editable = $i['estado'] === 'Borrador' ? can('informes', 'editar') : can('informes', 'aprobar');
$colsEq = $t['cols'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('informes') ?>"><i class="bi bi-arrow-left"></i> Informes</a>
  <h2 class="h5 m-0 ms-1"><?= e($cod) ?> · <?= e($i['cliente_nombre']) ?></h2>
  <?= badge($i['estado']) ?> <span class="badge text-bg-light border"><i class="bi bi-<?= e($t['icono']) ?> me-1"></i><?= e($t['corto']) ?></span>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= url('informe', ['id' => $id, 'pdf' => 'ver']) ?>"><i class="bi bi-eye me-1"></i>Ver PDF</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('informe', ['id' => $id, 'pdf' => 'bajar']) ?>"><i class="bi bi-download me-1"></i>Descargar</a>
    <?php if ($i['estado'] === 'Finalizado' && can('porfacturar', 'crear')): ?><a class="btn btn-sm btn-outline-success" href="<?= url('por_facturar_form', ['informe' => $id]) ?>"><i class="bi bi-receipt-cutoff me-1"></i>Pasar a por facturar</a><?php endif; ?>
    <?php if ($i['estado'] === 'Borrador' && can('informes', 'aprobar')): ?><form method="post" data-confirm="¿Finalizar el informe? Después solo se podrá modificar reabriéndolo."><?= csrf_field() ?><input type="hidden" name="accion" value="finalizar"><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Finalizar</button></form><?php endif; ?>
    <?php if ($i['estado'] === 'Finalizado' && can('informes', 'aprobar')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="reabrir"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-unlock me-1"></i>Reabrir</button></form><?php endif; ?>
    <?php if ($editable): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('informe_form', ['id' => $id]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
    <?php if (can('informes', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar el informe <?= e($cod) ?> y sus fotos?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?php if (trim((string)$i['resumen']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Resumen del trabajo</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($i['resumen']) ?></div></div><?php endif; ?>
    <?php if (trim((string)$i['actividades']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Actividades realizadas</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($i['actividades']) ?></div></div><?php endif; ?>
    <?php if ($filas['equipos']): ?>
    <div class="panel"><div class="panel-h"><h3><?= e($t['equipos']) ?></h3></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><?php foreach ($colsEq as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($filas['equipos'] as $f): ?><tr><?php for ($k = 1; $k <= 6; $k++): ?><td><?= $k === 6 && $f['c6'] ? badge($f['c6'], str_starts_with($f['c6'], 'Con') || $f['c6'] === 'Pendiente' ? 'warning' : 'success') : e($f['c' . $k] ?: '—') ?></td><?php endfor; ?></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php if ($filas['materiales']): ?>
    <div class="panel"><div class="panel-h"><h3>Materiales y equipos entregados</h3></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ítem</th><th>Marca</th><th>Serial / referencia</th><th class="c">Cant.</th></tr></thead><tbody>
      <?php foreach ($filas['materiales'] as $f): ?><tr><td><?= e($f['c1'] ?: '—') ?></td><td><?= e($f['c2'] ?: '—') ?></td><td><?= e($f['c3'] ?: '—') ?></td><td class="c"><?= e($f['c4'] ?: '—') ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php $hayDatos = array_filter(array_intersect_key($datos, $t['datos']), fn($v) => trim((string)$v) !== ''); if ($hayDatos): ?>
    <div class="panel"><div class="panel-h"><h3>Datos técnicos</h3></div>
      <dl class="mb-0"><?php foreach ($t['datos'] as $k => [$lbl, $_]): if (trim((string)($datos[$k] ?? '')) === '') continue; ?><dt class="small muted"><?= e($lbl) ?></dt><dd style="white-space:pre-wrap"><?= e($datos[$k]) ?></dd><?php endforeach; ?></dl></div>
    <?php endif; ?>
    <?php if ($filas['pruebas']): ?>
    <div class="panel"><div class="panel-h"><h3>Pruebas y resultados</h3></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Prueba</th><th>Resultado</th><th>Observación</th></tr></thead><tbody>
      <?php foreach ($filas['pruebas'] as $f): ?><tr><td><?= e($f['c1'] ?: '—') ?></td><td><?= $f['c2'] ? badge($f['c2'], $f['c2'] === 'OK' ? 'success' : ($f['c2'] === 'Falla' ? 'danger' : ($f['c2'] === 'No aplica' ? 'secondary' : 'warning'))) : '—' ?></td><td><?= e($f['c3'] ?: '') ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php if (trim((string)$i['recomendaciones']) !== ''): ?><div class="panel"><div class="panel-h"><h3>Recomendaciones y pendientes</h3></div><div class="msg-b" style="white-space:pre-wrap"><?= e($i['recomendaciones']) ?></div></div><?php endif; ?>
    <?php if ($fotos): ?>
    <div class="panel"><div class="panel-h"><h3><i class="bi bi-images me-2"></i>Evidencia fotográfica</h3></div>
      <div class="d-flex flex-wrap gap-3">
      <?php foreach ($fotos as $a): $img = in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); ?>
        <a class="text-decoration-none" href="archivo.php?id=<?= (int)$a['id'] ?>" target="_blank" style="width:160px">
          <?php if ($img): ?><img src="archivo.php?id=<?= (int)$a['id'] ?>" alt="" style="width:160px;height:110px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0"><?php else: ?><i class="bi bi-paperclip"></i><?php endif; ?>
          <div class="small text-truncate mt-1"><?= e($a['nombre_original']) ?></div>
        </a>
      <?php endforeach; ?>
      </div></div>
    <?php endif; ?>

    <?php if (can('informes', 'aprobar')): ?>
    <div class="panel" id="enviar">
      <div class="panel-h"><h3><i class="bi bi-envelope me-2"></i>Enviar por correo</h3>
        <div class="actions"><?= $activo ? '<span class="small muted">Se envía con el PDF adjunto desde ' . e(correo_config('cot')['desde']) . '</span>' : '<span class="small text-danger">Correo de cotizaciones sin configurar</span>' ?></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="enviar">
        <div class="form-grid">
          <div class="full"><label class="form-label req">Para (varios separados por coma)</label><input class="form-control" name="para" value="<?= e($i['cliente_email']) ?>" required placeholder="cliente@empresa.com"></div>
          <div class="full"><label class="form-label">Asunto</label><input class="form-control" name="asunto" value="<?= e($asuntoDef) ?>" maxlength="200"></div>
          <div class="full"><label class="form-label">Mensaje</label><textarea class="form-control" name="mensaje" rows="7"><?= e($msgDef) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" <?= $activo ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i><?= $i['enviado'] ? 'Volver a enviar' : 'Enviar informe' ?></button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Datos</h3></div>
      <dl>
        <dt>Informe</dt><dd><strong><?= e($cod) ?></strong></dd>
        <dt>Servicio</dt><dd><?= e($t['nombre']) ?></dd>
        <dt>Cliente</dt><dd><?= e($i['cliente_nombre']) ?></dd>
        <dt>NIT / C.C.</dt><dd><?= e($i['cliente_nit'] ?: '—') ?></dd>
        <dt>Contacto</dt><dd><?= e($i['contacto'] ?: '—') ?></dd>
        <dt>Correo</dt><dd><?= e($i['cliente_email'] ?: '—') ?></dd>
        <dt>Sede</dt><dd><?= e($i['sede'] ?: '—') ?></dd>
        <dt>Técnico</dt><dd><?= e($i['tecnico_nombre'] ?: '—') ?></dd>
        <dt>Inicio</dt><dd><?= fdate($i['fecha_inicio']) ?></dd>
        <dt>Finalización</dt><dd><?= $i['fecha_fin'] ? fdate($i['fecha_fin']) : '—' ?></dd>
        <?php if ($i['cotizacion_id']): ?><dt>Cotización</dt><dd><a href="<?= url('cotizacion', ['id' => $i['cotizacion_id']]) ?>"><?= e($i['referencia'] ?: 'Ver cotización') ?></a></dd>
        <?php elseif ($i['referencia']): ?><dt>Referencia</dt><dd><?= e($i['referencia']) ?></dd><?php endif; ?>
        <dt>Recibe</dt><dd><?= e($i['firma_nombre'] ?: '—') ?><?= $i['firma_cargo'] ? '<br><small class="muted">' . e($i['firma_cargo']) . '</small>' : '' ?></dd>
        <dt>Creado por</dt><dd><?= e($i['creador'] ?: '—') ?><br><small class="muted"><?= fdate($i['creado'], true) ?></small></dd>
        <dt>Último envío</dt><dd><?= $i['enviado'] ? fdate($i['enviado'], true) . '<br><small class="muted">' . e($i['enviado_a']) . '</small>' : '—' ?></dd>
      </dl>
    </div>
  </div>
</div>
