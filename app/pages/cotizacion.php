<?php
require_once APP_DIR . '/cotizaciones.php';
$id = (int)get('id', 0);
$c = cot_obtener($id);
if (!$c) not_found();
$items = cot_items($id);
$title = 'Cotización No. ' . $c['consecutivo'];
$em = cot_emisor();
$esProp = $c['formato'] === 'propuesta';

// PDF: vista en la pestaña o descarga
if (get('pdf')) cot_pdf($c, $items)->download(cot_archivo($c), get('pdf') === 'ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    if ($acc === 'enviar') {
        require_perm('cotizaciones', 'aprobar');
        $para = (string)post('para', '');
        $asunto = (string)post('asunto', '');
        $mensaje = (string)post('mensaje', '');
        if (!correo_activo('cot')) flash('El correo de cotizaciones no está configurado o está desactivado.', 'danger');
        elseif (!preg_match('/@/', $para)) flash('Escriba al menos un correo de destino.', 'danger');
        else {
            $pdf = cot_pdf($c, $items);
            $cuerpo = '<div style="white-space:pre-wrap;margin:0 0 12px">' . e($mensaje) . '</div>'
                . correo_ficha(['Cotización No.' => '<strong>' . (int)$c['consecutivo'] . '</strong>', 'Cliente' => e($c['cliente_nombre']), 'Fecha' => e(fdate($c['fecha'])),
                    'Total' => '<strong>' . e(money($c['total'])) . '</strong>', ($esProp ? 'Vigencia de tarifas' : 'Validez de la oferta') => e($c['validez'] ?? '')])
                . '<p style="margin:12px 0 0;color:#64748b;font-size:13px">Adjuntamos la cotización en formato PDF.</p>';
            $res = enviar_correo($para, $asunto, correo_plantilla('Cotización No. ' . $c['consecutivo'], $cuerpo), [
                'cuenta' => 'cot', 'motivo' => 'cotizacion',
                'adjuntos' => [['nombre' => cot_archivo($c), 'tipo' => 'application/pdf', 'datos' => $pdf->output()]],
            ]);
            if ($res['ok']) {
                $d = ['enviada' => now(), 'enviada_a' => mb_substr($para, 0, 500), 'actualizado' => now()];
                if ($c['estado'] === 'Borrador') $d['estado'] = 'Enviada';
                update('cotizaciones', $d, $id);
                flash('Cotización enviada a ' . $para . '.');
            } else flash('No se pudo enviar: ' . $res['error'], 'danger');
        }
    } elseif ($acc === 'estado' && in_array(post('estado'), COT_ESTADOS, true)) {
        require_perm('cotizaciones', 'aprobar');
        update('cotizaciones', ['estado' => post('estado'), 'actualizado' => now()], $id);
        flash('Estado actualizado a «' . post('estado') . '».');
    } elseif ($acc === 'eliminar') {
        require_perm('cotizaciones', 'eliminar');
        q('DELETE FROM cotizaciones WHERE id = ?', [$id]);
        flash('Cotización No. ' . $c['consecutivo'] . ' eliminada.');
        redirect(url('cotizaciones'));
    }
    redirect(url('cotizacion', ['id' => $id]));
}

