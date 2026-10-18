<?php
require_perm('usuarios', get('id') ? 'editar' : 'crear');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$title = $r ? 'Editar usuario' : 'Nuevo usuario';
$errors = [];
$roles = roles_todos();
$rolesPorId = array_column($roles, null, 'id');
// Solo un administrador puede asignar el rol Administrador o modificar a otro administrador
$rolActual = $r ? ($rolesPorId[$r['rol_id']] ?? null) : null;
if ($rolActual && $rolActual['codigo'] === 'admin' && !is_admin()) require_perm('usuarios', 'administrar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rolSel = $rolesPorId[(int)post('rol_id', 0)] ?? null;
    $d = [
        'nombre' => (string)post('nombre', ''),
        'email' => mb_strtolower((string)post('email', '')),
        'rol_id' => $rolSel['id'] ?? null,
        'rol' => $rolSel ? rol_legacy($rolSel) : 'usuario',
        'empresa_id' => post('empresa_id') ? (int)post('empresa_id') : null,
        'cargo' => post('cargo'),
        'telefono' => post('telefono'),
        'activo' => post('activo') ? 1 : 0,
    ];
    $pass = (string)($_POST['password'] ?? '');
    if ($d['nombre'] === '') $errors[] = 'El nombre es obligatorio.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no es válido.';
    if (!$rolSel) $errors[] = 'Seleccione un rol.';
    elseif ($rolSel['codigo'] === 'admin' && !is_admin()) $errors[] = 'Solo un administrador puede asignar el rol Administrador.';
    if ($rolSel && $rolSel['alcance'] === 'empresa' && !$d['empresa_id']) $errors[] = 'Un usuario con el rol «' . $rolSel['nombre'] . '» debe estar asociado a una empresa.';
    if (val('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $id])) $errors[] = 'Ya existe otro usuario con ese correo.';
    if (!$r && strlen($pass) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    if ($r && $pass !== '' && strlen($pass) < 8) $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
    if ($r && $id === (int)user()['id'] && (($rolSel['codigo'] ?? '') !== 'admin' && is_admin() || !$d['activo'])) $errors[] = 'No puede quitarse el rol de administrador ni desactivarse a sí mismo.';
    if ($r && $rolActual && $rolActual['codigo'] === 'admin' && (($rolSel['codigo'] ?? '') !== 'admin' || !$d['activo'])
        && !(int)val("SELECT COUNT(*) FROM usuarios u JOIN roles ro ON ro.id = u.rol_id WHERE ro.codigo = 'admin' AND u.activo = 1 AND u.id <> ?", [$id])) $errors[] = 'Debe quedar al menos un administrador activo.';

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
$r = $r ?? ['nombre' => '', 'email' => '', 'rol_id' => (int)val("SELECT id FROM roles WHERE codigo = 'usuario'"), 'empresa_id' => null, 'cargo' => '', 'telefono' => '', 'activo' => 1];
?>
<div class="panel" style="max-width:820px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label req">Nombre completo</label><input class="form-control" name="nombre" value="<?= e($r['nombre']) ?>" required></div>
      <div><label class="form-label req">Correo (usuario de acceso)</label><input class="form-control" type="email" name="email" value="<?= e($r['email']) ?>" required></div>
      <div><label class="form-label req">Rol</label><select class="form-select" name="rol_id" id="rol_id">
        <?php foreach ($roles as $ro): if ($ro['codigo'] === 'admin' && !is_admin()) continue; ?>
          <option value="<?= (int)$ro['id'] ?>" data-alc="<?= e($ro['alcance']) ?>" data-desc="<?= e($ro['descripcion']) ?>" <?= (int)$r['rol_id'] === (int)$ro['id'] ? 'selected' : '' ?>><?= e($ro['nombre']) ?></option>
        <?php endforeach; ?></select></div>
      <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="">— Ninguna —</option><?= options(empresas_list(), $r['empresa_id']) ?></select></div>
      <div><label class="form-label">Cargo</label><input class="form-control" name="cargo" value="<?= e($r['cargo']) ?>"></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" value="<?= e($r['telefono']) ?>"></div>
      <div><label class="form-label <?= isset($r['id']) ? '' : 'req' ?>"><?= isset($r['id']) ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña' ?></label><input class="form-control" type="password" name="password" autocomplete="new-password" minlength="8"></div>
      <div class="d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" value="1" id="act" <?= $r['activo'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Usuario activo</label></div></div>
    </div>
    <div class="alert alert-light border mt-3 mb-0 small">
      <span id="rol-desc"></span>
      <?php if (can('roles', 'ver')): ?> Los módulos y acciones de cada rol se configuran en <a href="<?= url('roles') ?>">Roles y permisos</a>.<?php endif; ?>
    </div>
    <script>
    (function () {
      var s = document.getElementById('rol_id'), d = document.getElementById('rol-desc');
      function show() { var o = s.options[s.selectedIndex]; d.textContent = o ? o.getAttribute('data-desc') || '' : ''; }
      s.addEventListener('change', show); show();
    })();
    </script>
    <div class="form-actions">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('usuarios') ?>">Cancelar</a>
    </div>
  </form>
</div>
