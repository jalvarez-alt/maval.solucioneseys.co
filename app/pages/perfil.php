<?php
$title = 'Mi perfil';
$u = user();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = (string)post('nombre', '');
    $tel = post('telefono');
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $nueva2 = (string)($_POST['nueva2'] ?? '');
    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
    $d = ['nombre' => $nombre, 'telefono' => $tel];
    if ($nueva !== '') {
        if (!password_verify($actual, $u['password'])) $errors[] = 'La contraseña actual no es correcta.';
        elseif (strlen($nueva) < 8) $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
        elseif ($nueva !== $nueva2) $errors[] = 'Las contraseñas nuevas no coinciden.';
        else $d['password'] = password_hash($nueva, PASSWORD_DEFAULT);
    }
    if (!$errors) {
        update('usuarios', $d, (int)$u['id']);
        flash(isset($d['password']) ? 'Perfil y contraseña actualizados.' : 'Perfil actualizado.');
        redirect(url('perfil'));
    }
}
?>
<div class="panel" style="max-width:680px">
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label">Nombre</label><input class="form-control" name="nombre" value="<?= e($u['nombre']) ?>" required></div>
      <div><label class="form-label">Teléfono</label><input class="form-control" name="telefono" value="<?= e($u['telefono']) ?>"></div>
      <div><label class="form-label">Correo</label><input class="form-control" value="<?= e($u['email']) ?>" disabled></div>
      <div><label class="form-label">Rol</label><input class="form-control" value="<?= e(nombre_rol($u)) ?><?= $u['empresa_nombre'] ? ' · ' . e($u['empresa_nombre']) : '' ?>" disabled></div>
    </div>
    <h6 class="sec">Cambiar contraseña</h6>
    <div class="form-grid">
      <div><label class="form-label">Contraseña actual</label><input class="form-control" type="password" name="actual"></div>
      <div><label class="form-label">Nueva contraseña</label><input class="form-control" type="password" name="nueva" minlength="8" autocomplete="new-password"></div>
      <div><label class="form-label">Repetir nueva</label><input class="form-control" type="password" name="nueva2" autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Guardar cambios</button></div>
  </form>
</div>
