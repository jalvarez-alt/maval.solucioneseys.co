<?php
require_once APP_DIR . '/cotizaciones.php';
$id = (int)get('id', 0);
$copiar = (int)get('copiar', 0);
$c = $id ? cot_obtener($id) : null;
if ($id && !$c) not_found();
require_perm('cotizaciones', $id ? 'editar' : 'crear');
$em = cot_emisor();
$errors = [];

// Plantilla de Excel para importar ítems
if (get('plantilla')) {
    require_once APP_DIR . '/lib/xlsx.php';
    xlsx_download('plantilla_items_cotizacion.xlsx', [
        ['name' => 'Items', 'headers' => ['Referencia', 'Descripción', 'Marca', 'Unidad', 'Cant', 'Valor unitario'],
         'rows' => [['ZK-M500', 'Dispositivo biométrico 500 huellas, 500 tarjetas, USB, WiFi', 'ZKTeco', 'UND', 1, 370072], ['', 'Mano de obra (instalación de puntos eléctricos)', '', 'UND', 2, 200000]],
         'widths' => [14, 60, 16, 10, 8, 16]],
        ['name' => 'Instrucciones', 'headers' => ['Columna', 'Qué escribir'], 'rows' => [
            ['Referencia', 'Opcional. Código del producto.'], ['Descripción', 'Obligatoria. Texto del ítem.'], ['Marca', 'Opcional.'], ['Unidad', 'Opcional (UND, M, GLB, HORA…). Vacío = UND.'],
            ['Cant', 'Cantidad. Vacío = 1.'], ['Valor unitario', 'Número, sin símbolo (se acepta 1.250.000 o 1250000).'],
            ['', ''], ['Importante', 'Puede importar .xlsx o .csv. Borre las filas de ejemplo antes de subirlo.']], 'widths' => [18, 80]],
    ]);
}
// Lectura del archivo de ítems (la usa el botón «Importar ítems desde archivo»; responde JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'importar_items') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $f = $_FILES['archivo'] ?? null;
        $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Seleccione el archivo.');
        if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('El archivo supera 5 MB.');
        if ($ext === 'xls') throw new RuntimeException('El formato .xls antiguo no es compatible: guárdelo como .xlsx o .csv.');
        [$its, $avisos] = cot_importar_items($f['tmp_name'], $ext);
        echo json_encode(['ok' => true, 'items' => array_map(fn($i) => ['referencia' => $i['referencia'], 'descripcion' => $i['descripcion'], 'marca' => $i['marca'], 'unidad' => $i['unidad'],
            'cantidad' => cot_num($i['cantidad']), 'valor_unitario' => (string)(float)$i['valor_unitario']], $its), 'avisos' => $avisos], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo leer el archivo: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Al crear sin elegir formato: se muestra el selector
$formato = $c['formato'] ?? (string)get('formato', '');
if (!$c && !$copiar && !isset(COT_FORMATOS[$formato])) {
    $title = 'Nueva cotización';
    ?>
<div class="row g-3" style="max-width:900px">
  <div class="col-md-6">
    <a class="panel h-100 d-block text-decoration-none text-reset mb-0" href="<?= url('cotizacion_form', ['formato' => 'items']) ?>">
      <div class="panel-h"><h3><i class="bi bi-table me-2"></i>Cotización con ítems</h3></div>
      <p class="mb-2">Encabezado con los datos del cliente y una tabla de ítems: <strong>referencia, descripción, marca, unidad, cantidad, valor unitario y valor total</strong>. Cierra con el total y las condiciones comerciales.</p>
      <span class="btn btn-primary btn-sm">Usar este formato</span>
    </a>
  </div>
  <div class="col-md-6">
    <a class="panel h-100 d-block text-decoration-none text-reset mb-0" href="<?= url('cotizacion_form', ['formato' => 'propuesta']) ?>">
      <div class="panel-h"><h3><i class="bi bi-file-earmark-text me-2"></i>Propuesta</h3></div>
      <p class="mb-2">Carta con índice y cuatro secciones: <strong>1. Descripción, 2. Alcances de la propuesta, 3. Oferta comercial</strong> (hasta <strong>dos ofertas</strong>, con mano de obra y total) y <strong>4. Condiciones comerciales</strong>.</p>
      <span class="btn btn-primary btn-sm">Usar este formato</span>
    </a>
  </div>
</div>
<?php
    return;
}

// Valores iniciales (nueva, copia o edición)
$origen = $c ?: ($copiar ? cot_obtener($copiar) : null);
if ($copiar && !$origen) not_found();
$formato = $c['formato'] ?? ($origen['formato'] ?? $formato);
$esProp = $formato === 'propuesta';
$v = $origen ?: [];
$v += ['fecha' => today(), 'ciudad' => $em['ciudad'], 'empresa_id' => null, 'cliente_nombre' => '', 'cliente_nit' => '', 'cliente_direccion' => '', 'cliente_telefono' => '', 'cliente_email' => '', 'atencion' => '',
    'descripcion' => '', 'alcances' => '', 'mano_obra' => 0, 'mano_obra2' => 0, 'titulo1' => '', 'titulo2' => '', 'sumar_ofertas' => 0, 'sumar1' => 1, 'sumar2' => 1, 'validez' => $em['validez'], 'tiempo_entrega' => $em['entrega'], 'forma_pago' => $em['pago'], 'notas' => ''];
if ($copiar && !$c) $v['fecha'] = today();
$vacio = [['referencia' => '', 'descripcion' => '', 'marca' => '', 'unidad' => 'UND', 'cantidad' => 1, 'valor_unitario' => '']];
$items = [1 => [], 2 => []];
foreach ($origen ? cot_items((int)$origen['id']) : [] as $it) $items[((int)$it['oferta']) === 2 ? 2 : 1][] = $it;
$ofertas = $esProp ? [1, 2] : [1];
$oferta2 = $esProp && !empty($items[2]);
$title = $c ? 'Editar cotización ' . $c['consecutivo'] : ($copiar ? 'Duplicar cotización' : 'Nueva cotización');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $num = fn($x) => (float)parse_money($x);
    $oferta2 = $esProp && post('oferta2') === '1';
    $d = [
        'fecha' => valid_date(post('fecha')), 'ciudad' => post('ciudad'), 'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'cliente_nombre' => (string)post('cliente_nombre', ''), 'cliente_nit' => post('cliente_nit'), 'cliente_direccion' => post('cliente_direccion'),
        'cliente_telefono' => post('cliente_telefono'), 'cliente_email' => post('cliente_email'), 'atencion' => post('atencion'),
        'descripcion' => $esProp ? post('descripcion') : null, 'alcances' => $esProp ? cot_rich_normalizar((string)($_POST['alcances'] ?? '')) : null,
        'titulo1' => $esProp ? post('titulo1') : null, 'titulo2' => $oferta2 ? post('titulo2') : null, 'sumar_ofertas' => $oferta2 && post('sumar_ofertas') ? 1 : 0, 'sumar1' => post('sumar_1') ? 1 : 0, 'sumar2' => $oferta2 && post('sumar_2') ? 1 : 0,
        'validez' => post('validez'), 'tiempo_entrega' => post('tiempo_entrega'), 'forma_pago' => post('forma_pago'), 'notas' => post('notas'),
    ];
    $its = [1 => [], 2 => []];
    foreach ($ofertas as $o) {
        if ($o === 2 && !$oferta2) continue;
        foreach ((array)($_POST['it_desc'][$o] ?? []) as $i => $desc) {
            $desc = trim((string)$desc);
            if ($desc === '') continue;
            $cant = $num($_POST['it_cant'][$o][$i] ?? '1'); $vu = $num($_POST['it_vu'][$o][$i] ?? '0');
            $its[$o][] = ['referencia' => mb_substr(trim((string)($_POST['it_ref'][$o][$i] ?? '')), 0, 60), 'descripcion' => mb_substr($desc, 0, 500), 'marca' => mb_substr(trim((string)($_POST['it_marca'][$o][$i] ?? '')), 0, 60),
                'unidad' => mb_substr(trim((string)($_POST['it_uni'][$o][$i] ?? '')), 0, 20), 'cantidad' => $cant > 0 ? $cant : 1, 'valor_unitario' => $vu];
        }
    }
    $mano = [1 => $num(post('mano_obra_1', '0')), 2 => $oferta2 ? $num(post('mano_obra_2', '0')) : 0.0]; // solo llegan en cotizaciones antiguas
    if (!$d['fecha']) $errors[] = 'Indique una fecha válida.';
    if ($d['cliente_nombre'] === '') $errors[] = 'Escriba el nombre del cliente (Señores).';
    if ($d['cliente_email'] && !filter_var($d['cliente_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo del cliente no es válido.';
    if (!$its[1]) $errors[] = $esProp ? 'Agregue al menos un ítem con descripción en la oferta 1.' : 'Agregue al menos un ítem con descripción.';
    if ($oferta2 && !$its[2]) $errors[] = 'La oferta 2 está activa pero no tiene ítems: agregue uno o quite la oferta.';
    if (!$errors) {
        $t1 = cot_totales($its[1], $mano[1]);
        $t2 = $oferta2 ? cot_totales($its[2], $mano[2]) : ['subtotal' => 0, 'total' => 0];
        $d += ['mano_obra' => $mano[1], 'subtotal' => $t1['subtotal'], 'iva' => 0, 'iva_pct' => 0, 'total' => $t1['total'],
            'mano_obra2' => $mano[2], 'subtotal2' => $t2['subtotal'], 'iva2' => 0, 'total2' => $t2['total'], 'actualizado' => now()];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($c) { update('cotizaciones', $d, $id); q('DELETE FROM cotizacion_items WHERE cotizacion_id = ?', [$id]); }
            else {
                $d += ['formato' => $formato, 'estado' => 'Borrador', 'creado_por' => (int)user()['id'], 'creado' => now(), 'consecutivo' => cot_siguiente()];
                $id = insert('cotizaciones', $d);
            }
            foreach ($its as $o => $lista) foreach ($lista as $k => $it) insert('cotizacion_items', $it + ['cotizacion_id' => $id, 'oferta' => $o, 'orden' => $k + 1, 'valor_total' => round($it['cantidad'] * $it['valor_unitario'], 2)]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        flash($c ? 'Cotización actualizada.' : 'Cotización No. ' . val('SELECT consecutivo FROM cotizaciones WHERE id = ?', [$id]) . ' creada.');
        redirect(url('cotizacion', ['id' => $id]));
    }
    $v = array_merge($v, $d, ['mano_obra' => $mano[1], 'mano_obra2' => $mano[2]]);
    $items = [1 => $its[1], 2 => $its[2]];
}
foreach ([1, 2] as $o) if (!$items[$o]) $items[$o] = $vacio;
$empresas = all('SELECT id, nombre, nit, direccion, telefono, email, contacto FROM empresas WHERE activo = 1 ORDER BY nombre');

/** Tabla de ítems de una oferta (los campos se llaman it_xxx[oferta][]). */
function cot_tabla_items(int $o, array $lista, bool $esProp): void
{ ?>
  <div class="tbl-wrap"><table class="tbl cot-items" data-o="<?= $o ?>">
    <thead><tr><th style="width:34px" class="c">#</th><th class="col-ref" style="width:110px">Referencia</th><th><?= $esProp ? 'Ítem' : 'Descripción' ?></th><th class="col-marca" style="width:110px">Marca</th><th class="col-uni" style="width:86px">Unidad</th>
      <th style="width:80px">Cant</th><th style="width:130px">Valor unitario</th><th style="width:130px" class="r">Valor total</th><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $it): ?>
      <tr>
        <td class="c muted n"></td>
        <td class="col-ref"><input class="form-control form-control-sm" name="it_ref[<?= $o ?>][]" value="<?= e($it['referencia']) ?>" maxlength="60"></td>
        <td><textarea class="form-control form-control-sm" name="it_desc[<?= $o ?>][]" rows="1" maxlength="500"><?= e($it['descripcion']) ?></textarea></td>
        <td class="col-marca"><input class="form-control form-control-sm" name="it_marca[<?= $o ?>][]" value="<?= e($it['marca']) ?>" maxlength="60"></td>
        <td class="col-uni"><input class="form-control form-control-sm" name="it_uni[<?= $o ?>][]" list="unidades" value="<?= e($it['unidad']) ?>" maxlength="20"></td>
        <td><input class="form-control form-control-sm q" name="it_cant[<?= $o ?>][]" value="<?= e(cot_num($it['cantidad'])) ?>" inputmode="decimal"></td>
        <td><input class="form-control form-control-sm vu" name="it_vu[<?= $o ?>][]" value="<?= e($it['valor_unitario'] === '' ? '' : (float)$it['valor_unitario']) ?>" inputmode="decimal" placeholder="0"></td>
        <td class="r fw-semibold vt nowrap">$ 0</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger del" title="Quitar el ítem"><i class="bi bi-x-lg"></i></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
    <button type="button" class="btn btn-sm btn-outline-primary add" data-o="<?= $o ?>"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button>
    <label class="btn btn-sm btn-outline-secondary mb-0" title="Excel (.xlsx) o CSV con las columnas Referencia, Descripción, Marca, Unidad, Cant y Valor unitario"><i class="bi bi-file-earmark-arrow-up me-1"></i>Importar ítems desde archivo
      <input type="file" class="d-none imp-file" data-o="<?= $o ?>" accept=".xlsx,.csv,.txt"></label>
    <select class="form-select form-select-sm imp-modo" data-o="<?= $o ?>" style="width:auto" title="Qué hacer con los ítems que ya están en la tabla"><option value="agregar">Agregar al final</option><option value="reemplazar">Reemplazar los actuales</option></select>
    <a class="small" href="<?= url('cotizacion_form', ['plantilla' => 1]) ?>">Descargar plantilla</a>
  </div>
  <div class="small mt-1" id="imp-msg-<?= $o ?>"></div>
<?php }

/** Totales de una oferta. */
function cot_bloque_totales(int $o, bool $esProp, array $v): void
{ ?>
  <?php $k = $o === 2 ? 'mano_obra2' : 'mano_obra'; $sk = 'sumar' . $o; ?>
  <div class="row justify-content-end mt-3"><div class="col-md-6 col-lg-5">
    <div class="form-check mb-2">
      <input class="form-check-input sumar-chk" type="checkbox" name="sumar_<?= $o ?>" value="1" id="sumar_<?= $o ?>" data-o="<?= $o ?>" <?= ($v[$sk] ?? 1) ? 'checked' : '' ?>>
      <label class="form-check-label fw-semibold" for="sumar_<?= $o ?>">Sumar los ítems y mostrar el total<?= $esProp ? ' de la oferta ' . $o : '' ?></label>
    </div>
    <table class="tbl" data-resumen="<?= $o ?>"><tbody>
      <tr><td class="muted">Subtotal ítems</td><td class="r" data-t="sub" data-o="<?= $o ?>">$ 0</td></tr>
      <?php if ((float)($v[$k] ?? 0) > 0): /* cotizaciones antiguas que traían mano de obra: se conserva hasta que se quite */ ?><tr><td class="muted">Mano de obra</td><td class="r"><input class="form-control form-control-sm text-end mano" data-o="<?= $o ?>" name="mano_obra_<?= $o ?>" value="<?= e((float)$v[$k]) ?>" inputmode="decimal"></td></tr><?php endif; ?>
      <tr><td class="fw-bold">TOTAL<?= $esProp ? ' oferta ' . $o : '' ?></td><td class="r fw-bold fs-6" data-t="tot" data-o="<?= $o ?>">$ 0</td></tr>
    </tbody></table>
    <div class="form-text">Si no lo marca, la <?= $esProp ? 'oferta' : 'cotización' ?> se muestra solo con los valores de cada ítem, sin subtotal ni total.</div>
  </div></div>
<?php } ?>
<style>
  .cot-items th, .cot-items td { vertical-align: top; }
  .cot-items textarea, .cot-items input { font-size: 13px; }
  .cot-prop .col-ref, .cot-prop .col-marca, .cot-prop .col-uni { display: none; }
  .rt-bar { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; align-items: center; }
  .rt-bar select { width: auto; min-width: 140px; }
  .rt-editor { min-height: 110px; height: auto; overflow: auto; font-size: 10.5pt; line-height: 1.4; }
  .rt-editor:empty:before { content: attr(data-placeholder); color: #8a94a3; }
  .rt-editor p { margin: 0 0 4px; } .rt-editor ul, .rt-editor ol { margin: 0 0 4px; padding-left: 22px; }
</style>
<form method="post" class="panel <?= $esProp ? 'cot-prop' : '' ?>" style="max-width:1100px" id="cot">
  <?= csrf_field() ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
    <?= badge(COT_FORMATOS[$formato], 'info') ?>
    <?php if ($c): ?><strong>Cotización No. <?= (int)$c['consecutivo'] ?></strong><?php else: ?><span class="muted small">Consecutivo asignado al guardar: <strong><?= cot_siguiente() ?></strong></span><?php endif; ?>
  </div>
  <h6 class="sec mt-2">Cliente</h6>
  <div class="form-grid">
    <div><label class="form-label">Cliente registrado (opcional)</label>
      <select class="form-select" name="empresa_id" id="empresa"><option value="">— Escribir los datos manualmente —</option><?= options(array_column($empresas, 'nombre', 'id'), $v['empresa_id']) ?></select></div>
    <div><label class="form-label req">Señores</label><input class="form-control" name="cliente_nombre" id="c_nombre" value="<?= e($v['cliente_nombre']) ?>" required maxlength="150"></div>
    <div><label class="form-label">C.C. o NIT</label><input class="form-control" name="cliente_nit" id="c_nit" value="<?= e($v['cliente_nit']) ?>"></div>
    <div><label class="form-label">Estimado(a) Sr.(a) / Atn.</label><input class="form-control" name="atencion" id="c_atn" value="<?= e($v['atencion']) ?>" placeholder="Persona de contacto"></div>
    <div><label class="form-label">Dirección</label><input class="form-control" name="cliente_direccion" id="c_dir" value="<?= e($v['cliente_direccion']) ?>"></div>
    <div><label class="form-label">Teléfono</label><input class="form-control" name="cliente_telefono" id="c_tel" value="<?= e($v['cliente_telefono']) ?>"></div>
    <div><label class="form-label">Correo electrónico (para enviar la cotización)</label><input class="form-control" type="email" name="cliente_email" id="c_mail" value="<?= e($v['cliente_email']) ?>"></div>
    <div><label class="form-label req">Fecha</label><input class="form-control" type="date" name="fecha" value="<?= e($v['fecha']) ?>" required></div>
    <?php if ($esProp): ?><div><label class="form-label">Ciudad</label><input class="form-control" name="ciudad" value="<?= e($v['ciudad']) ?>"></div><?php endif; ?>
  </div>

  <?php if ($esProp): ?>
  <h6 class="sec">1. Descripción</h6>
  <textarea class="form-control" name="descripcion" rows="3" placeholder="En qué consiste la solución"><?= e($v['descripcion']) ?></textarea>
  <h6 class="sec">2. Alcances de la propuesta</h6>
  <div class="rt-bar" id="rt-bar">
    <select class="form-select form-select-sm" id="rt-fuente" title="Tipo de letra"><option value="">Tipo de letra</option><option value="Arial">Arial</option><option value="Times New Roman">Times New Roman</option><option value="Courier New">Courier New</option></select>
    <select class="form-select form-select-sm" id="rt-tam" title="Tamaño de letra"><option value="">Tamaño</option><option value="8">8</option><option value="9">9</option><option value="10.5">10,5 (normal)</option><option value="12">12</option><option value="14">14</option><option value="16">16</option><option value="20">20</option></select>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bold" title="Negrilla"><i class="bi bi-type-bold"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertUnorderedList" title="Viñetas"><i class="bi bi-list-ul"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertOrderedList" title="Numeración"><i class="bi bi-list-ol"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="removeFormat" title="Quitar formato"><i class="bi bi-eraser"></i></button>
  </div>
  <div id="rt-editor" class="form-control rt-editor" contenteditable="true" data-placeholder="Qué incluye y cómo queda funcionando"><?= cot_rich_html($v['alcances']) ?></div>
  <input type="hidden" name="alcances" id="rt-valor">
  <div class="form-text">Seleccione el texto y use la barra: negrilla, tipo y tamaño de letra, viñetas y numeración. Se respeta tal cual en el PDF.</div>
  <h6 class="sec">3. Oferta comercial</h6>
  <input type="hidden" name="oferta2" id="oferta2" value="<?= $oferta2 ? '1' : '0' ?>">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <strong>Oferta 1</strong>
    <input class="form-control form-control-sm" style="max-width:340px" name="titulo1" value="<?= e($v['titulo1']) ?>" placeholder="Título (opcional), p. ej. Con materiales">
  </div>
  <?php else: ?>
  <h6 class="sec">Ítems</h6>
  <?php endif; ?>
  <?php cot_tabla_items(1, $items[1], $esProp); cot_bloque_totales(1, $esProp, $v); ?>

  <?php if ($esProp): ?>
  <div id="bloque2" <?= $oferta2 ? '' : 'hidden' ?> class="mt-4 pt-3 border-top">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <strong>Oferta 2</strong>
      <input class="form-control form-control-sm" style="max-width:340px" name="titulo2" value="<?= e($v['titulo2']) ?>" placeholder="Título (opcional), p. ej. Solo mano de obra">
      <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="quitar2"><i class="bi bi-trash me-1"></i>Quitar la oferta 2</button>
    </div>
    <?php cot_tabla_items(2, $items[2], $esProp); cot_bloque_totales(2, $esProp, $v); ?>
    <div class="alert alert-light border mt-3 mb-0">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="sumar_ofertas" value="1" id="sumar" <?= !empty($v['sumar_ofertas']) ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="sumar">Sumar las dos ofertas</label></div>
      <div class="small muted">Si lo marca, el PDF agrega al final «TOTAL OFERTA 1 + OFERTA 2». Si no, el cliente ve cada oferta por separado (son alternativas).</div>
      <div class="mt-1">Total combinado: <strong id="t_suma">$ 0</strong></div>
    </div>
  </div>
  <button type="button" class="btn btn-outline-primary mt-3" id="agregar2" <?= $oferta2 ? 'hidden' : '' ?>><i class="bi bi-plus-circle me-1"></i>Agregar una segunda oferta comercial</button>
  <?php endif; ?>
  <datalist id="unidades"><?php foreach (COT_UNIDADES as $u) echo '<option value="' . e($u) . '">'; ?></datalist>

  <h6 class="sec"><?= $esProp ? '4. Condiciones comerciales' : 'Condiciones comerciales' ?></h6>
  <div class="form-grid">
    <div><label class="form-label"><?= $esProp ? 'Vigencia de tarifas' : 'Validez de la oferta' ?></label><input class="form-control" name="validez" value="<?= e($v['validez']) ?>" placeholder="10 DIAS"></div>
    <div><label class="form-label">Tiempo de entrega</label><input class="form-control" name="tiempo_entrega" value="<?= e($v['tiempo_entrega']) ?>"></div>
    <div><label class="form-label">Forma de pago</label><input class="form-control" name="forma_pago" value="<?= e($v['forma_pago']) ?>"></div>
    <div class="full"><label class="form-label">Notas internas (no aparecen en el PDF)</label><input class="form-control" name="notas" value="<?= e($v['notas']) ?>"></div>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar cotización</button>
    <a class="btn btn-outline-secondary" href="<?= $c ? url('cotizacion', ['id' => $id]) : url('cotizaciones') ?>">Cancelar</a>
  </div>
</form>
<script>
(function () {
  var emp = <?= json_encode(array_column($empresas, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var $ = function (id) { return document.getElementById(id); };
  $('empresa').addEventListener('change', function () {
    var e = emp[this.value]; if (!e) return;
    $('c_nombre').value = e.nombre || ''; $('c_nit').value = e.nit || ''; $('c_dir').value = e.direccion || '';
    $('c_tel').value = e.telefono || ''; $('c_mail').value = e.email || ''; $('c_atn').value = e.contacto || '';
  });
  function pm(v) {
    v = String(v || '').replace(/[^\d,.\-]/g, ''); if (!v || v === '-') return 0;
    var c = v.lastIndexOf(','), d = v.lastIndexOf('.');
    if (c !== -1 && (d === -1 || c > d)) v = v.replace(/\./g, '').replace(',', '.');
    else { v = v.replace(/,/g, ''); if ((v.match(/\./g) || []).length > 1 || (d !== -1 && v.length - d - 1 === 3 && c === -1)) v = v.replace(/\./g, ''); }
    return parseFloat(v) || 0;
  }
  var money = function (n) { return '$ ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
  function tabla(o) { return document.querySelector('table.cot-items[data-o="' + o + '"] tbody'); }
  function recalc() {
    var tot = {1: 0, 2: 0};
    [1, 2].forEach(function (o) {
      var body = tabla(o); if (!body) return;
      var sub = 0;
      Array.prototype.forEach.call(body.rows, function (r, i) {
        r.querySelector('.n').textContent = i + 1;
        var t = pm(r.querySelector('.q').value) * pm(r.querySelector('.vu').value);
        r.querySelector('.vt').textContent = money(t); sub += t;
      });
      var m = document.querySelector('.mano[data-o="' + o + '"]'), mano = m ? pm(m.value) : 0;
      var chk = document.querySelector('.sumar-chk[data-o="' + o + '"]'), rs = document.querySelector('table[data-resumen="' + o + '"]');
      if (rs && chk) rs.style.opacity = chk.checked ? '1' : '.4';
      var set = function (k, val) { var el = document.querySelector('[data-t="' + k + '"][data-o="' + o + '"]'); if (el) el.textContent = money(val); };
      set('sub', sub); set('tot', sub + mano); tot[o] = sub + mano;
    });
    if ($('t_suma')) $('t_suma').textContent = money(tot[1] + tot[2]);
  }
  function fit(t) { t.style.height = 'auto'; t.style.height = Math.max(31, t.scrollHeight) + 'px'; }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('.add') : null;
    if (a) {
      var body = tabla(a.getAttribute('data-o')), r = body.rows[body.rows.length - 1].cloneNode(true);
      Array.prototype.forEach.call(r.querySelectorAll('input,textarea'), function (i) { i.value = ''; });
      r.querySelector('.q').value = '1'; r.querySelector('[name^="it_uni"]').value = 'UND';
      body.appendChild(r); recalc(); r.querySelector('textarea').focus(); return;
    }
    var b = e.target.closest ? e.target.closest('.del') : null;
    if (!b) return;
    var tb = b.closest('tbody');
    if (tb.rows.length > 1) b.closest('tr').remove(); else Array.prototype.forEach.call(b.closest('tr').querySelectorAll('input,textarea'), function (i) { i.value = ''; });
    recalc();
  });
  if ($('agregar2')) {
    $('agregar2').addEventListener('click', function () { $('bloque2').hidden = false; this.hidden = true; $('oferta2').value = '1'; Array.prototype.forEach.call($('bloque2').querySelectorAll('textarea'), fit); recalc(); });
    $('quitar2').addEventListener('click', function () {
      if (!confirm('¿Quitar la oferta 2 y sus ítems?')) return;
      var body = tabla(2); while (body.rows.length > 1) body.deleteRow(-1);
      Array.prototype.forEach.call(body.querySelectorAll('input,textarea'), function (i) { i.value = ''; });
      body.querySelector('.q').value = '1'; body.querySelector('[name^="it_uni"]').value = 'UND';
      document.querySelector('[name="titulo2"]').value = ''; var m2 = document.querySelector('.mano[data-o="2"]'); if (m2) m2.value = '';
      $('bloque2').hidden = true; $('agregar2').hidden = false; $('oferta2').value = '0'; recalc();
    });
  }
  document.addEventListener('input', function (e) { if (e.target.matches('#cot input, #cot textarea')) { if (e.target.tagName === 'TEXTAREA') fit(e.target); recalc(); } });
  Array.prototype.forEach.call(document.querySelectorAll('.cot-items textarea'), fit);
  // Importar ítems desde un archivo plano (Excel o CSV)
  function filaNueva(body) {
    var vacia = Array.prototype.filter.call(body.rows, function (r) { return !r.querySelector('textarea').value.trim(); })[0];
    if (vacia) return vacia;
    var r = body.rows[body.rows.length - 1].cloneNode(true);
    Array.prototype.forEach.call(r.querySelectorAll('input,textarea'), function (i) { i.value = ''; });
    body.appendChild(r); return r;
  }
  Array.prototype.forEach.call(document.querySelectorAll('.imp-file'), function (inp) {
    inp.addEventListener('change', function () {
      var o = inp.getAttribute('data-o'), msg = document.getElementById('imp-msg-' + o), file = inp.files[0];
      if (!file) return;
      var modo = document.querySelector('.imp-modo[data-o="' + o + '"]').value;
      if (modo === 'reemplazar' && !confirm('Se reemplazarán los ítems actuales de esta oferta por los del archivo. ¿Continuar?')) { inp.value = ''; return; }
      var fd = new FormData();
      fd.append('_csrf', document.querySelector('#cot input[name=_csrf]').value); fd.append('accion', 'importar_items'); fd.append('archivo', file);
      msg.className = 'small mt-1 muted'; msg.textContent = 'Leyendo el archivo…';
      fetch('index.php?p=cotizacion_form', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          inp.value = '';
          if (!j.ok) { msg.className = 'small mt-1 text-danger'; msg.textContent = j.error; return; }
          var body = tabla(o);
          if (modo === 'reemplazar') { while (body.rows.length > 1) body.deleteRow(-1); Array.prototype.forEach.call(body.rows[0].querySelectorAll('input,textarea'), function (i) { i.value = ''; }); }
          j.items.forEach(function (it) {
            var r = filaNueva(body);
            r.querySelector('[name^="it_ref"]').value = it.referencia; r.querySelector('textarea').value = it.descripcion;
            r.querySelector('[name^="it_marca"]').value = it.marca; r.querySelector('[name^="it_uni"]').value = it.unidad;
            r.querySelector('.q').value = it.cantidad; r.querySelector('.vu').value = it.valor_unitario;
          });
          Array.prototype.forEach.call(body.querySelectorAll('textarea'), fit);
          recalc();
          msg.className = 'small mt-1 ' + (j.avisos.length ? 'text-warning-emphasis' : 'text-success');
          msg.textContent = j.items.length + ' ítem(s) importado(s). Revise y guarde la cotización.' + (j.avisos.length ? ' Avisos: ' + j.avisos.slice(0, 5).join(' ') + (j.avisos.length > 5 ? ' (y ' + (j.avisos.length - 5) + ' más)' : '') : '');
        })
        .catch(function () { inp.value = ''; msg.className = 'small mt-1 text-danger'; msg.textContent = 'No se pudo importar el archivo.'; });
    });
  });
  // Editor de texto con formato (Alcances de la propuesta)
  var rt = document.getElementById('rt-editor');
  if (rt) {
    var bar = document.getElementById('rt-bar');
    bar.addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); });   // no perder la selección
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      rt.focus(); document.execCommand(b.getAttribute('data-cmd'), false, null);
    });
    document.getElementById('rt-fuente').addEventListener('change', function () { if (!this.value) return; rt.focus(); document.execCommand('fontName', false, this.value); this.value = ''; });
    document.getElementById('rt-tam').addEventListener('change', function () {
      var pt = this.value; this.value = ''; if (!pt) return;
      rt.focus(); document.execCommand('fontSize', false, '7');
      Array.prototype.forEach.call(rt.querySelectorAll('font[size="7"]'), function (f) {
        var s = document.createElement('span'); s.style.fontSize = pt + 'pt';
        while (f.firstChild) s.appendChild(f.firstChild); f.parentNode.replaceChild(s, f);
      });
    });
    rt.addEventListener('paste', function (e) { e.preventDefault(); var t = (e.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t); });
    document.getElementById('cot').addEventListener('submit', function () { document.getElementById('rt-valor').value = rt.innerHTML; });
  }
  recalc();
})();
</script>
