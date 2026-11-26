<?php
require_once APP_DIR . '/boveda.php';
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
$title = 'Bóvedas de claves';
$esAdmin = can('boveda', 'administrar');
$vid = (int)get('v', 0);
$boveda = $vid ? bov_obtener($vid) : null;     // null si no existe o el usuario no tiene acceso (no se distingue a propósito)
if ($vid && !$boveda) not_found();
$vista = get('vista', '');
$vista = $boveda ? (in_array($vista, ['acceso', 'config'], true) && $esAdmin ? $vista : '') : ($vista === 'registro' && $esAdmin ? 'registro' : '');
$dek = $boveda ? bov_dek($vid) : null;          // null = bloqueada (si estaba desbloqueada, renueva el plazo de inactividad)
$recCode = null; $recBoveda = null;             // código de recuperación recién generado (se muestra una sola vez)

/** Respuesta JSON para las consultas de la página (copiar o ver un dato). */
function bov_json(array $d, int $http = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = post('accion');
    // Las claves se leen sin recortar espacios: forman parte de la frase o la contraseña
    $frase = (string)($_POST['frase'] ?? ''); $frase2 = (string)($_POST['frase2'] ?? '');
    $pv = (int)post('v', 0);
    $bv = $pv ? bov_obtener($pv) : null;
    $volver = $pv ? url('boveda', ['v' => $pv]) : url('boveda');
    if ($acc === 'revelar') {
        $fila = in_array(post('campo'), ['usuario', 'clave', 'notas'], true) ? one('SELECT * FROM boveda WHERE id = ?', [(int)post('id', 0)]) : null;
        $bf = $fila ? bov_obtener((int)$fila['boveda_id']) : null;
        if (!$bf) bov_json(['ok' => false, 'error' => 'Registro no encontrado.'], 404);
        if (!can('boveda', 'aprobar')) bov_json(['ok' => false, 'error' => 'No tiene permiso para ver contraseñas.'], 403);
        $dk = bov_dek((int)$bf['id']);
        if (!$dk) bov_json(['ok' => false, 'bloqueada' => true, 'error' => 'La bóveda está bloqueada. Desbloquéela de nuevo.'], 423);
        $valor = bov_secreto($dk, $fila, (string)post('campo'));
        if ($valor === null) bov_json(['ok' => false, 'error' => 'No se pudo descifrar el dato (se alteró el registro o cambió la llave).'], 500);
        bov_log(post('uso') === 'copiar' ? 'copiar' : 'ver', $bf, (int)$fila['id'], ['usuario' => 'usuario', 'clave' => 'contraseña', 'notas' => 'notas'][(string)post('campo')] . ' · ' . $fila['titulo']);
        bov_json(['ok' => true, 'valor' => $valor]);
    } elseif ($acc === 'crear_boveda') {
        require_perm('boveda', 'administrar');
        $emp = one('SELECT id, nombre FROM empresas WHERE id = ?', [(int)post('empresa_id', 0)]);
        if (!$emp) flash('Elija el cliente.', 'danger');
        elseif (val('SELECT COUNT(*) FROM bovedas WHERE empresa_id = ?', [$emp['id']])) flash('Ese cliente ya tiene su bóveda.', 'danger');
        elseif (mb_strlen($frase) < BOV_FRASE_MIN) flash('La frase maestra debe tener al menos ' . BOV_FRASE_MIN . ' caracteres.', 'danger');
        elseif ($frase !== $frase2) flash('Las dos frases no coinciden.', 'danger');
        else {
            [$nid, $nuevaDek, $recCode] = bov_crear((int)$emp['id'], $emp['nombre'], $frase);
            bov_abrir($nid, $nuevaDek);
            $recBoveda = bov_obtener($nid); bov_log('configurar', $recBoveda);
            // sin redirección: el código de recuperación solo se muestra en esta respuesta
        }
        if ($recCode === null) redirect(url('boveda'));
    } elseif ($acc === 'desbloquear' && $bv) {
        if (bov_bloqueado_por_intentos()) flash('Demasiados intentos fallidos. Espere 15 minutos e intente de nuevo.', 'danger');
        elseif (($d = bov_dek_con_frase($bv, $frase)) !== null) { bov_abrir((int)$bv['id'], $d); bov_log('desbloqueo', $bv); flash('Bóveda desbloqueada. Se bloquea sola tras ' . intdiv(BOV_INACTIVIDAD, 60) . ' minutos sin usarla.'); }
        else { bov_log('fallo', $bv, null, 'frase incorrecta'); flash('Frase incorrecta.', 'danger'); }
        redirect($volver);
    } elseif ($acc === 'bloquear' && $bv) {
        bov_bloquear((int)$bv['id']); bov_log('bloqueo', $bv); flash('Bóveda bloqueada.');
        redirect($volver);
    } elseif ($acc === 'recuperar' && $bv) {
        require_perm('boveda', 'administrar');
        if (bov_bloqueado_por_intentos()) flash('Demasiados intentos fallidos. Espere 15 minutos e intente de nuevo.', 'danger');
        elseif (mb_strlen($frase) < BOV_FRASE_MIN) flash('La frase maestra nueva debe tener al menos ' . BOV_FRASE_MIN . ' caracteres.', 'danger');
        elseif ($frase !== $frase2) flash('Las dos frases no coinciden.', 'danger');
        elseif (($d = bov_dek_con_codigo($bv, (string)($_POST['codigo'] ?? ''))) === null) { bov_log('fallo', $bv, null, 'código de recuperación incorrecto'); flash('Código de recuperación incorrecto.', 'danger'); }
        else { bov_guardar_frase((int)$bv['id'], $d, $frase); bov_abrir((int)$bv['id'], $d); bov_log('recuperacion', $bv); flash('Frase maestra restablecida y bóveda desbloqueada.'); }
        redirect($volver);
    } elseif ($acc === 'cambiar_frase' && $bv) {
        require_perm('boveda', 'administrar');
        $dk = bov_dek((int)$bv['id']);
        if (!$dk || bov_dek_con_frase($bv, (string)($_POST['actual'] ?? '')) === null) { bov_log('fallo', $bv, null, 'cambio de frase: frase actual incorrecta'); flash('La frase actual no es correcta o la bóveda está bloqueada.', 'danger'); }
        elseif (mb_strlen($frase) < BOV_FRASE_MIN) flash('La frase maestra nueva debe tener al menos ' . BOV_FRASE_MIN . ' caracteres.', 'danger');
        elseif ($frase !== $frase2) flash('Las dos frases no coinciden.', 'danger');
        else { bov_guardar_frase((int)$bv['id'], $dk, $frase); bov_log('cambio_frase', $bv); flash('Frase maestra cambiada.'); }
        redirect(url('boveda', ['v' => $pv, 'vista' => 'config']));
    } elseif ($acc === 'nuevo_codigo' && $bv) {
        require_perm('boveda', 'administrar');
        $dk = bov_dek((int)$bv['id']);
        if (!$dk || bov_dek_con_frase($bv, (string)($_POST['actual'] ?? '')) === null) { bov_log('fallo', $bv, null, 'nuevo código: frase incorrecta'); flash('La frase maestra no es correcta o la bóveda está bloqueada.', 'danger'); redirect(url('boveda', ['v' => $pv, 'vista' => 'config'])); }
        $recCode = bov_guardar_recuperacion((int)$bv['id'], $dk); $recBoveda = $bv; bov_log('nuevo_codigo', $bv);
    } elseif ($acc === 'acceso' && $bv) {
        require_perm('boveda', 'administrar');
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['usuarios'] ?? []))));
        q('DELETE FROM boveda_acceso WHERE boveda_id = ?', [$bv['id']]);
        foreach ($ids as $uid) if (val('SELECT COUNT(*) FROM usuarios WHERE id = ? AND activo = 1', [$uid])) q('INSERT INTO boveda_acceso (boveda_id, usuario_id) VALUES (?, ?)', [$bv['id'], $uid]);
        bov_log('acceso', $bv, null, count($ids) . ' usuario(s) con acceso');
        flash('Acceso actualizado.');
        redirect(url('boveda', ['v' => $pv, 'vista' => 'acceso']));
    } elseif ($acc === 'eliminar') {
        require_perm('boveda', 'eliminar');
        $x = one('SELECT id, boveda_id, titulo FROM boveda WHERE id = ?', [(int)post('id', 0)]);
        if ($x && ($bx = bov_obtener((int)$x['boveda_id']))) { q('DELETE FROM boveda WHERE id = ?', [$x['id']]); bov_log('eliminar', $bx, (int)$x['id'], $x['titulo']); flash('Registro «' . $x['titulo'] . '» eliminado.'); }
        redirect('index.php?' . http_build_query($_GET));
    } elseif ($acc === 'eliminar_boveda' && $bv) {
        require_perm('boveda', 'administrar');
        if (!bov_dek((int)$bv['id']) || bov_dek_con_frase($bv, (string)($_POST['actual'] ?? '')) === null || trim((string)post('confirmar', '')) !== $bv['nombre']) {
            bov_log('fallo', $bv, null, 'eliminar bóveda: datos de confirmación incorrectos'); flash('No se eliminó: la bóveda debe estar desbloqueada, la frase correcta y el nombre escrito igual.', 'danger');
            redirect(url('boveda', ['v' => $pv, 'vista' => 'config']));
        }
        $n = (int)val('SELECT COUNT(*) FROM boveda WHERE boveda_id = ?', [$bv['id']]);
        bov_log('eliminar_boveda', $bv, null, $n . ' registro(s) eliminados');
        bov_bloquear((int)$bv['id']);
        q('DELETE FROM bovedas WHERE id = ?', [$bv['id']]);
        flash('Bóveda «' . $bv['nombre'] . '» eliminada con sus ' . $n . ' registros.');
        redirect(url('boveda'));
    }
}

