<?php
require_once APP_DIR . '/cotizaciones.php';
$id = (int)get('id', 0);
$c = cot_obtener($id);
if (!$c) not_found();
$items = cot_items($id);
$title = 'Cotización No. ' . $c['consecutivo'];
$em = cot_emisor();
$esProp = $c['formato'] === 'propuesta';
// Una o dos ofertas (solo las propuestas pueden tener dos)
$ofertas = [1 => ['titulo' => $c['titulo1'] ?? '', 'mano' => $c['mano_obra'], 'sumar' => (int)($c['sumar1'] ?? 1), 'sub' => $c['subtotal'], 'iva' => $c['iva'], 'total' => $c['total'], 'items' => []]];
foreach ($items as $it) {
    if ((int)$it['oferta'] === 2 && !isset($ofertas[2])) $ofertas[2] = ['titulo' => $c['titulo2'] ?? '', 'mano' => $c['mano_obra2'], 'sumar' => (int)($c['sumar2'] ?? 1), 'sub' => $c['subtotal2'], 'iva' => $c['iva2'], 'total' => $c['total2'], 'items' => []];
    $ofertas[(int)$it['oferta'] === 2 ? 2 : 1]['items'][] = $it;
}

// PDF: vista en la pestaña o descarga
if (get('pdf')) cot_pdf($c, $items)->download(cot_archivo($c), get('pdf') === 'ver');

