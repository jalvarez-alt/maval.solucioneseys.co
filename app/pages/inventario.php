<?php
$title = 'Otros equipos';
[$scope, $params] = equipo_scope('eq');
$where = [$scope, 'eq.tipo IN (' . implode(',', array_fill(0, count(OTROS_TIPOS), '?')) . ')'];
$params = array_merge($params, OTROS_TIPOS);
if (($tipo = get('tipo')) && in_array($tipo, OTROS_TIPOS, true)) { $where[] = 'eq.tipo = ?'; $params[] = $tipo; }
$estado = get('estado', 'vigentes');
if ($estado === 'vigentes') $where[] = "eq.estado <> 'Dado de baja'";
elseif (in_array($estado, EQUIPO_ESTADOS, true)) { $where[] = 'eq.estado = ?'; $params[] = $estado; }
if (is_admin() && ($emp = (int)get('empresa', 0))) { $where[] = 'eq.empresa_id = ?'; $params[] = $emp; }
if ($b = get('b')) {
    $where[] = '(eq.codigo LIKE ? OR eq.serial LIKE ? OR eq.marca LIKE ? OR eq.modelo LIKE ? OR eq.responsable LIKE ? OR eq.imei LIKE ? OR eq.linea LIKE ? OR eq.ubicacion LIKE ? OR u.nombre LIKE ?)';
    for ($i = 0; $i < 9; $i++) $params[] = "%$b%";
}
$w = implode(' AND ', $where);
require_once APP_DIR . '/masivo.php';
masivo_procesar($w, $params);
$sql = "SELECT eq.*, emp.nombre AS empresa, u.nombre AS usuario, u.email AS usuario_email,
        (SELECT COUNT(*) FROM tickets t WHERE t.equipo_id = eq.id AND t.estado IN ('Abierto','En proceso','En espera')) AS tickets_abiertos
    FROM equipos eq
    LEFT JOIN empresas emp ON emp.id = eq.empresa_id
    LEFT JOIN usuarios u ON u.id = eq.usuario_id
    WHERE $w ORDER BY emp.nombre, eq.tipo, eq.codigo";

// Exportar a Excel (mismas columnas que la plantilla de importación)
if (get('exportar') && is_admin()) {
    require_once APP_DIR . '/lib/xlsx.php';
    $rows = [];
    foreach (all($sql, $params) as $r) {
        $rows[] = [$r['codigo'], $r['tipo'], $r['marca'], $r['modelo'], $r['serial'], $r['empresa'], $r['responsable'], $r['usuario_email'], $r['ubicacion'], $r['estado'],
            $r['imei'], $r['linea'], $r['ip'], $r['fecha_compra'], $r['valor'] !== null ? (float)$r['valor'] : null, $r['garantia_hasta'], $r['especificaciones'], $r['notas']];
    }
    xlsx_download('otros_equipos_' . date('Ymd_Hi') . '.xlsx', [['name' => 'Equipos',
        'headers' => ['Código', 'Tipo', 'Marca', 'Modelo', 'Serial', 'Propietario', 'Asignado a', 'Usuario mesa de ayuda', 'Ubicación', 'Estado', 'IMEI', 'Línea', 'IP', 'Fecha compra', 'Valor compra', 'Garantía hasta', 'Especificaciones', 'Notas'],
        'rows' => $rows, 'widths' => [11, 15, 12, 18, 14, 24, 20, 26, 16, 13, 18, 14, 14, 13, 13, 14, 30, 30]]]);
}

$per = 30;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM equipos eq LEFT JOIN usuarios u ON u.id = eq.usuario_id WHERE $w", $params);
$rows = all($sql . " LIMIT $per OFFSET " . (($page - 1) * $per), $params);

