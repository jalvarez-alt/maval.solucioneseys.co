<?php
require_perm('empresas', 'ver');
$title = 'Empresas';
$tipo = get('tipo', '');
$where = '1=1';
$params = [];
if (in_array($tipo, ['propia', 'cliente'], true)) { $where = 'e.tipo = ?'; $params[] = $tipo; }
$rows = all("SELECT e.*,
    (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id) AS n_usuarios,
    (SELECT COUNT(*) FROM equipos q WHERE q.empresa_id = e.id) AS n_equipos,
    (SELECT COUNT(*) FROM tickets t WHERE t.empresa_id = e.id AND t.estado IN ('Abierto','En proceso','En espera')) AS n_tickets
    FROM empresas e WHERE $where ORDER BY e.tipo DESC, e.activo DESC, e.nombre", $params);

// Excel con las empresas del listado (mismas columnas que la plantilla de importación)
if (get('exportar')) { require_once APP_DIR . '/empresas_importar.php'; emp_exportar($rows); }
?>
<div class="panel">
  <div class="tabs">
    <a class="<?= $tipo === '' ? 'on' : '' ?>" href="<?= url('empresas') ?>">Todas</a>
    <a class="<?= $tipo === 'propia' ? 'on' : '' ?>" href="<?= url('empresas', ['tipo' => 'propia']) ?>">Propias (cartera)</a>
    <a class="<?= $tipo === 'cliente' ? 'on' : '' ?>" href="<?= url('empresas', ['tipo' => 'cliente']) ?>">Clientes</a>
    <span class="ms-auto d-flex gap-2 pb-1">
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('empresas', array_filter(['tipo' => $tipo, 'exportar' => 1]))) ?>"><i class="bi bi-download me-1"></i>Excel</a>
      <?php if (can('empresas', 'crear')): ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= url('empresas_importar') ?>"><i class="bi bi-upload me-1"></i>Importar Excel</a>
      <a class="btn btn-primary btn-sm" href="<?= url('empresa_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nueva empresa</a>
      <?php endif; ?>
    </span>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Empresa</th><th>NIT</th><th>Tipo</th><th>Contacto</th><th>Teléfono</th><th class="c">Usuarios</th><th class="c">Equipos</th><th class="c">Tickets abiertos</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= can('empresas', 'editar') ? '<a class="row-link" href="' . url('empresa_form', ['id' => $r['id']]) . '">' . e($r['nombre']) . '</a>' : e($r['nombre']) ?></td>
        <td><?= e($r['nit']) ?></td>
        <td><?= $r['tipo'] === 'propia' ? badge('Propia', 'primary') : badge('Cliente', 'info') ?></td>
        <td><?= e($r['contacto']) ?></td>
        <td><?= e($r['telefono']) ?></td>
        <td class="c"><?= (int)$r['n_usuarios'] ?></td>
        <td class="c"><a href="<?= url('inventario', ['empresa' => $r['id']]) ?>"><?= (int)$r['n_equipos'] ?></a></td>
        <td class="c"><a href="<?= url('tickets', ['empresa' => $r['id']]) ?>"><?= (int)$r['n_tickets'] ?></a></td>
        <td><?= $r['activo'] ? badge('Activo') : badge('Inactivo', 'secondary') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Sin empresas registradas.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="small muted mt-3 mb-0"><strong>Propias:</strong> las empresas de su negocio que emiten facturas (cartera) y a las que se cargan gastos. <strong>Clientes:</strong> empresas atendidas por la mesa de ayuda y dueñas de equipos en el inventario.</p>
</div>
