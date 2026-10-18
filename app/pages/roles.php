<?php
require_perm('roles', 'ver');
$title = 'Roles y permisos';

$roles = roles_todos();
$nPerm = array_column(all('SELECT rol_id, COUNT(*) n FROM rol_permisos GROUP BY rol_id'), 'n', 'rol_id');
$total = 0;
foreach (MODULOS as $m) $total += count($m[2]);
?>
<div class="panel">
  <div class="panel-h">
    <h3>Roles</h3>
    <div class="actions">
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('usuarios') ?>"><i class="bi bi-people me-1"></i>Usuarios</a>
      <?php if (can('roles', 'crear')): ?><a class="btn btn-sm btn-primary" href="<?= url('rol_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nuevo rol</a><?php endif; ?>
    </div>
  </div>
  <p class="small muted">Un rol define <strong>qué módulos</strong> puede abrir un usuario y <strong>qué acciones</strong> puede hacer en cada uno (consultar, crear, editar, eliminar, aprobar y administrar), además del alcance de la información que ve. A cada usuario se le asigna un rol en <a href="<?= url('usuarios') ?>">Usuarios</a>.</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Rol</th><th>Descripción</th><th>Alcance de los datos</th><th class="c">Permisos</th><th class="c">Usuarios activos</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
      <tr>
        <td class="nowrap"><strong><?= e($r['nombre']) ?></strong> <?= $r['sistema'] ? badge('Del sistema', 'secondary') : '' ?></td>
        <td class="muted small"><?= e($r['descripcion']) ?></td>
        <td class="small"><?= e(ALCANCES[$r['alcance']] ?? $r['alcance']) ?></td>
        <td class="c"><?= $r['codigo'] === 'admin' ? 'Todos' : (int)($nPerm[$r['id']] ?? 0) . ' de ' . $total ?></td>
        <td class="c"><?= (int)$r['n_usuarios'] ?></td>
        <td class="r nowrap">
          <?php if (can('roles', 'crear') && $r['codigo'] !== 'admin'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('rol_form', ['copiar' => $r['id']]) ?>" title="Crear un rol nuevo a partir de este"><i class="bi bi-copy"></i></a><?php endif; ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= url('rol_form', ['id' => $r['id']]) ?>"><i class="bi bi-<?= can('roles', 'editar') && $r['codigo'] !== 'admin' ? 'pencil' : 'eye' ?>"></i> <?= can('roles', 'editar') && $r['codigo'] !== 'admin' ? 'Editar' : 'Ver' ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
