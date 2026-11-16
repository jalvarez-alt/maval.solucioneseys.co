<?php
require_once APP_DIR . '/informes.php';
$title = 'Informes de servicio';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'eliminar') {
    require_perm('informes', 'eliminar');
    $iid = (int)post('id');
    if ($x = one('SELECT consecutivo FROM informes WHERE id = ?', [$iid])) {
        foreach (adjuntos('informe', $iid) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
        q("DELETE FROM adjuntos WHERE entidad = 'informe' AND entidad_id = ?", [$iid]);
        q('DELETE FROM informes WHERE id = ?', [$iid]);
        flash('Informe ' . inf_codigo($x) . ' eliminado.');
    }
    redirect('index.php?' . http_build_query($_GET));
}

$where = ['1=1']; $params = [];
$tipo = get('tipo', ''); $estado = get('estado', '');
if (isset(INF_TIPOS[$tipo])) { $where[] = 'i.tipo = ?'; $params[] = $tipo; }
if (in_array($estado, INF_ESTADOS, true)) { $where[] = 'i.estado = ?'; $params[] = $estado; }
if ($b = get('b')) {
    $where[] = '(i.cliente_nombre LIKE ? OR i.cliente_nit LIKE ? OR i.sede LIKE ? OR i.tecnico_nombre LIKE ? OR i.referencia LIKE ? OR CAST(i.consecutivo AS CHAR) = ?)';
    array_push($params, "%$b%", "%$b%", "%$b%", "%$b%", "%$b%", ltrim(preg_replace('/^IS-?/i', '', $b), '0'));
}
$w = implode(' AND ', $where);
$per = 30; $page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM informes i WHERE $w", $params);
$rows = all("SELECT i.* FROM informes i WHERE $w ORDER BY i.consecutivo DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$kMes = (int)val('SELECT COUNT(*) FROM informes WHERE YEAR(fecha_inicio) = ? AND MONTH(fecha_inicio) = ?', [(int)date('Y'), (int)date('n')]);
$kBorr = (int)val("SELECT COUNT(*) FROM informes WHERE estado = 'Borrador'");
$kFin = (int)val("SELECT COUNT(*) FROM informes WHERE estado = 'Finalizado' AND YEAR(fecha_inicio) = ?", [(int)date('Y')]);
$qs = array_filter(['p' => 'informes', 'tipo' => $tipo, 'estado' => $estado, 'b' => get('b')]);
?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-clipboard-check"></i>Servicios de <?= MESES[(int)date('n')] ?></div><div class="v"><?= $kMes ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-pencil-square"></i>En borrador</div><div class="v"><?= $kBorr ?></div><div class="sub">Pendientes de finalizar</div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Finalizados <?= date('Y') ?></div><div class="v"><?= $kFin ?></div></div>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="informes">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Número, cliente, NIT, sede, técnico o referencia">
    <select class="form-select" name="tipo" data-autosubmit><option value="">Todo tipo</option><?php foreach (INF_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($t['corto']) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="estado" data-autosubmit><option value="">Todo estado</option><?= options(INF_ESTADOS, $estado, false) ?></select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if (get('b') !== null || $tipo || $estado): ?><a class="btn btn-outline-secondary" href="<?= url('informes') ?>">Limpiar</a><?php endif; ?>
    <div class="actions">
      <?php if (can('informes', 'crear')): ?>
      <div class="dropdown">
        <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg me-1"></i>Nuevo informe</button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach (INF_TIPOS as $k => $t): ?><li><a class="dropdown-item" href="<?= url('informe_form', ['tipo' => $k]) ?>"><i class="bi bi-<?= e($t['icono']) ?> me-2"></i><?= e($t['nombre']) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>No.</th><th>Fecha</th><th>Cliente</th><th>Servicio</th><th>Técnico</th><th>Estado</th><th>Enviado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $t = INF_TIPOS[$r['tipo']] ?? ['corto' => $r['tipo'], 'icono' => 'clipboard-check']; ?>
      <tr>
        <td class="nowrap"><a class="row-link" href="<?= url('informe', ['id' => $r['id']]) ?>"><?= e(inf_codigo($r)) ?></a></td>
        <td class="nowrap muted"><?= fdate($r['fecha_fin'] ?: $r['fecha_inicio']) ?></td>
        <td><a class="row-link fw-normal" href="<?= url('informe', ['id' => $r['id']]) ?>"><?= e($r['cliente_nombre']) ?></a><?= $r['sede'] ? '<br><small class="muted">' . e($r['sede']) . '</small>' : '' ?></td>
        <td class="small"><i class="bi bi-<?= e($t['icono']) ?> me-1 muted"></i><?= e($t['corto']) ?></td>
        <td class="small muted"><?= e($r['tecnico_nombre'] ?: '—') ?></td>
        <td><?= badge($r['estado']) ?></td>
        <td class="nowrap small muted"><?= $r['enviado'] ? fdate($r['enviado'], true) : '—' ?></td>
        <td class="r nowrap">
          <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('informe', ['id' => $r['id'], 'pdf' => 'ver']) ?>" title="Ver PDF"><i class="bi bi-file-earmark-pdf"></i></a>
          <?php if (can('informes', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar el informe <?= e(inf_codigo($r)) ?> y sus fotos?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No hay informes con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
