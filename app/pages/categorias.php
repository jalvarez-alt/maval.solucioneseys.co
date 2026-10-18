<?php
require_perm('categorias', 'ver');
$title = 'Categorías de gasto';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    require_perm('categorias', ['crear' => 'crear', 'eliminar' => 'eliminar'][$acc] ?? 'editar');
    if ($acc === 'crear' && post('nombre')) {
        if (val('SELECT id FROM gasto_categorias WHERE nombre = ?', [post('nombre')])) flash('Esa categoría ya existe.', 'warning');
        else { insert('gasto_categorias', ['nombre' => mb_substr((string)post('nombre'), 0, 80), 'activo' => 1]); flash('Categoría creada.'); }
    } elseif ($acc === 'renombrar' && post('nombre')) {
        update('gasto_categorias', ['nombre' => mb_substr((string)post('nombre'), 0, 80)], (int)post('id'));
        flash('Categoría actualizada.');
    } elseif ($acc === 'estado') {
        q('UPDATE gasto_categorias SET activo = 1 - activo WHERE id = ?', [(int)post('id')]);
        flash('Estado actualizado.');
    } elseif ($acc === 'eliminar') {
        $cid = (int)post('id');
        if (val('SELECT COUNT(*) FROM gastos WHERE categoria_id = ?', [$cid]) > 0) flash('No se puede eliminar: tiene gastos registrados. Desactívela en su lugar.', 'warning');
        else { q('DELETE FROM gasto_categorias WHERE id = ?', [$cid]); flash('Categoría eliminada.'); }
    }
    redirect(url('categorias'));
}
$anio = (int)date('Y');
$rows = all('SELECT c.*, (SELECT COUNT(*) FROM gastos g WHERE g.categoria_id = c.id) AS n,
    (SELECT COALESCE(SUM(g.valor),0) FROM gastos g WHERE g.categoria_id = c.id AND YEAR(g.fecha) = ?) AS total_anio
    FROM gasto_categorias c ORDER BY c.activo DESC, c.nombre', [$anio]);
?>
<div class="panel" style="max-width:900px">
  <?php if (can('categorias', 'crear')): ?>
  <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <input class="form-control grow" name="nombre" maxlength="80" placeholder="Nueva categoría (ej.: Publicidad)" required>
    <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
  </form>
  <?php endif; ?>
  <table class="tbl">
    <thead><tr><th>Nombre</th><th class="c">Registros</th><th class="r">Gastado <?= $anio ?></th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['activo'] ? '' : 'muted' ?>">
        <td>
          <?php if (can('categorias', 'editar')): ?>
          <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="renombrar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input class="form-control form-control-sm" name="nombre" value="<?= e($r['nombre']) ?>" maxlength="80"><button class="btn btn-sm btn-outline-secondary" title="Guardar nombre"><i class="bi bi-check-lg"></i></button></form>
          <?php else: ?><?= e($r['nombre']) ?><?php endif; ?>
        </td>
        <td class="c"><?= (int)$r['n'] ?></td>
        <td class="r"><?= money($r['total_anio']) ?></td>
        <td><?= $r['activo'] ? badge('Activo') : badge('Inactiva', 'secondary') ?></td>
        <td class="r nowrap">
          <?php if (can('categorias', 'editar')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><?= $r['activo'] ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?>
          <?php if (!$r['n'] && can('categorias', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar la categoría?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
