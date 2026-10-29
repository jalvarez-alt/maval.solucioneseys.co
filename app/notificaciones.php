<?php
/**
 * Notificaciones por correo: cambios de estado de tickets y recordatorios del planeador de mantenimiento.
 * Todo se envía después de responder al navegador (despues()) y usa la cuenta configurada en «Correo y notificaciones».
 */

// =====================================================================
// Mesa de ayuda
// =====================================================================

/** Usuarios que atienden tickets: administradores y roles con el permiso «Administrar» en la mesa de ayuda. */
function usuarios_soporte(): array
{
    return all("SELECT u.id, u.nombre, u.email FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_id
        WHERE u.activo = 1 AND (r.codigo = 'admin' OR u.rol_id IN (SELECT rol_id FROM rol_permisos WHERE modulo = 'tickets' AND accion = 'administrar'))");
}

/**
 * Avisa por correo de un evento de un ticket. Eventos: creado, estado (ctx: anterior, nuevo, mensaje), respuesta (ctx: mensaje), asignado.
 * El mensaje se arma con los datos vigentes del ticket en el momento del envío.
 */
function notificar_ticket(int $ticketId, string $evento, array $ctx = []): void
{
    if (!correo_activo() || !ajuste('notif_tickets', 1)) return;
    $actor = (int)(user()['id'] ?? 0);
    $actorNombre = (string)(user()['nombre'] ?? '');
    despues(function () use ($ticketId, $evento, $ctx, $actor, $actorNombre) {
        $t = one('SELECT t.*, s.nombre AS sol_nombre, s.email AS sol_email, a.nombre AS asig_nombre, a.email AS asig_email, e.nombre AS empresa,
                eq.codigo AS eq_codigo, eq.tipo AS eq_tipo, eq.marca AS eq_marca, eq.modelo AS eq_modelo
            FROM tickets t LEFT JOIN usuarios s ON s.id = t.solicitante_id LEFT JOIN usuarios a ON a.id = t.asignado_id
            LEFT JOIN empresas e ON e.id = t.empresa_id LEFT JOIN equipos eq ON eq.id = t.equipo_id WHERE t.id = ?', [$ticketId]);
        if (!$t) return;
        $porEquipo = (bool)ajuste('notif_tickets_equipo', 1);
        // El solicitante recibe el aviso salvo que él mismo haya hecho el cambio (la creación sí se le confirma)
        $solicitante = $t['sol_email'] && ((int)$t['solicitante_id'] !== $actor || $evento === 'creado') ? $t['sol_email'] : null;
        // Equipo de soporte: el técnico asignado, o todos si aún no hay asignado
        $soporte = [];
        if ($porEquipo) {
            if ($t['asig_email']) $soporte[] = ['id' => (int)$t['asignado_id'], 'email' => $t['asig_email']];
            if (!$t['asignado_id'] || $evento === 'creado') foreach (usuarios_soporte() as $s) $soporte[] = ['id' => (int)$s['id'], 'email' => $s['email']];
        }
        $soporte = array_unique(array_column(array_filter($soporte, fn($s) => $s['id'] !== $actor && (int)$s['id'] !== (int)$t['solicitante_id']), 'email'));

        $cod = $t['codigo'];
        $ficha = correo_ficha([
            'Ticket' => '<strong>' . e($cod) . '</strong>', 'Asunto' => e($t['asunto']), 'Estado' => e($t['estado']), 'Prioridad' => e($t['prioridad']), 'Categoría' => e($t['categoria']),
            'Solicitante' => e($t['sol_nombre'] ?? ''), 'Empresa' => e($t['empresa'] ?? ''), 'Asignado a' => e($t['asig_nombre'] ?? 'Sin asignar'),
            'Equipo' => $t['eq_codigo'] ? e($t['eq_codigo'] . ' · ' . $t['eq_tipo'] . ' ' . trim(($t['eq_marca'] ?? '') . ' ' . ($t['eq_modelo'] ?? ''))) : null,
        ]);
        $msg = trim((string)($ctx['mensaje'] ?? ''));
        $citado = $msg !== '' ? '<div style="margin:12px 0;padding:10px 14px;border-left:3px solid #0f766e;background:#f8fafc;white-space:pre-wrap">'
            . '<div style="font-size:12px;color:#64748b;margin-bottom:4px">' . e($actorNombre ?: 'Soporte') . ' escribió:</div>' . e($msg) . '</div>' : '';
        $link = app_link('ticket', ['id' => $ticketId]);
        $avisados = [];   // correos que ya recibieron aviso de este evento
        $envia = function ($para, string $asunto, string $titulo, string $intro) use (&$avisados, $citado, $ficha, $link) {
            foreach ((array)$para as $c) $avisados[] = mb_strtolower((string)$c);
            return enviar_correo($para, $asunto, correo_plantilla($titulo, '<p style="margin:0 0 10px">' . $intro . '</p>' . $citado . $ficha, $link, 'Ver el ticket'), ['motivo' => 'ticket']);
        };

        if ($evento === 'creado') {
            if ($solicitante) $envia($solicitante, "[$cod] Recibimos su solicitud: " . $t['asunto'], 'Recibimos su solicitud', 'Su ticket fue registrado con el código <strong>' . e($cod) . '</strong>. Le avisaremos por este medio cada vez que cambie de estado.');
            if ($soporte) $envia($soporte, "[$cod] Nuevo ticket: " . $t['asunto'], 'Nuevo ticket en la mesa de ayuda', 'Se registró un nuevo ticket' . ($t['sol_nombre'] ? ' de <strong>' . e($t['sol_nombre']) . '</strong>' : '') . '.');
        } elseif ($evento === 'estado') {
            $nuevo = $ctx['nuevo'] ?? $t['estado'];
            $texto = [
                'Abierto' => 'Su ticket fue reabierto y está en la cola de atención.',
                'En proceso' => 'Un técnico ya está atendiendo su solicitud.',
                'En espera' => 'Su ticket quedó <strong>en espera</strong> (de información suya o de un tercero). Si falta algo de su parte, responda desde el ticket.',
                'Resuelto' => 'Marcamos su ticket como <strong>resuelto</strong>. Si quedó solucionado, ciérrelo desde el ticket; si el problema continúa, responda y lo reabriremos.',
                'Cerrado' => 'Su ticket fue <strong>cerrado</strong>. Gracias por contactarnos; si el problema reaparece, cree un nuevo ticket.',
            ][$nuevo] ?? 'El estado de su ticket cambió.';
            $de = !empty($ctx['anterior']) ? ' (antes «' . e($ctx['anterior']) . '»)' : '';
            if ($solicitante) $envia($solicitante, "[$cod] Su ticket está: $nuevo", 'Su ticket cambió a «' . $nuevo . '»', $texto . $de);
            if ($soporte) $envia($soporte, "[$cod] Estado: $nuevo — " . $t['asunto'], 'El ticket cambió a «' . $nuevo . '»', 'El ticket pasó a <strong>' . e($nuevo) . '</strong>' . $de . ($actorNombre ? ' por ' . e($actorNombre) : '') . '.');
        } elseif ($evento === 'respuesta') {
            if ($actor && (int)$t['solicitante_id'] === $actor) {
                if ($soporte) $envia($soporte, "[$cod] Respuesta del solicitante: " . $t['asunto'], 'El solicitante respondió', 'Hay una nueva respuesta en el ticket.');
            } elseif ($solicitante) {
                $envia($solicitante, "[$cod] Nueva respuesta a su ticket", 'Nueva respuesta a su ticket', 'El equipo de soporte respondió su ticket.');
            }
        } elseif ($evento === 'asignado') {
            if ($t['asig_email'] && (int)$t['asignado_id'] !== $actor) $envia($t['asig_email'], "[$cod] Se le asignó un ticket: " . $t['asunto'], 'Se le asignó un ticket', 'Se le asignó este ticket para su atención.');
        }
        // Usuarios que la empresa tiene configurados: se enteran de la creación, las respuestas y los cambios de estado (sin repetir a quien ya recibió aviso)
        if ($t['empresa_id'] && in_array($evento, ['creado', 'estado', 'respuesta'], true)) {
            $dest = [];
            foreach (all('SELECT u.id, u.email FROM empresa_notificar n JOIN usuarios u ON u.id = n.usuario_id WHERE n.empresa_id = ? AND u.activo = 1', [(int)$t['empresa_id']]) as $n) {
                if ((int)$n['id'] === $actor || !filter_var($n['email'], FILTER_VALIDATE_EMAIL) || in_array(mb_strtolower($n['email']), $avisados, true)) continue;
                $dest[] = $n['email'];
            }
            if ($dest) {
                $de = ' del ticket <strong>' . e($cod) . '</strong>' . ($t['sol_nombre'] ? ' de <strong>' . e($t['sol_nombre']) . '</strong>' : '') . ($t['empresa'] ? ' (' . e($t['empresa']) . ')' : '');
                if ($evento === 'creado') $envia($dest, "[$cod] Nuevo ticket de " . ($t['empresa'] ?: 'su empresa') . ': ' . $t['asunto'], 'Nuevo ticket de su empresa', 'Se registró un nuevo ticket' . ($t['sol_nombre'] ? ' de <strong>' . e($t['sol_nombre']) . '</strong>' : '') . ($t['empresa'] ? ' de <strong>' . e($t['empresa']) . '</strong>' : '') . '.');
                elseif ($evento === 'estado') $envia($dest, "[$cod] Estado: " . ($ctx['nuevo'] ?? $t['estado']) . ' — ' . $t['asunto'], 'El ticket cambió a «' . ($ctx['nuevo'] ?? $t['estado']) . '»', 'El estado' . $de . ' pasó a <strong>' . e($ctx['nuevo'] ?? $t['estado']) . '</strong>' . (!empty($ctx['anterior']) ? ' (antes «' . e($ctx['anterior']) . '»)' : '') . ($actorNombre ? ' por ' . e($actorNombre) : '') . '.');
                else $envia($dest, "[$cod] Nueva respuesta: " . $t['asunto'], 'Nueva respuesta en un ticket de su empresa', 'Hay una nueva respuesta' . $de . ($actorNombre ? ', escrita por ' . e($actorNombre) : '') . '.');
            }
        }
    });
}

// =====================================================================
// Planeador de mantenimiento
// =====================================================================

const MANT_SELECT = 'SELECT m.*, eq.codigo, eq.tipo, eq.marca, eq.modelo, eq.ubicacion, eq.correos AS eq_correos, eq.estado AS eq_estado, eq.responsable,
        u.email AS usuario_email, t.email AS tecnico_email, t.nombre AS tecnico, emp.nombre AS empresa
    FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id
    LEFT JOIN usuarios u ON u.id = eq.usuario_id AND u.activo = 1 LEFT JOIN usuarios t ON t.id = m.tecnico_id AND t.activo = 1 LEFT JOIN empresas emp ON emp.id = eq.empresa_id';

/** Correos a avisar de un mantenimiento: correos del equipo, usuario asignado y técnico responsable. */
function mant_destinatarios(array $m): array
{
    $l = [];
    foreach (preg_split('/[,;\s]+/', (string)$m['eq_correos']) as $c) if (filter_var($c, FILTER_VALIDATE_EMAIL)) $l[] = mb_strtolower($c);
    foreach ([$m['usuario_email'], $m['tecnico_email']] as $c) if ($c && filter_var($c, FILTER_VALIDATE_EMAIL)) $l[] = mb_strtolower($c);
    return array_values(array_unique($l));
}
function mant_copia(): array
{
    $l = [];
    foreach (preg_split('/[,;\s]+/', (string)ajuste('mant_copia', '')) as $c) if (filter_var($c, FILTER_VALIDATE_EMAIL)) $l[] = mb_strtolower($c);
    return array_values(array_unique($l));
}
function dias_hasta(string $fecha): int { return (int)round((strtotime($fecha) - strtotime(today())) / 86400); }
function mant_texto_dias(int $d): string
{
    return $d === 0 ? 'hoy' : ($d > 0 ? 'en ' . $d . ($d === 1 ? ' día' : ' días') : 'vencido hace ' . abs($d) . (abs($d) === 1 ? ' día' : ' días'));
}
function mant_tabla(array $rows): string
{
    $h = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:6px 0 14px;font-size:13px"><tr style="background:#f1f5f9;text-align:left">'
        . '<th style="padding:7px 8px">Equipo</th><th style="padding:7px 8px">Ubicación</th><th style="padding:7px 8px">Fecha</th><th style="padding:7px 8px">Estado</th></tr>';
    foreach ($rows as $m) {
        $d = dias_hasta($m['fecha_programada']);
        $color = $d < 0 ? '#b91c1c' : ($d <= 3 ? '#b45309' : '#0f766e');
        $h .= '<tr><td style="padding:7px 8px;border-bottom:1px solid #eef2f6"><strong>' . e($m['codigo']) . '</strong><br><span style="color:#64748b">' . e($m['tipo'] . ' ' . trim(($m['marca'] ?? '') . ' ' . ($m['modelo'] ?? ''))) . '</span></td>'
            . '<td style="padding:7px 8px;border-bottom:1px solid #eef2f6">' . e($m['ubicacion'] ?: ($m['empresa'] ?? '—')) . '</td>'
            . '<td style="padding:7px 8px;border-bottom:1px solid #eef2f6;white-space:nowrap">' . fdate($m['fecha_programada']) . '</td>'
            . '<td style="padding:7px 8px;border-bottom:1px solid #eef2f6;color:' . $color . ';white-space:nowrap">' . e(mant_texto_dias($d)) . '</td></tr>';
    }
    return $h . '</table>';
}

/**
 * Arma y envía un correo por destinatario. $grupos = ['programados' => filas, 'proximos' => filas, 'pendientes' => filas].
 * Devuelve [ids de mantenimientos notificados con éxito, correos enviados].
 */
function mant_enviar_grupos(array $grupos): array
{
    $porDest = [];   // email => [grupo => [id => fila]]
    $copia = mant_copia();
    foreach ($grupos as $g => $rows) {
        foreach ($rows as $m) {
            foreach (array_merge(mant_destinatarios($m), $copia) as $d) $porDest[$d][$g][(int)$m['id']] = $m;
        }
    }
    $ok = []; $enviados = 0;
    foreach ($porDest as $dest => $g) {
        $secciones = '';
        $titulos = ['programados' => 'Mantenimientos preventivos programados', 'proximos' => 'Próximos mantenimientos', 'pendientes' => 'Mantenimientos pendientes, ya vencidos'];
        foreach ($titulos as $k => $tit) {
            if (!empty($g[$k])) {
                $filas = array_values($g[$k]);
                usort($filas, fn($a, $b) => strcmp($a['fecha_programada'], $b['fecha_programada']));
                $secciones .= '<h3 style="margin:14px 0 2px;font-size:15px">' . e($tit) . ' (' . count($filas) . ')</h3>' . mant_tabla($filas);
            }
        }
        $asunto = isset($g['programados']) && count($g) === 1 ? 'Mantenimiento preventivo programado'
            : (isset($g['pendientes']) && count($g) === 1 ? 'Mantenimientos preventivos pendientes' : (isset($g['proximos']) && count($g) === 1 ? 'Recordatorio: próximos mantenimientos preventivos' : 'Recordatorio de mantenimientos preventivos'));
        $intro = '<p style="margin:0 0 6px">Este es el resumen de mantenimientos preventivos de los equipos a su cargo. Una vez realizados, el técnico los registra en la aplicación.</p>';
        $r = enviar_correo($dest, $asunto, correo_plantilla($asunto, $intro . $secciones, app_link('mantenimiento'), 'Ver el planeador'), ['motivo' => 'mantenimiento']);
        if ($r['ok']) {
            $enviados++;
            foreach ($g as $rows) foreach (array_keys($rows) as $id) $ok[$id] = true;
        }
    }
    return [array_keys($ok), $enviados];
}

/** Informa las fechas recién programadas (se llama al crear la programación). */
function notificar_programacion(array $ids): void
{
    if (!$ids || !correo_activo() || !ajuste('notif_mantenimiento', 1)) return;
    despues(function () use ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $rows = all(MANT_SELECT . " WHERE m.id IN ($in) AND m.estado = 'Programado' AND eq.estado <> 'Dado de baja'");
        if (!$rows) return;
        [$okIds] = mant_enviar_grupos(['programados' => $rows]);
        foreach ($rows as $m) {
            if (!in_array((int)$m['id'], $okIds, true)) continue;
            $d = ['programacion_enviada' => now()];
            if (dias_hasta($m['fecha_programada']) <= (int)$m['aviso_dias']) $d['aviso_enviado'] = now(); // ya quedó avisado
            update('mantenimientos', $d, (int)$m['id']);
        }
    });
}

/**
 * Envía los avisos de próximos mantenimientos (dentro de los días de aviso de cada uno) y recordatorios de los pendientes vencidos.
 * $todo = true ignora las marcas de «ya enviado» (envío manual desde el planeador).
 * Devuelve ['proximos' => n, 'pendientes' => n, 'correos' => n].
 */
function enviar_recordatorios_mantenimiento(bool $todo = false): array
{
    $res = ['proximos' => 0, 'pendientes' => 0, 'correos' => 0];
    if (!correo_activo() || !ajuste('notif_mantenimiento', 1)) return $res;
    $hoy = today();
    $base = MANT_SELECT . " WHERE m.estado = 'Programado' AND eq.estado <> 'Dado de baja'";
    $proximos = all($base . ' AND m.fecha_programada >= ? AND DATEDIFF(m.fecha_programada, ?) <= m.aviso_dias' . ($todo ? '' : ' AND m.aviso_enviado IS NULL') . ' ORDER BY m.fecha_programada', [$hoy, $hoy]);
    $repetir = max(1, (int)ajuste('mant_repetir', 7));
    $pend = all($base . ' AND m.fecha_programada < ?' . ($todo ? '' : ' AND (m.pendiente_enviado IS NULL OR m.pendiente_enviado <= ?)') . ' ORDER BY m.fecha_programada',
        $todo ? [$hoy] : [$hoy, date('Y-m-d H:i:s', strtotime("-$repetir days") + 3600)]);
    if (!$proximos && !$pend) return $res;
    [$okIds, $res['correos']] = mant_enviar_grupos(['proximos' => $proximos, 'pendientes' => $pend]);
    foreach ($proximos as $m) if (in_array((int)$m['id'], $okIds, true)) { update('mantenimientos', ['aviso_enviado' => now()], (int)$m['id']); $res['proximos']++; }
    foreach ($pend as $m) if (in_array((int)$m['id'], $okIds, true)) { update('mantenimientos', ['pendiente_enviado' => now()], (int)$m['id']); $res['pendientes']++; }
    return $res;
}

/** Una vez al día (con la primera visita de cualquier usuario) envía los recordatorios, aunque no haya tarea programada en el servidor. */
function recordatorios_diarios(): void
{
    if (!correo_activo() || ajuste('ult_recordatorio') === today()) return;
    ajuste_set('ult_recordatorio', today());
    despues(function () { enviar_recordatorios_mantenimiento(false); });
}