// ---------------------------------------------------------------- Vista: código de recuperación (se muestra una sola vez)
if ($recCode !== null): ?>
<div class="panel" style="max-width:720px">
  <div class="panel-h"><h3><i class="bi bi-key me-2"></i>Código de recuperación · <?= e($recBoveda['nombre']) ?></h3></div>
  <div class="alert alert-warning">
    <strong>Guárdelo ahora en un lugar seguro y fuera de este sistema</strong> (impreso en una caja fuerte, gestor de contraseñas personal…).
    No se vuelve a mostrar ni se guarda en ningún sitio. Si olvida la frase maestra de esta bóveda, este código es la <strong>única</strong> forma de recuperar sus claves; sin la frase y sin el código no hay manera de abrirlas.
  </div>
  <div class="text-center my-4"><code style="font-size:1.6rem;letter-spacing:.1em;user-select:all"><?= e($recCode) ?></code></div>
  <a class="btn btn-primary" href="<?= url('boveda', ['v' => $recBoveda['id']]) ?>"><i class="bi bi-check2 me-1"></i>Ya lo guardé, continuar</a>
</div>
<?php return; endif;

/** Pestañas (a nivel de lista o de una bóveda). */
$tab = fn(string $v, string $txt, string $href) => '<li class="nav-item"><a class="nav-link ' . ($vista === $v ? 'active' : '') . '" href="' . $href . '">' . $txt . '</a></li>';

