<?php
require_once APP_DIR . '/computo.php';
$title = 'Equipos de cómputo';
[$w, $params, $act, $f] = computo_filtros($_GET);
$f['estado'] = $f['estado'] ?? 'vigentes';
require_once APP_DIR . '/masivo.php';
masivo_procesar($w, $params);

$exp = get('exportar');
if ($exp === 'xlsx' || $exp === 'pdf') {
    $rows = computo_rows($w, $params);
    if ($exp === 'pdf') {
        require_once APP_DIR . '/lib/pdf.php';
        computo_pdf($rows, $act)->download('equipos_computo_' . date('Ymd_Hi') . '.pdf');
    }
    require_once APP_DIR . '/lib/xlsx.php';
    $hojaEq = [];
    foreach ($rows as $r) {
        $hojaEq[] = [$r['codigo'], computo_usuario($r), $r['usuario_email'], $r['cargo'], $r['correos'], $r['empresa'], $r['tipo'], $r['marca'], $r['modelo'], $r['procesador'], $r['serial'], $r['ram'], $r['disco'],
            $r['so_nombre'], $r['so_lic'] ?: 'Sin registrar', $r['of_nombre'], $r['of_lic'] ?: 'Sin registrar', $r['av_nombre'], $r['av_lic'] ?: 'Sin registrar', $r['av_vence'],
            $r['fecha_compra'], $r['valor'] !== null ? (float)$r['valor'] : null, $r['renovacion'], $r['estado'], $r['ubicacion'], $r['notas']];
    }
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $hojaSw = [];
    if ($ids) {
        $catN = SOFT_BASE + ['otro' => 'Otro'];
        foreach (all('SELECT s.*, eq.codigo, eq.responsable FROM equipo_software s JOIN equipos eq ON eq.id = s.equipo_id WHERE s.equipo_id IN (' . implode(',', $ids) . ') ORDER BY eq.codigo, s.id') as $s) {
            $hojaSw[] = [$s['codigo'], $s['responsable'], $catN[$s['categoria']] ?? $s['categoria'], $s['nombre'], $s['version'], $s['tipo_licencia'], $s['licencia'], $s['vence']];
        }
    }
    xlsx_download('equipos_computo_' . date('Ymd_Hi') . '.xlsx', [
        ['name' => 'Equipos', 'headers' => ['Código', 'Usuario', 'Usuario mesa de ayuda', 'Cargo', 'Correos', 'Propietario', 'Tipo', 'Marca', 'Modelo', 'Procesador', 'Serial', 'RAM', 'DD', 'Sistema operativo', 'Licencia S.O.', 'Ofimática', 'Licencia ofimática', 'Antivirus', 'Licencia antivirus', 'Antivirus vence', 'Fecha compra', 'Valor compra', 'Renovación', 'Estado', 'Ubicación', 'Observación'],
            'rows' => $hojaEq, 'widths' => [11, 24, 26, 18, 30, 22, 13, 12, 20, 24, 16, 9, 14, 22, 14, 22, 14, 22, 14, 13, 13, 14, 12, 13, 18, 40]],
        ['name' => 'Software', 'headers' => ['Código', 'Usuario', 'Categoría', 'Software', 'Versión', 'Tipo de licencia', 'Estado licencia', 'Vence'],
            'rows' => $hojaSw, 'widths' => [11, 24, 18, 30, 12, 16, 14, 13]],
    ]);
}

$per = 30;
$page = max(1, (int)get('pg', 1));
$total = computo_count($w, $params);
$rows = computo_rows($w, $params, "LIMIT $per OFFSET " . (($page - 1) * $per));
$qs = array_filter(['p' => 'computo_lista'] + $f, fn($v) => $v !== null && $v !== '');
?>
<div class="tabs">
  <a href="<?= e(computo_qs($f, [], 'computo')) ?>">Dashboard</a>
  <a class="on" href="<?= e(computo_qs($f)) ?>">Equipos</a>
  <span class="ms-auto d-flex gap-2 pb-1 flex-wrap">
    <a class="btn btn-sm btn-outline-primary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel del filtro</a>
    <a class="btn btn-sm btn-outline-primary" href="index.php?<?= e(http_build_query($qs + ['exportar' => 'pdf'])) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF del filtro</a>
    <?php if (is_admin()): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('importar', ['inv' => 'computo']) ?>"><i class="bi bi-upload me-1"></i>Importar</a>
    <a class="btn btn-sm btn-primary" href="<?= url('computo_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nuevo equipo</a><?php endif; ?>
  </span>
</div>

<?= computo_filtros_form($f, $act, 'computo_lista') ?>

<div class="panel">
  <div class="panel-h"><h3><?= $total ?> equipo<?= $total === 1 ? '' : 's' ?></h3></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><?= masivo_check_all() ?><th>Usuario / cargo</th><?php if (!is_role('empresa')): ?><th>Propietario</th><?php endif; ?><th>Tipo</th><th>Marca / modelo</th><th>Procesador</th><th>RAM</th><th>DD</th><th>S.O.</th><th>Ofimática</th><th>Antivirus</th><th>Compra</th><th>Renovación</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?= masivo_check((int)$r['id']) ?>
        <td><a class="row-link" href="<?= url('equipo', ['id' => $r['id']]) ?>"><?= e(computo_usuario($r)) ?></a><?= $r['usuario_sistema'] ? ' <i class="bi bi-person-check text-success" title="Usuario de mesa de ayuda: ' . e($r['usuario_sistema']) . '"></i>' : '' ?><br><small class="muted"><?= e($r['cargo'] ?: '') ?><?= $r['cargo'] ? ' · ' : '' ?><?= e($r['codigo']) ?></small>
          <?= $r['tickets_abiertos'] ? '<br>' . badge($r['tickets_abiertos'] . ' ticket(s)', 'warning') : '' ?></td>
        <?php if (!is_role('empresa')): ?><td><?= e($r['empresa'] ?? '—') ?></td><?php endif; ?>
        <td><?= e($r['tipo']) ?><?= $r['estado'] !== 'Activo' ? '<br>' . badge($r['estado']) : '' ?></td>
        <td><?= e(trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')) ?: '—') ?><?= $r['serial'] ? '<br><small class="muted">S/N ' . e($r['serial']) . '</small>' : '' ?></td>
        <td><?= e($r['procesador'] ?: '—') ?></td>
        <td class="nowrap"><?= e($r['ram'] ?: '—') ?></td>
        <td class="nowrap"><?= e($r['disco'] ?: '—') ?></td>
        <td><?= lic_badge($r['so_lic'], $r['so_nombre']) ?></td>
        <td><?= lic_badge($r['of_lic'], $r['of_nombre']) ?></td>
        <td><?= lic_badge($r['av_lic'], $r['av_nombre']) ?><?= $r['av_vence'] && $r['av_vence'] < today() ? '<br><small class="text-danger">Vencido ' . fdate($r['av_vence']) . '</small>' : '' ?></td>
        <td class="nowrap"><?= fdate($r['fecha_compra']) ?></td>
        <td><?= $r['renovacion'] ? badge($r['renovacion']) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="13" class="empty">No hay equipos con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
  <?= masivo_barra($total) ?>
</div>
