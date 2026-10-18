<?php
$id = (int)get('id', 0);
$copiar = (int)get('copiar', 0);
$r = $id ? one('SELECT * FROM roles WHERE id = ?', [$id]) : null;
if ($id && !$r) not_found();
require_perm('roles', $id ? 'ver' : 'crear');
$esAdmin = $r && $r['codigo'] === 'admin';
$puedeEditar = can('roles', $id ? 'editar' : 'crear') && !$esAdmin;
$title = $r ? 'Rol ' . $r['nombre'] : 'Nuevo rol';
$errors = [];

// Permisos actuales (o los del rol que se copia)
$origen = $id ?: $copiar;
$marcados = [];
if ($origen) foreach (all('SELECT modulo, accion FROM rol_permisos WHERE rol_id = ?', [$origen]) as $p) $marcados[$p['modulo'] . '.' . $p['accion']] = true;
$base = $r ?? ['nombre' => '', 'descripcion' => '', 'alcance' => 'propios', 'codigo' => null, 'sistema' => 0];
if (!$r && $copiar && ($c = one('SELECT alcance FROM roles WHERE id = ?', [$copiar]))) $base['alcance'] = $c['alcance'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('accion') === 'eliminar') {
        require_perm('roles', 'eliminar');
        if (!$r || $r['sistema']) flash('Los roles del sistema no se pueden eliminar.', 'warning');
        elseif ((int)val('SELECT COUNT(*) FROM usuarios WHERE rol_id = ?', [$id])) flash('No se puede eliminar: hay usuarios con este rol. Cámbieles el rol primero.', 'warning');
        else { q('DELETE FROM roles WHERE id = ?', [$id]); flash('Rol eliminado.'); redirect(url('roles')); }
        redirect(url('rol_form', ['id' => $id]));
    }
    if (!$puedeEditar) {
        if ($esAdmin) { flash('El rol Administrador no se puede modificar.', 'warning'); redirect(url('roles')); }
        require_perm('roles', $id ? 'editar' : 'crear');
    }
    $d = ['nombre' => (string)post('nombre', ''), 'descripcion' => post('descripcion'), 'alcance' => (string)post('alcance', 'propios')];
    if ($r && $r['sistema']) { $d['nombre'] = $r['nombre']; $d['alcance'] = $r['alcance']; } // los roles del sistema conservan nombre y alcance
    if ($d['nombre'] === '') $errors[] = 'Escriba el nombre del rol.';
    if (!isset(ALCANCES[$d['alcance']])) $errors[] = 'Alcance no válido.';
    if (val('SELECT id FROM roles WHERE LOWER(nombre) = LOWER(?) AND id <> ?', [$d['nombre'], $id])) $errors[] = 'Ya existe un rol con ese nombre.';
    $sel = [];
    foreach (MODULOS as $mod => [$nom, $ico, $acciones]) {
        foreach (array_keys($acciones) as $acc) if (!empty($_POST['perm'][$mod][$acc])) $sel[$mod][$acc] = true;
        if (!empty($sel[$mod]) && isset($acciones['ver'])) $sel[$mod]['ver'] = true; // cualquier acción requiere poder consultar el módulo
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        if ($r) { update('roles', $d, $id); q('UPDATE usuarios SET rol = ? WHERE rol_id = ?', [rol_legacy($d + ['codigo' => $r['codigo']]), $id]); }
        else { $d += ['sistema' => 0, 'creado' => now()]; $id = insert('roles', $d); }
        q('DELETE FROM rol_permisos WHERE rol_id = ?', [$id]);
        foreach ($sel as $mod => $accs) foreach (array_keys($accs) as $acc) insert('rol_permisos', ['rol_id' => $id, 'modulo' => $mod, 'accion' => $acc]);
        $pdo->commit();
        flash('Rol «' . $d['nombre'] . '» guardado. Los cambios aplican de inmediato a sus usuarios.');
        redirect(url('roles'));
    }
    $base = array_merge($base, $d);
    $marcados = [];
    foreach ($sel as $mod => $accs) foreach (array_keys($accs) as $acc) $marcados[$mod . '.' . $acc] = true;
}
$bloqueado = !$puedeEditar ? ' disabled' : '';
?>
<form method="post" class="panel" style="max-width:1100px">
  <?= csrf_field() ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
  <?php if ($esAdmin): ?><div class="alert alert-info py-2">El rol <strong>Administrador</strong> tiene acceso total y no se puede restringir, para que siempre exista quien configure la aplicación.</div><?php endif; ?>
  <div class="form-grid">
    <div><label class="form-label req">Nombre del rol</label><input class="form-control" name="nombre" maxlength="60" value="<?= e($base['nombre']) ?>" required <?= $r && $r['sistema'] ? 'readonly' : '' ?><?= $bloqueado ?> placeholder="Ej.: Técnico de soporte, Contador…"></div>
    <div><label class="form-label">Alcance de los datos</label>
      <select class="form-select" name="alcance" <?= ($r && $r['sistema']) ? 'disabled' : '' ?><?= $bloqueado ?>><?= options(ALCANCES, $base['alcance']) ?></select>
      <div class="form-text">Define cuántos tickets y equipos ve el usuario, sin importar los módulos habilitados. «Solo su empresa» exige que el usuario tenga una empresa asignada.</div></div>
    <div class="full"><label class="form-label">Descripción</label><input class="form-control" name="descripcion" maxlength="255" value="<?= e($base['descripcion']) ?>" <?= $bloqueado ?>></div>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mt-4 mb-2">
    <h6 class="sec m-0">Módulos y acciones permitidas</h6>
    <?php if ($puedeEditar): ?>
    <div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="ver">Solo consulta</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="todo">Marcar todo</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="nada">Quitar todo</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl perm-matrix">
    <thead><tr><th>Módulo</th><?php foreach (ACCIONES as $k => $lbl): ?><th class="c"><?= e($lbl) ?></th><?php endforeach; ?><th class="c">Fila</th></tr></thead>
    <tbody>
    <?php foreach (MODULOS as $mod => [$nom, $ico, $acciones]): ?>
      <tr data-mod="<?= e($mod) ?>">
        <td class="nowrap"><i class="bi bi-<?= e($ico) ?> me-2 muted"></i><strong><?= e($nom) ?></strong></td>
        <?php foreach (ACCIONES as $acc => $lbl): ?>
          <td class="c">
            <?php if (isset($acciones[$acc])): ?>
              <input class="form-check-input perm" type="checkbox" name="perm[<?= e($mod) ?>][<?= e($acc) ?>]" value="1" data-acc="<?= e($acc) ?>"
                title="<?= e($acciones[$acc]) ?>" aria-label="<?= e($nom . ': ' . $acciones[$acc]) ?>"
                <?= $esAdmin || isset($marcados[$mod . '.' . $acc]) ? 'checked' : '' ?><?= $bloqueado ?>>
            <?php else: ?><span class="muted">·</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td class="c"><?php if ($puedeEditar): ?><button type="button" class="btn btn-sm btn-link p-0" data-row-toggle>todo</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <details class="mt-3 small">
    <summary class="muted">¿Qué autoriza cada acción?</summary>
    <ul class="mt-2 mb-0">
      <?php foreach (MODULOS as $mod => [$nom, $ico, $acciones]): ?>
        <li><strong><?= e($nom) ?>:</strong> <?= e(implode(' · ', array_map(fn($k, $v) => ACCIONES[$k] . ' = ' . $v, array_keys($acciones), $acciones))) ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <p class="small muted mt-2 mb-0">Cualquier acción habilitada en un módulo incluye automáticamente <strong>Consultar</strong>. El menú solo muestra los módulos que el rol puede consultar.</p>

  <div class="form-actions">
    <?php if ($puedeEditar): ?><button class="btn btn-primary">Guardar rol</button><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= url('roles') ?>">Volver</a>
    <?php if ($r && !$r['sistema'] && can('roles', 'eliminar')): ?>
      <button class="btn btn-outline-danger ms-auto" name="accion" value="eliminar" formnovalidate onclick="return confirm('¿Eliminar este rol?')"><i class="bi bi-trash me-1"></i>Eliminar rol</button>
    <?php endif; ?>
  </div>
</form>
<?php if ($puedeEditar): ?>
<script>
(function () {
  var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.perm-matrix input.perm')); };
  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = b.getAttribute('data-preset');
      boxes().forEach(function (c) { c.checked = p === 'todo' || (p === 'ver' && c.getAttribute('data-acc') === 'ver'); });
    });
  });
  document.querySelectorAll('[data-row-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var cs = Array.prototype.slice.call(b.closest('tr').querySelectorAll('input.perm'));
      var all = cs.every(function (c) { return c.checked; });
      cs.forEach(function (c) { c.checked = !all; });
    });
  });
  // Marcar una acción marca también «Consultar» de ese módulo
  document.addEventListener('change', function (e) {
    var c = e.target;
    if (!c.matches || !c.matches('input.perm') || !c.checked || c.getAttribute('data-acc') === 'ver') return;
    var ver = c.closest('tr').querySelector('input.perm[data-acc="ver"]');
    if (ver) ver.checked = true;
  });
})();
</script>
<?php endif; ?>