// ================================================================ A) Una bóveda
if ($boveda):
    $tabs = '<ul class="nav nav-tabs mb-3">' . $tab('', 'Claves', url('boveda', ['v' => $vid]))
        . ($esAdmin ? $tab('acceso', 'Quién tiene acceso', url('boveda', ['v' => $vid, 'vista' => 'acceso'])) . $tab('config', 'Seguridad', url('boveda', ['v' => $vid, 'vista' => 'config'])) : '') . '</ul>';
    echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-3"><a class="btn btn-sm btn-outline-secondary" href="' . url('boveda') . '"><i class="bi bi-arrow-left"></i> Bóvedas</a>'
        . '<h2 class="h5 m-0 ms-1"><i class="bi bi-shield-lock me-1"></i>' . e($boveda['nombre']) . '</h2>' . ($dek ? ' <span class="badge-s badge-success">Desbloqueada</span>' : ' <span class="badge-s badge-secondary">Bloqueada</span>') . '</div>';
    echo $tabs;

    // ------------------------------------------------------------ A1) Quién tiene acceso
    if ($vista === 'acceso'):
        $usuarios = all('SELECT u.id, u.nombre, u.email, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.activo = 1 ORDER BY u.nombre');
        $con = array_map('intval', array_column(all('SELECT usuario_id FROM boveda_acceso WHERE boveda_id = ?', [$vid]), 'usuario_id')); ?>