// Excel con todos los ítems (solo mientras la cotización es un borrador). Mismas columnas que la plantilla de importación.
if (get('xlsx')) {
    if ($c['estado'] !== 'Borrador') { flash('Los ítems solo se pueden descargar en Excel mientras la cotización está en borrador.', 'warning'); redirect(url('cotizacion', ['id' => $id])); }
    require_once APP_DIR . '/lib/xlsx.php';
    $dos = count(array_unique(array_map(fn($i) => (int)$i['oferta'], $items))) > 1;
    $filas = [];
    foreach ($items as $it) {
        $fila = [$it['referencia'], $it['descripcion'], $it['marca'], $it['unidad'], (float)$it['cantidad'], (float)$it['valor_unitario'], (float)$it['valor_total']];
        if ($dos) array_unshift($fila, 'Oferta ' . (int)$it['oferta']);
        $filas[] = $fila;
    }
    $cab = ['Referencia', 'Descripción', 'Marca', 'Unidad', 'Cant', 'Valor unitario', 'Valor total'];
    $anchos = [14, 60, 16, 10, 8, 16, 16];
    if ($dos) { array_unshift($cab, 'Oferta'); array_unshift($anchos, 10); }
    xlsx_download('Items_cotizacion_' . $c['consecutivo'] . '.xlsx', [['name' => 'Items', 'headers' => $cab, 'rows' => $filas, 'widths' => $anchos]]);
}

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
                    (count($ofertas) > 1 ? (!empty($c['sumar_ofertas']) ? 'Total (suma de las 2 ofertas)' : 'Totales (oferta 1 / oferta 2)') : 'Total') => '<strong>' . e(cot_total_texto($c)) . '</strong>', ($esProp ? 'Vigencia de tarifas' : 'Validez de la oferta') => e($c['validez'] ?? '')])
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
<style>.rt-vista { white-space: normal; } .rt-vista p { margin: 0 0 4px; } .rt-vista ul, .rt-vista ol { margin: 0 0 4px; padding-left: 22px; }</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= url('cotizaciones') ?>"><i class="bi bi-arrow-left"></i> Cotizaciones</a>
  <h2 class="h5 m-0 ms-1">No. <?= (int)$c['consecutivo'] ?> · <?= e($c['cliente_nombre']) ?></h2>
  <?= badge($c['estado']) ?> <?= badge(COT_FORMATOS[$c['formato']] ?? $c['formato'], 'info') ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($c['estado'] === 'Borrador'): ?><a class="btn btn-sm btn-outline-success" href="<?= url('cotizacion', ['id' => $id, 'xlsx' => 1]) ?>" title="Descargar todos los ítems en Excel (solo en borrador)"><i class="bi bi-file-earmark-excel me-1"></i>Ítems en Excel</a><?php endif; ?>
    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= url('cotizacion', ['id' => $id, 'pdf' => 'ver']) ?>"><i class="bi bi-eye me-1"></i>Ver PDF</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('cotizacion', ['id' => $id, 'pdf' => 'bajar']) ?>"><i class="bi bi-download me-1"></i>Descargar</a>
    <?php if (can('cotizaciones', 'crear')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('cotizacion_form', ['copiar' => $id]) ?>"><i class="bi bi-copy me-1"></i>Duplicar</a><?php endif; ?>
    <?php if ($c['estado'] === 'Aceptada' && can('porfacturar', 'ver')): $pfId = (int)val('SELECT id FROM por_facturar WHERE cotizacion_id = ? ORDER BY id DESC LIMIT 1', [$id]); ?>
      <?php if ($pfId): ?><a class="btn btn-sm btn-outline-success" href="<?= url('por_facturar_form', ['id' => $pfId]) ?>"><i class="bi bi-receipt-cutoff me-1"></i>Ya está en Por facturar</a>
      <?php elseif (can('porfacturar', 'crear')): ?><a class="btn btn-sm btn-outline-success" href="<?= url('por_facturar_form', ['cotizacion' => $id]) ?>"><i class="bi bi-receipt-cutoff me-1"></i>Pasar a por facturar</a><?php endif; ?>
    <?php endif; ?>
    <?php if (can('cotizaciones', 'editar')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('cotizacion_form', ['id' => $id]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a><?php endif; ?>
    <?php if (can('cotizaciones', 'eliminar')): ?><form method="post" data-confirm="¿Eliminar la cotización No. <?= (int)$c['consecutivo'] ?>?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?php foreach ($ofertas as $o => $of): ?>
    <div class="panel">
      <div class="panel-h"><h3><?= $esProp ? (count($ofertas) > 1 ? 'Oferta ' . $o . ($of['titulo'] ? ': ' . e($of['titulo']) : '') : 'Oferta comercial') : 'Ítems' ?></h3></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th class="c">#</th><?php if (!$esProp): ?><th>Referencia</th><?php endif; ?><th><?= $esProp ? 'Ítem' : 'Descripción' ?></th><?php if (!$esProp): ?><th>Marca</th><th>Unidad</th><?php endif; ?><th class="c">Cant</th><th class="r">Valor unit.</th><th class="r">Valor total</th></tr></thead>
        <tbody>
        <?php foreach ($of['items'] as $k => $it): ?>
          <tr><td class="c muted"><?= $k + 1 ?></td><?php if (!$esProp): ?><td><?= e($it['referencia'] ?: '—') ?></td><?php endif; ?><td><?= e($it['descripcion']) ?></td>
            <?php if (!$esProp): ?><td><?= e($it['marca'] ?: '—') ?></td><td><?= e($it['unidad'] ?: '—') ?></td><?php endif; ?>
            <td class="c"><?= e(cot_num($it['cantidad'])) ?></td><td class="r nowrap"><?= money($it['valor_unitario']) ?></td><td class="r nowrap"><?= money($it['valor_total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php $cs = $esProp ? 4 : 7; ?>
          <?php if ((float)$of['mano'] > 0): ?><tr><td colspan="<?= $cs ?>" class="r muted">Mano de obra</td><td class="r"><?= money($of['mano']) ?></td></tr><?php endif; ?>
          <?php if ($of['sumar']): ?><tr><td colspan="<?= $cs ?>" class="r fw-bold">TOTAL<?= count($ofertas) > 1 ? ' OFERTA ' . $o : '' ?></td><td class="r fw-bold"><?= money($of['total']) ?></td></tr><?php else: ?><tr><td colspan="<?= $cs + 1 ?>" class="muted small">Esta oferta se presenta sin total (solo valores por ítem).</td></tr><?php endif; ?>
        </tfoot>
      </table></div>
    </div>
    <?php endforeach; ?>
    <?php if (count($ofertas) > 1 && !empty($c["sumar_ofertas"]) && $ofertas[1]['sumar'] && $ofertas[2]['sumar']): ?>
    <div class="panel d-flex justify-content-between align-items-center"><strong>TOTAL OFERTA 1 + OFERTA 2</strong><strong class="fs-5"><?= money($c["total"] + $c["total2"]) ?></strong></div>
    <?php endif; ?>
    <?php if (!$esProp && trim(strip_tags((string)$c['texto_final'])) !== ''): ?>
    <div class="panel"><div class="panel-h"><h3>Texto al final</h3></div><div class="msg-b rt-vista"><?= cot_rich_html($c['texto_final']) ?></div></div>
    <?php endif; ?>
    <?php if ($esProp): ?>
    <div class="panel">
      <div class="panel-h"><h3>1. Descripción</h3></div><div class="msg-b mb-3"><?= e($c['descripcion'] ?: '—') ?></div>
      <div class="panel-h"><h3>2. Alcances de la propuesta</h3></div><div class="msg-b rt-vista"><?= trim((string)$c['alcances']) !== '' ? cot_rich_html($c['alcances']) : '—' ?></div>
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
