<?php
require_once APP_DIR . '/cotizaciones.php';
$title = 'Cotizaciones';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('accion') === 'eliminar') {
    require_perm('cotizaciones', 'eliminar');
    $n = val('SELECT consecutivo FROM cotizaciones WHERE id = ?', [(int)post('id')]);
    q('DELETE FROM cotizaciones WHERE id = ?', [(int)post('id')]);
    flash('Cotización No. ' . $n . ' eliminada.');
    redirect('index.php?' . http_build_query($_GET));
}

$where = ['1=1']; $params = [];
$estado = get('estado', '');
if (in_array($estado, COT_ESTADOS, true)) { $where[] = 'c.estado = ?'; $params[] = $estado; }
if (isset(COT_FORMATOS[get('formato', '')])) { $where[] = 'c.formato = ?'; $params[] = get('formato'); }
if ($anio = (int)get('anio', 0)) { $where[] = 'YEAR(c.fecha) = ?'; $params[] = $anio; }
if ($b = get('b')) {
    $where[] = '(c.cliente_nombre LIKE ? OR c.cliente_nit LIKE ? OR c.atencion LIKE ? OR CAST(c.consecutivo AS CHAR) = ?)';
    array_push($params, "%$b%", "%$b%", "%$b%", $b);
}
$w = implode(' AND ', $where);
$per = 30;
$page = max(1, (int)get('pg', 1));
$total = (int)val("SELECT COUNT(*) FROM cotizaciones c WHERE $w", $params);
$rows = all("SELECT c.*, (SELECT COUNT(*) FROM cotizacion_items i WHERE i.cotizacion_id = c.id) AS n_items FROM cotizaciones c WHERE $w ORDER BY c.consecutivo DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);

$k = fn(string $cond, array $p = []) => all("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM cotizaciones WHERE $cond", $p)[0];
$kMes = $k('YEAR(fecha) = ? AND MONTH(fecha) = ? AND estado <> \'Anulada\'', [(int)date('Y'), (int)date('n')]);
$kPend = $k("estado IN ('Borrador', 'Enviada')");
$kAcep = $k("estado = 'Aceptada' AND YEAR(fecha) = ?", [(int)date('Y')]);
$anios = array_column(all('SELECT DISTINCT YEAR(fecha) a FROM cotizaciones ORDER BY a DESC'), 'a');
$qs = array_filter(['p' => 'cotizaciones', 'estado' => $estado, 'formato' => get('formato'), 'anio' => get('anio'), 'b' => get('b')]);
?>
<div class="kpis">
  <div class="kpi accent"><div class="lbl"><i class="bi bi-file-earmark-text"></i>Cotizadas en <?= MESES[(int)date('n')] ?></div><div class="v"><?= (int)$kMes['n'] ?></div><div class="sub"><?= money($kMes['t']) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-hourglass-split"></i>Pendientes de respuesta</div><div class="v"><?= (int)$kPend['n'] ?></div><div class="sub"><?= money($kPend['t']) ?> · borrador y enviadas</div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-check2-circle"></i>Aceptadas <?= date('Y') ?></div><div class="v"><?= (int)$kAcep['n'] ?></div><div class="sub"><?= money($kAcep['t']) ?></div></div>
  <div class="kpi"><div class="lbl"><i class="bi bi-hash"></i>Próximo consecutivo</div><div class="v"><?= cot_siguiente() ?></div></div>
</div>
<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="cotizaciones">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Número, cliente, NIT o contacto">
    <select class="form-select" name="estado" data-autosubmit><option value="">Todo estado</option><?= options(COT_ESTADOS, $estado, false) ?></select>
    <select class="form-select" name="formato" data-autosubmit><option value="">Todo formato</option><?= options(COT_FORMATOS, get('formato')) ?></select>
    <?php if ($anios): ?><select class="form-select" name="anio" data-autosubmit><option value="">Todo año</option><?= options(array_combine($anios, $anios), get('anio')) ?></select><?php endif; ?>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <div class="actions"><?php if (can('cotizaciones', 'crear')): ?><a class="btn btn-primary" href="<?= url('cotizacion_form') ?>"><i class="bi bi-plus-lg me-1"></i>Nueva cotización</a><?php endif; ?></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>No.</th><th>Fecha</th><th>Cliente</th><th>Formato</th><th class="c">Ítems</th><th class="r">Total</th><th>Estado</th><th>Enviada</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><a class="row-link" href="<?= url('cotizacion', ['id' => $r['id']]) ?>"><?= (int)$r['consecutivo'] ?></a></td>
        <td class="nowrap muted"><?= fdate($r['fecha']) ?></td>
        <td><a class="row-link fw-normal" href="<?= url('cotizacion', ['id' => $r['id']]) ?>"><?= e($r['cliente_nombre']) ?></a><?= $r['atencion'] ? '<br><small class="muted">' . e($r['atencion']) . '</small>' : '' ?></td>
        <td class="small muted"><?= $r['formato'] === 'propuesta' ? 'Propuesta' : 'Con ítems' ?></td>
        <td class="c"><?= (int)$r['n_items'] ?></td>
        <td class="r nowrap"><?= e(cot_total_texto($r)) ?></td>
        <td><?= badge($r['estado']) ?></td>
        <td class="nowrap small muted"><?= $r['enviada'] ? fdate($r['enviada'], true) : '—' ?></td>
        <td class="r nowrap">
          <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('cotizacion', ['id' => $r['id'], 'pdf' => 'ver']) ?>" title="Ver PDF"><i class="bi bi-file-earmark-pdf"></i></a>
          <?php if (can('cotizaciones', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar la cotización No. <?= (int)$r['consecutivo'] ?>?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">No hay cotizaciones con esos filtros.<?= can('cotizaciones', 'crear') ? ' <a href="' . url('cotizacion_form') . '">Crear la primera</a>' : '' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