<div class="panel" style="max-width:820px">
  <div class="panel-h"><h3>Quién tiene acceso a esta bóveda</h3></div>
  <p class="small">Solo ven esta bóveda (y su lista de claves) los administradores de la bóveda y los usuarios marcados aquí; además necesitan permisos del módulo y conocer la frase maestra para desbloquearla. Para otras personas la bóveda ni existe.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="acceso"><input type="hidden" name="v" value="<?= $vid ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:4px 16px;max-height:340px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:10px">
      <?php foreach ($usuarios as $us): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="usuarios[]" value="<?= (int)$us['id'] ?>" id="ua<?= (int)$us['id'] ?>" <?= in_array((int)$us['id'], $con, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="ua<?= (int)$us['id'] ?>"><?= e($us['nombre']) ?><small class="text-muted d-block"><?= e($us['email']) ?><?= $us['empresa'] ? ' · ' . e($us['empresa']) : '' ?></small></label></div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Guardar acceso</button></div>
  </form>
</div>
<?php return; endif;

    // ------------------------------------------------------------ A2) Seguridad (frase, código, eliminar)
    if ($vista === 'config'):
        $nReg = (int)val('SELECT COUNT(*) FROM boveda WHERE boveda_id = ?', [$vid]); ?>
<?php if (!$dek): ?><div class="alert alert-warning py-2">Desbloquee la bóveda primero (pestaña Claves) para cambiar la frase, generar un código nuevo o eliminarla.</div><?php endif; ?>
<div class="panel" style="max-width:720px">
  <div class="panel-h"><h3><i class="bi bi-shield-lock me-2"></i>Cambiar la frase maestra</h3></div>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="cambiar_frase"><input type="hidden" name="v" value="<?= $vid ?>">
    <div class="form-grid">
      <div class="full"><label class="form-label req">Frase actual</label><input class="form-control" type="password" name="actual" required autocomplete="off"></div>
      <div><label class="form-label req">Frase nueva</label><input class="form-control" type="password" name="frase" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
      <div><label class="form-label req">Repita la frase nueva</label><input class="form-control" type="password" name="frase2" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" <?= $dek ? '' : 'disabled' ?>>Cambiar frase</button></div>
  </form>
</div>
<div class="panel" style="max-width:720px">
  <div class="panel-h"><h3><i class="bi bi-key me-2"></i>Código de recuperación</h3></div>
  <p class="small">Si lo perdió o lo vio alguien que no debía, genere uno nuevo: el anterior deja de servir. Se muestra una sola vez.</p>
  <form method="post" autocomplete="off" data-confirm="¿Generar un código de recuperación nuevo? El anterior dejará de funcionar."><?= csrf_field() ?><input type="hidden" name="accion" value="nuevo_codigo"><input type="hidden" name="v" value="<?= $vid ?>">
    <div class="form-grid"><div class="full"><label class="form-label req">Frase maestra</label><input class="form-control" type="password" name="actual" required autocomplete="off"></div></div>
    <div class="form-actions"><button class="btn btn-outline-primary" <?= $dek ? '' : 'disabled' ?>>Generar código nuevo</button></div>
  </form>
</div>
<div class="panel" style="max-width:720px;border-color:#fecaca">
  <div class="panel-h"><h3 class="text-danger"><i class="bi bi-trash me-2"></i>Eliminar la bóveda</h3></div>
  <p class="small">Borra la bóveda y sus <strong><?= $nReg ?></strong> registros. No se puede deshacer.</p>
  <form method="post" autocomplete="off" data-confirm="¿Eliminar la bóveda «<?= e($boveda['nombre']) ?>» con todas sus claves? No se puede deshacer."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_boveda"><input type="hidden" name="v" value="<?= $vid ?>">
    <div class="form-grid">
      <div><label class="form-label req">Frase maestra</label><input class="form-control" type="password" name="actual" required autocomplete="off"></div>
      <div><label class="form-label req">Escriba el nombre exacto: <em><?= e($boveda['nombre']) ?></em></label><input class="form-control" name="confirmar" required autocomplete="off"></div>
    </div>
    <div class="form-actions"><button class="btn btn-outline-danger" <?= $dek ? '' : 'disabled' ?>>Eliminar la bóveda</button></div>
  </form>
</div>
<?php return; endif;

    // ------------------------------------------------------------ A3) Claves
    $where = ['b.boveda_id = ?']; $params = [$vid];
    $tipo = get('tipo', '');
    if (in_array($tipo, BOV_TIPOS, true)) { $where[] = 'b.tipo = ?'; $params[] = $tipo; }
    if ($bq = get('b')) { $where[] = '(b.titulo LIKE ? OR b.host LIKE ?)'; array_push($params, "%$bq%", "%$bq%"); }
    $w = implode(' AND ', $where);
    $per = 30; $page = max(1, (int)get('pg', 1));
    $total = (int)val("SELECT COUNT(*) FROM boveda b WHERE $w", $params);
    $rows = all("SELECT b.id, b.titulo, b.tipo, b.host, b.clave_cambiada, b.actualizado FROM boveda b WHERE $w ORDER BY b.titulo LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $puedeVer = can('boveda', 'aprobar');
    $qs = array_filter(['p' => 'boveda', 'v' => $vid, 'tipo' => $tipo, 'b' => get('b')]);
?>
<?php if ($dek): ?>
<div class="alert alert-success d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-unlock"></i><span>Bóveda <strong>desbloqueada</strong>. Se bloquea sola en <strong id="bov-resta"><?= bov_segundos_restantes($vid) ?></strong> s sin usarla.</span>
  <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="accion" value="bloquear"><input type="hidden" name="v" value="<?= $vid ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-lock me-1"></i>Bloquear ahora</button></form>
</div>
<?php else: ?>
<div class="panel" style="max-width:720px">
  <div class="panel-h"><h3><i class="bi bi-lock me-2"></i>Bóveda bloqueada</h3></div>
  <form method="post" autocomplete="off" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="accion" value="desbloquear"><input type="hidden" name="v" value="<?= $vid ?>">
    <div class="grow" style="min-width:260px"><label class="form-label">Frase maestra de <?= e($boveda['nombre']) ?></label><input class="form-control" type="password" name="frase" required autofocus autocomplete="off"></div>
    <button class="btn btn-primary"><i class="bi bi-unlock me-1"></i>Desbloquear</button>
  </form>
  <div class="form-text">Para ver, copiar, crear o editar claves hay que desbloquearla. La lista de abajo no muestra usuarios ni contraseñas.</div>
  <?php if ($esAdmin): ?>
  <details class="mt-3"><summary class="small">¿Olvidó la frase? Recupere el acceso con el código de recuperación</summary>
    <form method="post" autocomplete="off" class="mt-2"><?= csrf_field() ?><input type="hidden" name="accion" value="recuperar"><input type="hidden" name="v" value="<?= $vid ?>">
      <div class="form-grid">
        <div class="full"><label class="form-label req">Código de recuperación</label><input class="form-control" name="codigo" required autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"></div>
        <div><label class="form-label req">Frase maestra nueva</label><input class="form-control" type="password" name="frase" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
        <div><label class="form-label req">Repita la frase</label><input class="form-control" type="password" name="frase2" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
      </div>
      <div class="form-actions"><button class="btn btn-outline-primary">Restablecer la frase</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="p" value="boveda"><input type="hidden" name="v" value="<?= $vid ?>">
    <input class="form-control grow" name="b" value="<?= e(get('b')) ?>" placeholder="Título o dirección">
    <select class="form-select" name="tipo" data-autosubmit><option value="">Todo tipo</option><?= options(BOV_TIPOS, $tipo, false) ?></select>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if (get('b') !== null || $tipo): ?><a class="btn btn-outline-secondary" href="<?= url('boveda', ['v' => $vid]) ?>">Limpiar</a><?php endif; ?>
    <div class="actions"><?php if (can('boveda', 'crear')): ?><a class="btn btn-primary <?= $dek ? '' : 'disabled' ?>" href="<?= url('boveda_form', ['v' => $vid]) ?>"><i class="bi bi-plus-lg me-1"></i>Nueva clave</a><?php endif; ?></div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Título</th><th>Tipo</th><th>Dirección</th><th>Clave cambiada</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-id="<?= (int)$r['id'] ?>">
        <td class="fw-semibold"><?= e($r['titulo']) ?><div class="bov-valor small fw-normal"></div></td>
        <td class="small"><?= e($r['tipo']) ?></td>
        <td class="small"><?php if ($r['host'] && preg_match('#^https?://#i', $r['host'])): ?><a href="<?= e($r['host']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['host']) ?></a><?php else: echo e($r['host'] ?: '—'); endif; ?></td>
        <td class="small muted nowrap"><?= $r['clave_cambiada'] ? fdate($r['clave_cambiada']) : '—' ?></td>
        <td class="r nowrap">
          <?php if ($puedeVer): ?>
            <button class="btn btn-sm btn-outline-secondary bov-btn" data-campo="usuario" data-uso="copiar" title="Copiar usuario" <?= $dek ? '' : 'disabled' ?>><i class="bi bi-person"></i></button>
            <button class="btn btn-sm btn-outline-secondary bov-btn" data-campo="clave" data-uso="copiar" title="Copiar contraseña" <?= $dek ? '' : 'disabled' ?>><i class="bi bi-clipboard"></i></button>
            <button class="btn btn-sm btn-outline-secondary bov-btn" data-campo="clave" data-uso="ver" title="Ver contraseña por 20 segundos" <?= $dek ? '' : 'disabled' ?>><i class="bi bi-eye"></i></button>
          <?php endif; ?>
          <?php if (can('boveda', 'editar')): ?><a class="btn btn-sm btn-outline-primary <?= $dek ? '' : 'disabled' ?>" href="<?= url('boveda_form', ['v' => $vid, 'id' => $r['id']]) ?>" title="Editar"><i class="bi bi-pencil"></i></a><?php endif; ?>
          <?php if (can('boveda', 'eliminar')): ?><form method="post" class="d-inline" data-confirm="¿Eliminar «<?= e($r['titulo']) ?>»? No se puede deshacer."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">No hay claves guardadas<?= $bq || $tipo ? ' con esos filtros' : ' en esta bóveda' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, $qs) ?>
</div>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>, restante = <?= $dek ? bov_segundos_restantes($vid) : 0 ?>;
  var el = document.getElementById('bov-resta');
  if (el) setInterval(function () { restante--; el.textContent = Math.max(restante, 0); if (restante <= 0) location.reload(); }, 1000);
  function aviso(fila, txt, cls, ms) {
    var d = fila.querySelector('.bov-valor'); d.textContent = txt; d.className = 'bov-valor small fw-normal ' + (cls || '');
    clearTimeout(d._t); d._t = setTimeout(function () { d.textContent = ''; }, ms || 4000);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.bov-btn'); if (!b || b.disabled) return;
    var fila = b.closest('tr'), fd = new FormData();
    fd.append('_csrf', csrf); fd.append('accion', 'revelar'); fd.append('id', fila.getAttribute('data-id')); fd.append('campo', b.getAttribute('data-campo')); fd.append('uso', b.getAttribute('data-uso'));
    fetch('index.php?p=boveda', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { aviso(fila, j.error || 'No se pudo obtener el dato.', 'text-danger', 6000); if (j.bloqueada) setTimeout(function () { location.reload(); }, 1500); return; }
        if (b.getAttribute('data-uso') === 'copiar') {
          navigator.clipboard.writeText(j.valor).then(function () {
            aviso(fila, (b.getAttribute('data-campo') === 'usuario' ? 'Usuario' : 'Contraseña') + ' copiado. El portapapeles se limpia en 30 s.', 'text-success', 4000);
            setTimeout(function () { navigator.clipboard.writeText(' ').catch(function () {}); }, 30000);
          }).catch(function () { aviso(fila, 'El navegador no permitió copiar.', 'text-danger'); });
        } else {
          var d = fila.querySelector('.bov-valor'); d.innerHTML = ''; var c = document.createElement('code'); c.textContent = j.valor; c.style.userSelect = 'all'; d.appendChild(c);
          d.className = 'bov-valor small fw-normal'; clearTimeout(d._t); d._t = setTimeout(function () { d.textContent = ''; }, 20000);
        }
        j.valor = null;
      }).catch(function () { aviso(fila, 'Error de conexión.', 'text-danger'); });
  });
})();
</script>
<?php return; endif;

