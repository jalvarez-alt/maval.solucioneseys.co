<?php
require_once APP_DIR . '/boveda.php';
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
$id = (int)get('id', 0);
$r = $id ? one('SELECT * FROM boveda WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
$vid = $r ? (int)$r['boveda_id'] : (int)get('v', 0);
$boveda = bov_obtener($vid);                      // null si no existe o el usuario no tiene acceso
if (!$boveda) not_found();
require_perm('boveda', $id ? 'editar' : 'crear');
$dek = bov_dek($vid);
if (!$dek) { flash('Desbloquee la bóveda para ' . ($id ? 'editar' : 'crear') . ' claves.', 'warning'); redirect(url('boveda', ['v' => $vid])); }
$title = ($r ? 'Editar clave' : 'Nueva clave') . ' · ' . $boveda['nombre'];
$errors = [];
$verSecretos = can('boveda', 'aprobar');   // con este permiso el usuario y las notas se precargan al editar (queda registrado); la contraseña nunca

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Secretos sin recortar espacios (forman parte de la clave)
    $usuario = mb_substr((string)($_POST['usuario'] ?? ''), 0, 255); $clave = mb_substr((string)($_POST['clave'] ?? ''), 0, 1000); $notas = mb_substr((string)($_POST['notas'] ?? ''), 0, 5000);
    $d = ['titulo' => mb_substr((string)post('titulo', ''), 0, 150), 'tipo' => in_array(post('tipo'), BOV_TIPOS, true) ? post('tipo') : 'Otro', 'host' => mb_substr((string)post('host', ''), 0, 255) ?: null];
    if ($d['titulo'] === '') $errors[] = 'Escriba un título (por ejemplo «Router principal»).';
    if (!$r && $clave === '') $errors[] = 'Escriba la contraseña.';
    if (!$errors) {
        $ahora = now();
        if ($r) {
            $d += ['actualizado_por' => (int)user()['id'], 'actualizado' => $ahora];
            // Contraseña: vacía = conservar. Usuario y notas: si se precargaron (permiso de ver), lo enviado reemplaza; si no, vacío = conservar.
            if ($clave !== '') { $d['clave_enc'] = bov_enc($clave, $dek, bov_aad($id, 'clave')); $d['clave_cambiada'] = $ahora; }
            if ($verSecretos || $usuario !== '') $d['usuario_enc'] = $usuario === '' ? null : bov_enc($usuario, $dek, bov_aad($id, 'usuario'));
            if ($verSecretos || $notas !== '') $d['notas_enc'] = $notas === '' ? null : bov_enc($notas, $dek, bov_aad($id, 'notas'));
            update('boveda', $d, $id);
            bov_log('editar', $boveda, $id, $d['titulo'] . ($clave !== '' ? ' (cambió la contraseña)' : ''));
            flash('Clave actualizada.');
        } else {
            $d += ['boveda_id' => $vid, 'creado_por' => (int)user()['id'], 'creado' => $ahora, 'actualizado_por' => (int)user()['id'], 'actualizado' => $ahora, 'clave_cambiada' => $ahora];
            $id = (int)insert('boveda', $d);    // el cifrado se liga al id del registro
            update('boveda', ['usuario_enc' => $usuario === '' ? null : bov_enc($usuario, $dek, bov_aad($id, 'usuario')), 'clave_enc' => bov_enc($clave, $dek, bov_aad($id, 'clave')),
                'notas_enc' => $notas === '' ? null : bov_enc($notas, $dek, bov_aad($id, 'notas'))], $id);
            bov_log('crear', $boveda, $id, $d['titulo']);
            flash('Clave guardada.');
        }
        redirect(url('boveda', ['v' => $vid]));
    }
    $r = array_merge($r ?? [], $d);
    $pre = ['usuario' => $usuario, 'notas' => $notas];
} elseif ($r) {
    $pre = ['usuario' => '', 'notas' => ''];
    if ($verSecretos) {
        $pre = ['usuario' => bov_secreto($dek, $r, 'usuario') ?? '', 'notas' => bov_secreto($dek, $r, 'notas') ?? ''];
        bov_log('ver', $boveda, $id, 'usuario y notas (formulario de edición) · ' . $r['titulo']);
    }
} else {
    $r = ['titulo' => '', 'tipo' => BOV_TIPOS[0], 'host' => ''];
    $pre = ['usuario' => '', 'notas' => ''];
}
?>
<div class="panel" style="max-width:820px">
  <div class="panel-h"><h3><i class="bi bi-shield-lock me-2"></i><?= e($boveda['nombre']) ?></h3></div>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label class="form-label req">Título</label><input class="form-control" name="titulo" maxlength="150" value="<?= e($r['titulo']) ?>" required placeholder="Router principal, Correo administrador…"></div>
      <div><label class="form-label">Tipo</label><select class="form-select" name="tipo"><?= options(BOV_TIPOS, $r['tipo'], false) ?></select></div>
      <div class="full"><label class="form-label">Dirección / URL</label><input class="form-control" name="host" maxlength="255" value="<?= e($r['host'] ?? '') ?>" placeholder="https://192.168.1.1 · mail.cliente.com"><div class="form-text">Se guarda sin cifrar para poder buscar: no escriba aquí datos secretos.</div></div>
      <div><label class="form-label">Usuario</label><input class="form-control" name="usuario" maxlength="255" value="<?= e($pre['usuario']) ?>" autocomplete="off"></div>
      <div><label class="form-label <?= $id ? '' : 'req' ?>">Contraseña</label>
        <div class="input-group">
          <input class="form-control" type="password" name="clave" id="clave" maxlength="1000" autocomplete="new-password" <?= $id ? '' : 'required' ?> placeholder="<?= $id ? '•••••••• (vacío = conservar la actual)' : '' ?>">
          <button type="button" class="btn btn-outline-secondary" id="ver-clave" title="Mostrar u ocultar"><i class="bi bi-eye"></i></button>
          <button type="button" class="btn btn-outline-secondary" id="gen-clave" title="Generar una contraseña segura"><i class="bi bi-magic"></i></button>
        </div></div>
      <div class="full"><label class="form-label">Notas <small class="muted">(se cifran; útil para PIN, preguntas de seguridad, códigos de respaldo…)</small></label>
        <textarea class="form-control" name="notas" rows="4" maxlength="5000" autocomplete="off"><?= e($pre['notas']) ?></textarea>
        <?php if ($id && !$verSecretos): ?><div class="form-text">Su rol no puede ver los datos guardados: si deja usuario o notas vacíos se conserva lo que había.</div><?php endif; ?></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= url('boveda', ['v' => $vid]) ?>">Cancelar</a>
    </div>
  </form>
</div>
<script>
(function () {
  var c = document.getElementById('clave');
  document.getElementById('ver-clave').addEventListener('click', function () { c.type = c.type === 'password' ? 'text' : 'password'; });
  document.getElementById('gen-clave').addEventListener('click', function () {
    var abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*?-_', n = 20, out = '', buf = new Uint32Array(n);
    crypto.getRandomValues(buf); for (var i = 0; i < n; i++) out += abc[buf[i] % abc.length];
    c.value = out; c.type = 'text';
  });
})();
</script>
