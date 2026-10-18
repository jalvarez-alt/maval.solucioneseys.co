<?php
require_role('admin');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM gastos WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar gasto' : 'Registrar gasto';
$errors = [];
$cats = array_column(all('SELECT id, nombre FROM gasto_categorias WHERE activo = 1 ' . ($r ? 'OR id = ' . (int)$r['categoria_id'] : '') . ' ORDER BY nombre'), 'nombre', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('accion') === 'quitar_adj') {
        $a = one("SELECT * FROM adjuntos WHERE id = ? AND entidad = 'gasto' AND entidad_id = ?", [(int)post('adj'), $id]);
        if ($a) { @unlink(UPLOAD_DIR . '/' . $a['archivo']); q('DELETE FROM adjuntos WHERE id = ?', [$a['id']]); flash('Soporte eliminado.'); }
        redirect(url('gasto_form', ['id' => $id]));
    }
    $d = [
        'fecha' => valid_date(post('fecha')),
        'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'categoria_id' => (int)post('categoria_id', 0),
        'proveedor' => post('proveedor'),
        'concepto' => (string)post('concepto', ''),
        'valor' => parse_money(post('valor', '0')),
        'metodo_pago' => post('metodo_pago'),
        'referencia' => post('referencia'),
        'notas' => post('notas'),
    ];
    if (!$d['fecha']) $errors[] = 'Indique una fecha válida.';
    if (!isset($cats[$d['categoria_id']])) $errors[] = 'Seleccione una categoría.';
    if ($d['concepto'] === '') $errors[] = 'Escriba el concepto del gasto.';
    if ($d['valor'] <= 0) $errors[] = 'El valor debe ser mayor que cero.';
    if (!$errors) {
        if ($r) { update('gastos', $d, $id); flash('Gasto actualizado.'); }
        else { $d['creado_por'] = user()['id']; $d['creado'] = now(); $id = insert('gastos', $d); flash('Gasto de ' . money($d['valor']) . ' registrado.'); }
        save_uploads('soportes', 'gasto', $id);
        if (post('otro')) redirect(url('gasto_form', ['fecha' => $d['fecha'], 'cat' => $d['categoria_id'], 'empresa' => $d['empresa_id']]));
        redirect(url('gastos', ['anio' => substr($d['fecha'], 0, 4), 'mes' => (int)substr($d['fecha'], 5, 2)]));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['fecha' => valid_date(get('fecha')) ?? today(), 'empresa_id' => get('empresa'), 'categoria_id' => get('cat'), 'proveedor' => '', 'concepto' => '', 'valor' => '', 'metodo_pago' => 'Transferencia', 'referencia' => '', 'notas' => ''];
$proveedores = array_column(all("SELECT DISTINCT proveedor FROM gastos WHERE proveedor IS NOT NULL AND proveedor <> '' ORDER BY proveedor LIMIT 300"), 'proveedor');
$conceptos = array_column(all("SELECT DISTINCT concepto FROM gastos ORDER BY concepto LIMIT 300"), 'concepto');
?>
<div class="panel" style="max-width:900px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label req">Fecha</label><input class="form-control" type="date" name="fecha" value="<?= e($r['fecha']) ?>" required></div>
      <div><label class="form-label req">Categoría</label><select class="form-select" name="categoria_id" required><option value="">Seleccione…</option><?= options($cats, $r['categoria_id']) ?></select></div>
      <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="">— General —</option><?= options(empresas_list('propia'), $r['empresa_id']) ?></select></div>
      <div class="full"><label class="form-label req">Concepto</label><input class="form-control" name="concepto" list="dl-conceptos" maxlength="255" value="<?= e($r['concepto']) ?>" placeholder="Ej.: Factura de energía septiembre" required></div>
      <div><label class="form-label">Proveedor</label><input class="form-control" name="proveedor" list="dl-proveedores" value="<?= e($r['proveedor']) ?>"></div>
      <div><label class="form-label req">Valor</label><input class="form-control money" name="valor" value="<?= e($r['valor']) ?>" inputmode="decimal" required></div>
      <div><label class="form-label">Método de pago</label><select class="form-select" name="metodo_pago"><option value=""></option><?= options(METODOS_PAGO, $r['metodo_pago'], false) ?></select></div>
      <div><label class="form-label">Referencia / N.º factura</label><input class="form-control" name="referencia" value="<?= e($r['referencia']) ?>"></div>
      <div class="full"><label class="form-label">Notas</label><textarea class="form-control" name="notas" rows="2"><?= e($r['notas']) ?></textarea></div>
      <div class="full"><label class="form-label">Soportes (factura, recibo, comprobante)</label><input class="form-control" type="file" name="soportes[]" multiple>
        <?php if ($id): foreach (adjuntos('gasto', $id) as $a): ?>
          <div class="d-inline-flex align-items-center gap-1 mt-2 me-2"><a class="adj" href="archivo.php?id=<?= (int)$a['id'] ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= e($a['nombre_original']) ?></a>
            <button class="btn btn-sm btn-link text-danger p-0" form="quitar<?= (int)$a['id'] ?>" title="Quitar"><i class="bi bi-x-circle"></i></button></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <?php if (!$id): ?><button class="btn btn-outline-primary" name="otro" value="1">Guardar y registrar otro</button><?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= url('gastos') ?>">Cancelar</a>
    </div>
  </form>
  <?php if ($id): foreach (adjuntos('gasto', $id) as $a): ?>
    <form method="post" id="quitar<?= (int)$a['id'] ?>" data-confirm="¿Quitar este soporte?"><?= csrf_field() ?><input type="hidden" name="accion" value="quitar_adj"><input type="hidden" name="adj" value="<?= (int)$a['id'] ?>"></form>
  <?php endforeach; endif; ?>
</div>
<datalist id="dl-proveedores"><?php foreach ($proveedores as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
<datalist id="dl-conceptos"><?php foreach ($conceptos as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
