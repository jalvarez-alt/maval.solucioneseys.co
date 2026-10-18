<?php
/**
 * Acciones masivas sobre equipos (solo administrador): eliminar, asignar usuario de la mesa de ayuda y cambiar estado.
 * Los equipos se toman SIEMPRE de la consulta filtrada de la página ($w / $params), así nunca se toca algo fuera de ella.
 */

/** Procesa el envío de la barra de selección. Debe llamarse con la condición de filtro de la página. */
/** Módulo (computo o inventario) cuyo permiso «Administrar» habilita las acciones masivas. */
function masivo_mod(): string { return $GLOBALS['masivo_mod'] ?? 'inventario'; }

function masivo_procesar(string $w, array $params): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || post('accion') !== 'masivo' || !can(masivo_mod(), 'administrar')) return;
    $volver = 'index.php?' . http_build_query(array_diff_key($_GET, ['pg' => 1]));
    $base = "FROM equipos eq LEFT JOIN usuarios u ON u.id = eq.usuario_id WHERE $w";

    if (post('todos') === '1') {
        $ids = array_map('intval', array_column(all("SELECT eq.id $base", $params), 'id'));
    } else {
        $pedidos = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $ids = [];
        foreach (array_chunk($pedidos, 500) as $ch) {
            $ids = array_merge($ids, array_map('intval', array_column(all("SELECT eq.id $base AND eq.id IN (" . implode(',', $ch) . ')', $params), 'id')));
        }
    }
    if (!$ids) { flash('No seleccionó ningún equipo.', 'warning'); redirect($volver); }
    $n = count($ids);
    $uid = (int)user()['id'];
    $pdo = db();
    $op = post('op');

    if ($op === 'eliminar' && !can(masivo_mod(), 'eliminar')) { flash('No tiene permiso para eliminar equipos.', 'warning'); redirect($volver); }
    if ($op === 'eliminar') {
        if (mb_strtoupper(trim((string)post('confirmacion', ''))) !== 'ELIMINAR') { flash('Eliminación cancelada: debe escribir ELIMINAR para confirmar.', 'warning'); redirect($volver); }
        $pdo->beginTransaction();
        foreach (array_chunk($ids, 500) as $ch) {
            $in = implode(',', $ch);
            q("UPDATE tickets SET equipo_id = NULL WHERE equipo_id IN ($in)");          // los tickets se conservan
            q("DELETE FROM equipo_software WHERE equipo_id IN ($in)");
            q("DELETE FROM equipo_movimientos WHERE equipo_id IN ($in)");
            q("DELETE FROM equipos WHERE id IN ($in)");
        }
        $pdo->commit();
        error_log('MAVAL: ' . user()['email'] . " eliminó $n equipo(s) en bloque.");
        flash("Se eliminaron $n equipo(s). Sus tickets se conservan sin equipo vinculado.");
    } elseif ($op === 'asignar') {
        $usr = post('usuario_id') === 'ninguno' ? null : one('SELECT id, nombre, empresa_id FROM usuarios WHERE id = ? AND activo = 1', [(int)post('usuario_id')]);
        if (post('usuario_id') !== 'ninguno' && !$usr) { flash('Seleccione el usuario de la mesa de ayuda.', 'warning'); redirect($volver); }
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $eq = one('SELECT tipo, responsable, empresa_id FROM equipos WHERE id = ?', [$id]);
            $d = ['usuario_id' => $usr['id'] ?? null, 'actualizado' => now()];
            if ($usr && !$eq['empresa_id'] && $usr['empresa_id']) $d['empresa_id'] = $usr['empresa_id'];
            if ($usr && es_computo($eq['tipo']) && !$eq['responsable']) $d['responsable'] = $usr['nombre'];
            update('equipos', $d, $id);
            insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => $usr ? 'Asignado al usuario de mesa de ayuda ' . $usr['nombre'] . ' (acción masiva).' : 'Se quitó el usuario de mesa de ayuda (acción masiva).', 'fecha' => now()]);
        }
        $pdo->commit();
        flash($usr ? "$n equipo(s) asignado(s) a {$usr['nombre']}. Ya los puede ver en la mesa de ayuda." : "Se quitó el usuario de mesa de ayuda de $n equipo(s).");
    } elseif ($op === 'estado' && in_array(post('estado'), EQUIPO_ESTADOS, true)) {
        $nuevo = post('estado');
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $ant = val('SELECT estado FROM equipos WHERE id = ?', [$id]);
            if ($ant === $nuevo) continue;
            update('equipos', ['estado' => $nuevo, 'actualizado' => now()], $id);
            insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => "Estado: $ant → $nuevo (acción masiva).", 'fecha' => now()]);
        }
        $pdo->commit();
        flash("Estado «{$nuevo}» aplicado a $n equipo(s).");
    } else {
        flash('Seleccione una acción.', 'warning');
    }
    redirect($volver);
}

