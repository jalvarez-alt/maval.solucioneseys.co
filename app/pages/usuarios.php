<?php
require_role('admin');
$title = 'Usuarios';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'estado') {
    $id = (int)post('id');
    if ($id === (int)user()['id']) {
        flash('No puede desactivar su propio usuario.', 'warning');
    } else {
        q('UPDATE usuarios SET activo = 1 - activo WHERE id = ?', [$id]);
        flash('Estado del usuario actualizado.');
    }
    redirect(url('usuarios', array_filter(['rol' => get('rol'), 'b' => get('b')])));
}

$where = ['1=1'];
$params = [];
if ($b = get('b')) { $where[] = '(u.nombre LIKE ? OR u.email LIKE ?)'; $params[] = "%$b%"; $params[] = "%$b%"; }
if (($rol = get('rol')) && isset(ROLES[$rol])) { $where[] = 'u.rol = ?'; $params[] = $rol; }
$rows = all('SELECT u.*, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.activo DESC, u.nombre', $params);
$porRol = array_column(all('SELECT rol, COUNT(*) n FROM usuarios WHERE activo = 1 GROUP BY rol'), 'n', 'rol');
?>
<div class="kpis">
  <?php foreach (ROLES as $k => $lbl): ?>
  <div class="kpi"><div class="lbl"><?= badge($k) ?></div><div class="v"><?= (int)($porRol[$k] ?? 0) ?></div><div class="sub">activos</div></div>
  <?php endforeach; ?>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="usuarios">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Buscar por nombre o correo">
    <select class="form-select" name="rol" data-autosubmit><option value="">Todos los roles</option><?= options(ROLES, get('rol')) ?></select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <div class="actions"><a class="btn btn-primary" href="<?= url('usuario_form') ?>"><i class="bi bi-person-plus me-1"></i>Nuevo usuario</a></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Empresa</th><th>Cargo</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['activo'] ? '' : 'muted' ?>">
        <td><a class="row-link" href="<?= url('usuario_form', ['id' => $r['id']]) ?>"><?= e($r['nombre']) ?></a></td>
        <td><?= e($r['email']) ?></td>
        <td><?= badge($r['rol']) ?></td>
        <td><?= e($r['empresa'] ?? '—') ?></td>
        <td><?= e($r['cargo'] ?? '') ?></td>
        <td class="nowrap"><?= fdate($r['ultimo_acceso'], true) ?></td>
        <td><?= $r['activo'] ? badge('Activo') : badge('Inactivo', 'secondary') ?></td>
        <td class="r">
          <form method="post" class="d-inline" data-confirm="¿Cambiar el estado de este usuario?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>"><i class="bi bi-<?= $r['activo'] ? 'pause' : 'play' ?>"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No hay usuarios con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