$activo = correo_activo('cot');
$asuntoDef = 'Cotización No. ' . $c['consecutivo'] . ' - ' . $em['nombre'];
$msgDef = "Cordial saludo" . ($c['atencion'] ? ', ' . $c['atencion'] : '') . ".\n\nEn atención a su solicitud, adjuntamos la cotización No. " . $c['consecutivo'] . ". Quedamos atentos a cualquier inquietud.\n\nAtentamente,\n" . $em['firmante'] . "\n" . $em['cargo'] . "\n" . $em['nombre'] . "\n" . $em['telefonos'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('cotizaciones') ?>"><i class="bi bi-arrow-left"></i> Cotizaciones</a>
  <h2 class="h5 m-0 ms-1">No. <?= (int)$c['consecutivo'] ?> · <?= e($c['cliente_nombre']) ?></h2>
  <?= badge($c['estado']) ?> <?= badge(COT_FORMATOS[$c['formato']] ?? $c['formato'], 'info') ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= url('cotizacion', ['id' => $id, 'pdf' => 'ver']) ?>"><i class="bi bi-eye me-1"></i>Ver PDF</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('cotizacion', ['id' => $id, 'pdf' => 'bajar']) ?>"><i class="bi bi-download me-1"></i>Descargar</a>
    <?php if (can('cotizaciones', 'crear')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('cotizacion_form', ['copiar' => $id]) ?>"><i class="bi bi-copy me-1"></i>Duplicar</a><?php endif; ?>
    <?php if (can('cotizaciones', 'editar')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('cotizacion_form', ['id' => $id]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
    <?php if (can('cotizaciones', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar la cotización No. <?= (int)$c['consecutivo'] ?>?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div>
    <div class="panel">
      <div class="panel-h"><h3><?= $esProp ? 'Oferta comercial' : 'Ítems' ?></h3></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th class="c">#</th><?php if (!$esProp): ?><th>Referencia</th><?php endif; ?><th><?= $esProp ? 'Ítem' : 'Descripción' ?></th><?php if (!$esProp): ?><th>Marca</th><th>Unidad</th><?php endif; ?><th class="c">Cant</th><th class="r">Valor unit.</th><th class="r">Valor total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $k => $it): ?>
          <tr><td class="c muted"><?= $k + 1 ?></td><?php if (!$esProp): ?><td><?= e($it['referencia'] ?: '—') ?></td><?php endif; ?><td><?= e($it['descripcion']) ?></td>
            <?php if (!$esProp): ?><td><?= e($it['marca'] ?: '—') ?></td><td><?= e($it['unidad'] ?: '—') ?></td><?php endif; ?>
            <td class="c"><?= e(cot_num($it['cantidad'])) ?></td><td class="r nowrap"><?= money($it['valor_unitario']) ?></td><td class="r nowrap"><?= money($it['valor_total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php if ((float)$c['mano_obra'] > 0): ?><tr><td colspan="<?= $esProp ? 4 : 7 ?>" class="r muted">Mano de obra</td><td class="r"><?= money($c['mano_obra']) ?></td></tr><?php endif; ?>
          <?php if ((float)$c['iva'] > 0): ?><tr><td colspan="<?= $esProp ? 4 : 7 ?>" class="r muted">Subtotal</td><td class="r"><?= money($c['subtotal'] + $c['mano_obra']) ?></td></tr>
            <tr><td colspan="<?= $esProp ? 4 : 7 ?>" class="r muted">IVA <?= e(cot_num($c['iva_pct'])) ?>%</td><td class="r"><?= money($c['iva']) ?></td></tr><?php endif; ?>
          <tr><td colspan="<?= $esProp ? 4 : 7 ?>" class="r fw-bold">TOTAL</td><td class="r fw-bold"><?= money($c['total']) ?></td></tr>
        </tfoot>
      </table></div>
    </div>
    <?php if ($esProp): ?>
    <div class="panel">
      <div class="panel-h"><h3>1. Descripción</h3></div><div class="msg-b mb-3"><?= e($c['descripcion'] ?: '—') ?></div>
      <div class="panel-h"><h3>2. Alcances de la propuesta</h3></div><div class="msg-b"><?= e($c['alcances'] ?: '—') ?></div>
    </div>
    <?php endif; ?>

    <?php if (can('cotizaciones', 'aprobar')): ?>
    <div class="panel" id="enviar">
      <div class="panel-h"><h3><i class="bi bi-envelope me-2"></i>Enviar por correo</h3>
        <div class="actions"><?= $activo ? '<span class="small muted">Se envía con el PDF adjunto desde ' . e(correo_config('cot')['desde']) . '</span>' : '<span class="small text-danger">Correo de cotizaciones sin configurar' . (can('cotizaciones', 'administrar') ? ' · <a href="' . url('cotizacion_config') . '">Configurar</a>' : '') . '</span>' ?></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="enviar">
        <div class="form-grid">
          <div class="full"><label class="form-label req">Para (varios separados por coma)</label><input class="form-control" name="para" value="<?= e($c['cliente_email']) ?>" required placeholder="cliente@empresa.com"></div>
          <div class="full"><label class="form-label">Asunto</label><input class="form-control" name="asunto" value="<?= e($asuntoDef) ?>" maxlength="200"></div>
          <div class="full"><label class="form-label">Mensaje</label><textarea class="form-control" name="mensaje" rows="8"><?= e($msgDef) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" <?= $activo ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i><?= $c['enviada'] ? 'Volver a enviar' : 'Enviar cotización' ?></button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div>
    <div class="panel meta">
      <div class="panel-h"><h3>Datos</h3></div>
      <dl>
        <dt>Consecutivo</dt><dd><strong><?= (int)$c['consecutivo'] ?></strong></dd>
        <dt>Fecha</dt><dd><?= fdate($c['fecha']) ?></dd>
        <dt>Cliente</dt><dd><?= e($c['cliente_nombre']) ?></dd>
        <dt>NIT / C.C.</dt><dd><?= e($c['cliente_nit'] ?: '—') ?></dd>
        <dt>Contacto</dt><dd><?= e($c['atencion'] ?: '—') ?></dd>
        <dt>Dirección</dt><dd><?= e($c['cliente_direccion'] ?: '—') ?></dd>
        <dt>Teléfono</dt><dd><?= e($c['cliente_telefono'] ?: '—') ?></dd>
        <dt>Correo</dt><dd><?= e($c['cliente_email'] ?: '—') ?></dd>
        <dt><?= $esProp ? 'Vigencia' : 'Validez' ?></dt><dd><?= e($c['validez'] ?: '—') ?></dd>
        <dt>Entrega</dt><dd><?= e($c['tiempo_entrega'] ?: '—') ?></dd>
        <dt>Pago</dt><dd><?= e($c['forma_pago'] ?: '—') ?></dd>
        <dt>Creada por</dt><dd><?= e($c['creador'] ?: '—') ?><br><small class="muted"><?= fdate($c['creado'], true) ?></small></dd>
        <dt>Último envío</dt><dd><?= $c['enviada'] ? fdate($c['enviada'], true) . '<br><small class="muted">' . e($c['enviada_a']) . '</small>' : '—' ?></dd>
        <?php if ($c['notas']): ?><dt>Notas</dt><dd><?= e($c['notas']) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if (can('cotizaciones', 'aprobar')): ?>
    <div class="panel">
      <div class="panel-h"><h3>Estado</h3></div>
      <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="estado">
        <select class="form-select form-select-sm" name="estado"><?= options(COT_ESTADOS, $c['estado'], false) ?></select>
        <button class="btn btn-sm btn-outline-primary">Cambiar</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