// ================================================================ B) Lista de bóvedas / registro de accesos
$tabs = '<ul class="nav nav-tabs mb-3">' . $tab('', 'Bóvedas', url('boveda')) . ($esAdmin ? $tab('registro', 'Registro de accesos', url('boveda', ['vista' => 'registro'])) : '') . '</ul>';
echo $tabs;

if ($vista === 'registro'):
    $per = 50; $page = max(1, (int)get('pg', 1));
    $total = (int)val('SELECT COUNT(*) FROM boveda_log');
    $rows = all('SELECT * FROM boveda_log ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)); ?>
<div class="panel">
  <div class="panel-h"><h3>Registro de accesos</h3><div class="actions small muted">Quién abrió, vio o copió cada dato. No se puede editar ni borrar desde la aplicación.</div></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Fecha</th><th>Usuario</th><th>Bóveda</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="nowrap muted"><?= fdate($r['fecha'], true) ?></td><td><?= e($r['usuario_nombre'] ?: '—') ?></td><td class="small"><?= e($r['boveda_nombre'] ?: '—') ?></td>
        <td><?= $r['accion'] === 'fallo' ? '<span class="text-danger">' . e(bov_accion_txt($r['accion'])) . '</span>' : e(bov_accion_txt($r['accion'])) ?></td>
        <td class="small"><?= e($r['detalle'] ?: '') ?></td><td class="small muted"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Sin movimientos.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= pagination($total, $per, $page, ['p' => 'boveda', 'vista' => 'registro']) ?>
</div>
<?php return; endif;

$lista = bov_lista();
$sinBoveda = $esAdmin ? all('SELECT e.id, e.nombre FROM empresas e WHERE e.activo = 1 AND e.tipo = ? AND e.id NOT IN (SELECT empresa_id FROM bovedas WHERE empresa_id IS NOT NULL) ORDER BY e.nombre', ['cliente']) : [];
?>
<div class="panel">
  <div class="panel-h"><h3><i class="bi bi-shield-lock me-2"></i>Bóvedas de claves</h3><div class="actions small muted">Una por cliente, cada una con su propia frase maestra.</div></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Cliente</th><th class="c">Claves</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $b): $ab = bov_abierta((int)$b['id']); ?>
      <tr>
        <td><a class="row-link" href="<?= url('boveda', ['v' => $b['id']]) ?>"><?= e($b['nombre']) ?></a></td>
        <td class="c"><?= (int)$b['n'] ?></td>
        <td><?= $ab ? '<span class="badge-s badge-success">Desbloqueada</span>' : '<span class="badge-s badge-secondary">Bloqueada</span>' ?></td>
        <td class="r"><a class="btn btn-sm btn-outline-primary" href="<?= url('boveda', ['v' => $b['id']]) ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Abrir</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="4" class="empty"><?= $esAdmin ? 'Aún no hay bóvedas. Cree la primera abajo.' : 'No tiene acceso a ninguna bóveda. Pida a un administrador que se lo dé.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php if ($esAdmin): ?>
