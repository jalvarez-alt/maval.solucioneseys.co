<?php
/**
 * Planeador de mantenimiento preventivo: lógica compartida por el listado y el formulario.
 */

/** Mantenimiento con los datos del equipo, solo si el usuario puede ver ese equipo. */
function mant_obtener(int $id): ?array
{
    [$es, $ep] = equipo_scope('eq');
    return one('SELECT m.*, eq.codigo, eq.tipo, eq.marca, eq.modelo, eq.ubicacion, eq.estado AS eq_estado, eq.empresa_id, emp.nombre AS empresa
        FROM mantenimientos m JOIN equipos eq ON eq.id = m.equipo_id LEFT JOIN empresas emp ON emp.id = eq.empresa_id
        WHERE m.id = ? AND ' . $es, array_merge([$id], $ep));
}

function mant_fecha_habil(string $fecha, bool $saltarFinSemana): string
{
    if (!$saltarFinSemana) return $fecha;
    while ((int)date('N', strtotime($fecha)) >= 6) $fecha = date('Y-m-d', strtotime($fecha . ' +1 day'));
    return $fecha;
}

/** Registra el mantenimiento como realizado, anota la hoja de vida y, si es periódico, programa el siguiente. Devuelve el id del siguiente (o null). */
function mant_realizar(array $m, string $fecha, ?string $obs): ?int
{
    $uid = (int)user()['id'];
    update('mantenimientos', ['estado' => 'Realizado', 'fecha_realizada' => $fecha, 'observaciones' => $obs !== null && $obs !== '' ? $obs : $m['observaciones'], 'actualizado' => now()], (int)$m['id']);
    insert('equipo_movimientos', ['equipo_id' => $m['equipo_id'], 'usuario_id' => $uid, 'fecha' => now(),
        'descripcion' => mb_substr('Mantenimiento preventivo realizado el ' . fdate($fecha) . ($obs ? ': ' . $obs : '.'), 0, 500)]);
    $meses = (int)$m['periodicidad_meses'];
    if ($meses <= 0) return null;
    $sig = date('Y-m-d', strtotime($fecha . " +$meses months"));
    if (val("SELECT id FROM mantenimientos WHERE equipo_id = ? AND estado = 'Programado' AND fecha_programada >= ?", [$m['equipo_id'], $sig])) return null; // ya hay uno igual o posterior
    $nid = insert('mantenimientos', ['equipo_id' => $m['equipo_id'], 'fecha_programada' => mant_fecha_habil($sig, true), 'estado' => 'Programado', 'periodicidad_meses' => $meses,
        'aviso_dias' => $m['aviso_dias'], 'tecnico_id' => $m['tecnico_id'], 'actividades' => $m['actividades'], 'creado_por' => $uid, 'creado' => now()]);
    insert('equipo_movimientos', ['equipo_id' => $m['equipo_id'], 'usuario_id' => $uid, 'fecha' => now(), 'descripcion' => 'Próximo mantenimiento preventivo programado para el ' . fdate(mant_fecha_habil($sig, true)) . '.']);
    return $nid;
}

/** Texto y color de estado de una programación (Vencido / Hoy / En N días / Realizado / Cancelado). */
function mant_badge(array $m): string
{
    if ($m['estado'] === 'Realizado') return badge('Realizado', 'success');
    if ($m['estado'] === 'Cancelado') return badge('Cancelado', 'secondary');
    $d = dias_hasta($m['fecha_programada']);
    if ($d < 0) return badge('Vencido · ' . abs($d) . ($d === -1 ? ' día' : ' días'), 'danger');
    if ($d === 0) return badge('Hoy', 'warning');
    return badge($d <= 7 ? 'En ' . $d . ($d === 1 ? ' día' : ' días') : 'Programado', $d <= 7 ? 'warning' : 'info');
}
