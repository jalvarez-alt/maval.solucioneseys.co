<?php
require_once APP_DIR . '/cotizaciones.php';
$id = (int)get('id', 0);
$copiar = (int)get('copiar', 0);
$c = $id ? cot_obtener($id) : null;
if ($id && !$c) not_found();
require_perm('cotizaciones', $id ? 'editar' : 'crear');
$em = cot_emisor();
$errors = [];

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
    'descripcion' => '', 'alcances' => '', 'mano_obra' => 0, 'mano_obra2' => 0, 'titulo1' => '', 'titulo2' => '', 'validez' => $em['validez'], 'tiempo_entrega' => $em['entrega'], 'forma_pago' => $em['pago'], 'notas' => ''];
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
        'descripcion' => $esProp ? post('descripcion') : null, 'alcances' => $esProp ? post('alcances') : null,
        'titulo1' => $esProp ? post('titulo1') : null, 'titulo2' => $oferta2 ? post('titulo2') : null,
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
    $mano = [1 => $esProp ? $num(post('mano_obra_1', '0')) : 0.0, 2 => $oferta2 ? $num(post('mano_obra_2', '0')) : 0.0];
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
  <button type="button" class="btn btn-sm btn-outline-primary mt-2 add" data-o="<?= $o ?>"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button>
<?php }

/** Totales de una oferta. */
function cot_bloque_totales(int $o, bool $esProp, array $v): void
{ ?>
  <div class="row justify-content-end mt-3"><div class="col-md-5 col-lg-4">
    <table class="tbl"><tbody>
      <tr><td class="muted">Subtotal ítems</td><td class="r" data-t="sub" data-o="<?= $o ?>">$ 0</td></tr>
      <?php if ($esProp): $k = $o === 2 ? 'mano_obra2' : 'mano_obra'; ?><tr><td class="muted">Mano de obra</td><td class="r"><input class="form-control form-control-sm text-end mano" data-o="<?= $o ?>" name="mano_obra_<?= $o ?>" value="<?= e((float)$v[$k] ?: '') ?>" inputmode="decimal" placeholder="0"></td></tr><?php endif; ?>
      <tr><td class="fw-bold">TOTAL<?= $esProp ? ' oferta ' . $o : '' ?></td><td class="r fw-bold fs-6" data-t="tot" data-o="<?= $o ?>">$ 0</td></tr>
    </tbody></table>
  </div></div>
<?php } ?>
<style>
  .cot-items th, .cot-items td { vertical-align: top; }
  .cot-items textarea, .cot-items input { font-size: 13px; }
  .cot-prop .col-ref, .cot-prop .col-marca, .cot-prop .col-uni { display: none; }
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
  <textarea class="form-control" name="alcances" rows="3" placeholder="Qué incluye y cómo queda funcionando"><?= e($v['alcances']) ?></textarea>
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
    [1, 2].forEach(function (o) {
      var body = tabla(o); if (!body) return;
      var sub = 0;
      Array.prototype.forEach.call(body.rows, function (r, i) {
        r.querySelector('.n').textContent = i + 1;
        var t = pm(r.querySelector('.q').value) * pm(r.querySelector('.vu').value);
        r.querySelector('.vt').textContent = money(t); sub += t;
      });
      var m = document.querySelector('.mano[data-o="' + o + '"]'), mano = m ? pm(m.value) : 0;
      var set = function (k, val) { var el = document.querySelector('[data-t="' + k + '"][data-o="' + o + '"]'); if (el) el.textContent = money(val); };
      set('sub', sub); set('tot', sub + mano);
    });
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
      document.querySelector('[name="titulo2"]').value = ''; document.querySelector('.mano[data-o="2"]').value = '';
      $('bloque2').hidden = true; $('agregar2').hidden = false; $('oferta2').value = '0'; recalc();
    });
  }
  document.addEventListener('input', function (e) { if (e.target.matches('#cot input, #cot textarea')) { if (e.target.tagName === 'TEXTAREA') fit(e.target); recalc(); } });
  Array.prototype.forEach.call(document.querySelectorAll('.cot-items textarea'), fit);
  recalc();
})();
</script>