[$s2, $p2] = equipo_scope('eq');
$porTipo = array_column(all("SELECT eq.tipo, COUNT(*) n FROM equipos eq WHERE $s2 AND eq.estado <> 'Dado de baja' GROUP BY eq.tipo", $p2), 'n', 'tipo');
$grupos = [
    'Celulares y tablets' => ['icon' => 'phone', 'tipos' => ['Celular', 'Tablet', 'Teléfono IP']],
    'Impresión' => ['icon' => 'printer', 'tipos' => ['Impresora', 'Escáner']],
    'Cámaras' => ['icon' => 'camera-video', 'tipos' => ['Cámara', 'DVR / NVR']],
    'Red, energía y otros' => ['icon' => 'router', 'tipos' => ['Router / Switch', 'Access point', 'UPS', 'Monitor', 'Otro']],
];
$repar = (int)val("SELECT COUNT(*) FROM equipos eq WHERE $s2 AND eq.estado = 'En reparación' AND eq.tipo NOT IN ('" . implode("','", COMPUTO_TIPOS) . "')", $p2);
$qs = array_filter(['p' => 'inventario', 'tipo' => get('tipo'), 'estado' => $estado, 'empresa' => get('empresa'), 'b' => get('b')]);
?>
<div class="kpis">
  <?php foreach ($grupos as $g => $info):
      $n = 0; foreach ($info['tipos'] as $tp) $n += (int)($porTipo[$tp] ?? 0); ?>
    <div class="kpi"><div class="lbl"><i class="bi bi-<?= $info['icon'] ?>"></i><?= e($g) ?></div><div class="v"><?= $n ?></div></div>
  <?php endforeach; ?>
  <div class="kpi warn"><div class="lbl"><i class="bi bi-tools"></i>En reparación</div><div class="v"><?= $repar ?></div></div>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="inventario">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Código, serial, marca, IMEI, responsable…">
    <select class="form-select" name="tipo" data-autosubmit><option value="">Todos los tipos</option><?= options(OTROS_TIPOS, get('tipo'), false) ?></select>
    <select class="form-select" name="estado" data-autosubmit>
      <option value="vigentes" <?= $estado === 'vigentes' ? 'selected' : '' ?>>Vigentes</option>
      <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option>
      <?= options(EQUIPO_ESTADOS, $estado, false) ?>
    </select>
    <?php if (is_admin()): ?><select class="form-select" name="empresa" data-autosubmit><option value="">Toda empresa</option><?= options(empresas_list(), get('empresa')) ?></select><?php endif; ?>
    <div class="actions">
      <?php if (is_admin()): ?>
        <a class="btn btn-outline-secondary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 1])) ?>"><i class="bi bi-download me-1"></i>Excel</a>
        <a class="btn btn-outline-primary" href="<?= url('importar', ['inv' => 'otros']) ?>"><i class="bi bi-upload me-1"></i>Importar</a>
        <a class="btn btn-primary" href="<?= url('equipo_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nuevo equipo</a>
      <?php endif; ?>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><?= masivo_check_all() ?><th>Código</th><th>Tipo</th><th>Marca / modelo</th><th>Serial</th><?php if (!is_role('empresa')): ?><th>Empresa</th><?php endif; ?><th>Asignado a</th><th>Ubicación</th><th>Estado</th><th class="c">Tickets</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?= masivo_check((int)$r['id']) ?>
        <td class="nowrap"><a class="row-link" href="<?= url('equipo', ['id' => $r['id']]) ?>"><?= e($r['codigo']) ?></a></td>
        <td><?= e($r['tipo']) ?></td>
        <td><?= e(trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')) ?: '—') ?></td>
        <td class="muted"><?= e($r['serial'] ?: '—') ?></td>
        <?php if (!is_role('empresa')): ?><td><?= e($r['empresa'] ?? '—') ?></td><?php endif; ?>
        <td><?= e($r['responsable'] ?: ($r['usuario'] ?: '—')) ?><?= $r['usuario'] ? ' <i class="bi bi-person-check text-success" title="Usuario de mesa de ayuda: ' . e($r['usuario']) . '"></i>' : '' ?></td>
        <td class="muted"><?= e($r['ubicacion'] ?: '—') ?></td>
        <td><?= badge($r['estado']) ?></td>
        <td class="c"><?= $r['tickets_abiertos'] ? badge((string)$r['tickets_abiertos'], 'warning') : '<span class="muted">0</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty"><?= is_admin() ? 'No hay equipos con esos filtros.' : 'No hay equipos de este tipo asignados.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
  <?= masivo_barra($total) ?>
</div>
