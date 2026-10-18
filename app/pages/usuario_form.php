<?php
require_role('admin');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar usuario' : 'Nuevo usuario';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'nombre' => (string)post('nombre', ''),
        'email' => mb_strtolower((string)post('email', '')),
        'rol' => (string)post('rol', 'usuario'),
        'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'cargo' => post('cargo'),
        'telefono' => post('telefono'),
        'activo' => post('activo') ? 1 : 0,
    ];
    $pass = (string)($_POST['password'] ?? '');
    if ($d['nombre'] === '') $errors[] = 'El nombre es obligatorio.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es válido.';
    if (!isset(ROLES[$d['rol']])) $errors[] = 'Rol no válido.';
    if ($d['rol'] === 'empresa' && !$d['empresa_id']) $errors[] = 'Un usuario con rol Empresa debe estar asociado a una empresa.';
    if (val('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $id])) $errors[] = 'Ya existe otro usuario con ese correo.';
    if (!$r && strlen($pass) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    if ($r && $pass !== '' && strlen($pass) < 8) $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
    if ($r && $id === (int)user()['id'] && ($d['rol'] !== 'admin' || !$d['activo'])) $errors[] = 'No puede quitarse el rol de administrador ni desactivarse a sí mismo.';

    if (!$errors) {
        if ($pass !== '') $d['password'] = password_hash($pass, PASSWORD_DEFAULT);
        if ($r) {
            update('usuarios', $d, $id);
            flash('Usuario actualizado.');
        } else {
            $d['creado'] = now();
            $id = insert('usuarios', $d);
            flash('Usuario creado. Comparta el correo y la contraseña con la persona.');
        }
        redirect(url('usuarios'));
    }
    $r = array_merge($r ?? [], $d);
}
$r = $r ?? ['nombre' => '', 'email' => '', 'rol' => 'usuario', 'empresa_id' => null, 'cargo' => '', 'telefono' => '', 'activo' => 1];
?>
<div class="panel" style="max-width:820px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label req">Nombre completo</label><input class="form-control" name="nombre" value="<?= e($r['nombre']) ?>" required></div>
      <div><label class="form-label req">Correo (usuario de acceso)</label><input class="form-control" type="email" name="email" value="<?= e($r['email']) ?>" required></div>
      <div><label class="form-label req">Rol</label><select class="form-select" name="rol"><?= options(ROLES, $r['rol']) ?></select></div>
      <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="">— Ninguna —</option><?= options(empresas_list(), $r['empresa_id']) ?></select></div>
      <div><label class="form-label">Cargo</label><input class="form-control" name="cargo" value="<?= e($r['cargo']) ?>"></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" value="<?= e($r['telefono']) ?>"></div>
      <div><label class="form-label <?= isset($r['id']) ? '' : 'req' ?>"><?= isset($r['id']) ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña' ?></label><input class="form-control" type="password" name="password" autocomplete="new-password" minlength="8"></div>
      <div class="d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" value="1" id="act" <?= $r['activo'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Usuario activo</label></div></div>
    </div>
    <div class="alert alert-light border mt-3 mb-0 small">
      <strong>Administrador:</strong> acceso total. <strong>Usuario:</strong> crea y sigue sus tickets y ve los equipos que tiene asignados.
      <strong>Empresa:</strong> ve y crea tickets de toda su empresa y consulta el inventario de su empresa.
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('usuarios') ?>">Cancelar</a>
    </div>
  </form>
</div>
