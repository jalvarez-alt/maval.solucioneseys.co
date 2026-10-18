<?php
require_role('admin');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM empresas WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar empresa' : 'Nueva empresa';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'nombre' => (string)post('nombre', ''),
        'nit' => post('nit'),
        'tipo' => post('tipo') === 'propia' ? 'propia' : 'cliente',
        'contacto' => post('contacto'),
        'telefono' => post('telefono'),
        'email' => post('email'),
        'direccion' => post('direccion'),
        'activo' => post('activo') ? 1 : 0,
    ];
    if ($d['nombre'] === '') $errors[] = 'El nombre es obligatorio.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es válido.';
    if ($r && $r['tipo'] === 'propia' && $d['tipo'] !== 'propia' && val('SELECT COUNT(*) FROM facturas WHERE empresa_id = ?', [$id]) > 0) {
        $errors[] = 'Esta empresa tiene facturas en cartera; no puede cambiarse a tipo Cliente.';
    }
    if (!$errors) {
        if ($r) { update('empresas', $d, $id); flash('Empresa actualizada.'); }
        else { $d['creado'] = now(); insert('empresas', $d); flash('Empresa creada.'); }
        redirect(url('empresas'));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['nombre' => '', 'nit' => '', 'tipo' => 'cliente', 'contacto' => '', 'telefono' => '', 'email' => '', 'direccion' => '', 'activo' => 1];
?>
<div class="panel" style="max-width:820px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full"><label class="form-label req">Razón social / nombre</label><input class="form-control" name="nombre" value="<?= e($r['nombre']) ?>" required></div>
      <div><label class="form-label">NIT</label><input class="form-control" name="nit" value="<?= e($r['nit']) ?>"></div>
      <div><label class="form-label">Tipo</label><select class="form-select" name="tipo"><?= options(['cliente' => 'Cliente', 'propia' => 'Propia (cartera y gastos)'], $r['tipo']) ?></select></div>
      <div><label class="form-label">Persona de contacto</label><input class="form-control" name="contacto" value="<?= e($r['contacto']) ?>"></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" value="<?= e($r['telefono']) ?>"></div>
      <div><label class="form-label">Correo</label><input class="form-control" type="email" name="email" value="<?= e($r['email']) ?>"></div>
      <div><label class="form-label">Dirección</label><input class="form-control" name="direccion" value="<?= e($r['direccion']) ?>"></div>
      <div class="d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" value="1" id="act" <?= $r['activo'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Activa</label></div></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('empresas') ?>">Cancelar</a>
    </div>
  </form>
</div>