<div class="panel" style="max-width:820px">
  <div class="panel-h"><h3>Nueva bóveda</h3></div>
  <?php if (!$sinBoveda): ?><div class="small muted">Todos los clientes activos ya tienen su bóveda.</div><?php else: ?>
  <p class="small">Cada bóveda guarda los usuarios y contraseñas de <strong>un</strong> cliente, cifrados con su propia <strong>frase maestra</strong>:</p>
  <ul class="small">
    <li>Es la llave de esa bóveda: mínimo <?= BOV_FRASE_MIN ?> caracteres (sirve una frase de varias palabras). No se guarda en ningún sitio; quien tenga la base de datos solo ve texto cifrado.</li>
    <li>Se le dará un <strong>código de recuperación</strong> por si la olvida. Sin la frase ni el código, las claves de ese cliente no se pueden recuperar.</li>
    <li>Después elija quién tiene acceso a la bóveda (pestaña «Quién tiene acceso»).</li>
  </ul>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="crear_boveda">
    <div class="form-grid">
      <div class="full"><label class="form-label req">Cliente</label><select class="form-select" name="empresa_id" required><option value="">Seleccione…</option><?= options(array_column($sinBoveda, 'nombre', 'id'), '') ?></select></div>
      <div><label class="form-label req">Frase maestra</label><input class="form-control" type="password" name="frase" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
      <div><label class="form-label req">Repita la frase</label><input class="form-control" type="password" name="frase2" minlength="<?= BOV_FRASE_MIN ?>" required autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Crear la bóveda</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