/** Barra de acciones (aparece al marcar equipos). $total = equipos que cumplen el filtro. */
function masivo_barra(int $total): string
{
    if (!can(masivo_mod(), 'administrar')) return '';
    $usuarios = all("SELECT u.id, u.nombre, u.email, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.activo = 1 ORDER BY u.nombre");
    $opts = '<option value="">Seleccione el usuario…</option><option value="ninguno">— Quitar usuario asignado —</option>';
    foreach ($usuarios as $us) $opts .= '<option value="' . (int)$us['id'] . '">' . e($us['nombre'] . ' · ' . $us['email'] . ($us['empresa'] ? ' · ' . $us['empresa'] : '')) . '</option>';
    return '<form method="post" id="masivo" class="bulk-bar" hidden data-total="' . $total . '">' . csrf_field()
        . '<input type="hidden" name="accion" value="masivo"><input type="hidden" name="todos" value="0"><input type="hidden" name="confirmacion" value="">'
        . '<div class="bulk-info"><strong data-bulk-count>0</strong> seleccionado(s)'
        . ($total > 0 ? ' <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-bulk-all hidden>Seleccionar los ' . $total . ' equipos del filtro</button>' : '')
        . ' <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-bulk-clear>Quitar selección</button></div>'
        . '<div class="bulk-actions">'
        . '<label class="visually-hidden" for="bulk-usr">Usuario de mesa de ayuda</label><select class="form-select form-select-sm" id="bulk-usr" name="usuario_id">' . $opts . '</select>'
        . '<button class="btn btn-sm btn-outline-primary" name="op" value="asignar"><i class="bi bi-person-check me-1"></i>Asignar</button>'
        . '<span class="sep"></span>'
        . '<label class="visually-hidden" for="bulk-est">Nuevo estado</label><select class="form-select form-select-sm" id="bulk-est" name="estado"><option value="">Cambiar estado…</option>' . options(EQUIPO_ESTADOS, '', false) . '</select>'
        . '<button class="btn btn-sm btn-outline-primary" name="op" value="estado">Aplicar</button>'
        . (can(masivo_mod(), 'eliminar') ? '<span class="sep"></span>'
        . '<button class="btn btn-sm btn-danger" name="op" value="eliminar" data-bulk-delete><i class="bi bi-trash me-1"></i>Eliminar</button>' : '')
        . '</div></form>';
}

/** Celda con la casilla de selección de una fila. */
function masivo_check(int $id): string
{
    return can(masivo_mod(), 'administrar') ? '<td class="c sel-col"><input class="form-check-input sel" type="checkbox" name="ids[]" value="' . $id . '" form="masivo" aria-label="Seleccionar"></td>' : '';
}
function masivo_check_all(): string
{
    return can(masivo_mod(), 'administrar') ? '<th class="c sel-col"><input class="form-check-input sel-all" type="checkbox" aria-label="Seleccionar todos los de esta página"></th>' : '';
}
