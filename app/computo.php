<?php
/**
 * Lógica compartida del inventario de cómputo: filtros, consulta y resúmenes.
 * Los computadores viven en la tabla `equipos` (tipos de COMPUTO_TIPOS) para integrarse con la mesa de ayuda.
 */

const FILTROS_COMPUTO = ['b', 'empresa', 'tipo', 'marca', 'so', 'lic_so', 'lic_of', 'lic_av', 'lic', 'renov', 'estado', 'desde', 'hasta'];

/** Lee los filtros del querystring. Devuelve [condiciones, parámetros, etiquetas de filtros activos, valores]. */
function computo_filtros(array $src): array
{
    [$scope, $params] = equipo_scope('eq');
    $w = [$scope, 'eq.tipo IN (' . implode(',', array_fill(0, count(COMPUTO_TIPOS), '?')) . ')'];
    $params = array_merge($params, COMPUTO_TIPOS);
    $act = [];
    $f = [];
    foreach (FILTROS_COMPUTO as $k) $f[$k] = isset($src[$k]) && $src[$k] !== '' ? trim((string)$src[$k]) : null;

    if ($f['b']) {
        $w[] = '(eq.codigo LIKE ? OR eq.responsable LIKE ? OR eq.cargo LIKE ? OR eq.correos LIKE ? OR eq.serial LIKE ? OR eq.marca LIKE ? OR eq.modelo LIKE ? OR eq.procesador LIKE ? OR u.nombre LIKE ?)';
        for ($i = 0; $i < 9; $i++) $params[] = '%' . $f['b'] . '%';
        $act['b'] = 'Búsqueda: ' . $f['b'];
    }
    if ($f['empresa'] && alcance() === 'todos') { $w[] = 'eq.empresa_id = ?'; $params[] = (int)$f['empresa']; $act['empresa'] = 'Propietario: ' . (val('SELECT nombre FROM empresas WHERE id = ?', [(int)$f['empresa']]) ?? '?'); }
    if ($f['tipo'] && in_array($f['tipo'], COMPUTO_TIPOS, true)) { $w[] = 'eq.tipo = ?'; $params[] = $f['tipo']; $act['tipo'] = 'Tipo: ' . $f['tipo']; }
    if ($f['marca']) { $w[] = 'eq.marca = ?'; $params[] = $f['marca']; $act['marca'] = 'Marca: ' . $f['marca']; }
    if ($f['so']) { $w[] = "EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = 'so' AND s.nombre = ?)"; $params[] = $f['so']; $act['so'] = 'S.O.: ' . $f['so']; }
    foreach (['lic_so' => ['so', 'Licencia S.O.'], 'lic_of' => ['ofimatica', 'Licencia ofimática'], 'lic_av' => ['antivirus', 'Licencia antivirus']] as $k => [$cat, $lbl]) {
        if (!$f[$k]) continue;
        if ($f[$k] === 'Sin registrar') {
            $w[] = "NOT EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = '$cat')";
        } elseif (in_array($f[$k], LICENCIAS, true)) {
            $w[] = "EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = '$cat' AND s.licencia = ?)";
            $params[] = $f[$k];
        } else continue;
        $act[$k] = $lbl . ': ' . $f[$k];
    }
    if ($f['lic'] === 'no_legal') { $w[] = "EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.licencia = 'No legal')"; $act['lic'] = 'Con software no legal'; }
    if ($f['lic'] === 'legal') { $w[] = "NOT EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.licencia = 'No legal')"; $act['lic'] = 'Todo el software legal'; }
    if ($f['lic'] === 'av_vencido') { $w[] = "EXISTS (SELECT 1 FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = 'antivirus' AND s.vence IS NOT NULL AND s.vence < ?)"; $params[] = today(); $act['lic'] = 'Antivirus vencido'; }
    if ($f['renov'] && in_array($f['renov'], RENOVACION, true)) { $w[] = 'eq.renovacion = ?'; $params[] = $f['renov']; $act['renov'] = 'Renovación: ' . $f['renov']; }
    if ($f['renov'] === 'Sin definir') { $w[] = "(eq.renovacion IS NULL OR eq.renovacion = '')"; $act['renov'] = 'Renovación: sin definir'; }
    $estado = $f['estado'] ?? 'vigentes';
    if ($estado === 'vigentes') $w[] = "eq.estado <> 'Dado de baja'";
    elseif (in_array($estado, EQUIPO_ESTADOS, true)) { $w[] = 'eq.estado = ?'; $params[] = $estado; $act['estado'] = 'Estado: ' . $estado; }
    if ($d = valid_date($f['desde'])) { $w[] = 'eq.fecha_compra >= ?'; $params[] = $d; $act['desde'] = 'Compra desde ' . fdate($d); }
    if ($d = valid_date($f['hasta'])) { $w[] = 'eq.fecha_compra <= ?'; $params[] = $d; $act['hasta'] = 'Compra hasta ' . fdate($d); }
    return [implode(' AND ', $w), $params, $act, $f];
}

/** Consulta de computadores con el resumen de su software base. */
function computo_rows(string $where, array $params, string $limit = ''): array
{
    $sub = function (string $cat, string $col) {
        return "(SELECT s.$col FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = '$cat' ORDER BY s.id LIMIT 1)";
    };
    $sql = 'SELECT eq.*, emp.nombre AS empresa, u.nombre AS usuario_sistema, u.email AS usuario_email, '
        . $sub('so', 'nombre') . ' AS so_nombre, ' . $sub('so', 'licencia') . ' AS so_lic, '
        . $sub('ofimatica', 'nombre') . ' AS of_nombre, ' . $sub('ofimatica', 'licencia') . ' AS of_lic, '
        . $sub('antivirus', 'nombre') . ' AS av_nombre, ' . $sub('antivirus', 'licencia') . ' AS av_lic, ' . $sub('antivirus', 'vence') . ' AS av_vence, '
        . "(SELECT COUNT(*) FROM equipo_software s WHERE s.equipo_id = eq.id AND s.licencia = 'No legal') AS n_no_legal, "
        . "(SELECT COUNT(*) FROM equipo_software s WHERE s.equipo_id = eq.id AND s.categoria = 'otro') AS n_otros, "
        . "(SELECT COUNT(*) FROM tickets t WHERE t.equipo_id = eq.id AND t.estado IN ('Abierto','En proceso','En espera')) AS tickets_abiertos "
        . 'FROM equipos eq LEFT JOIN empresas emp ON emp.id = eq.empresa_id LEFT JOIN usuarios u ON u.id = eq.usuario_id '
        . "WHERE $where ORDER BY emp.nombre, eq.responsable, eq.codigo $limit";
    return all($sql, $params);
}
function computo_count(string $where, array $params): int
{
    return (int)val("SELECT COUNT(*) FROM equipos eq LEFT JOIN usuarios u ON u.id = eq.usuario_id WHERE $where", $params);
}

/** Nombre del usuario del equipo (texto libre o usuario del sistema). */
function computo_usuario(array $r): string { return $r['responsable'] ?: ($r['usuario_sistema'] ?? '') ?: '—'; }

/** Renovación sugerida según años desde la compra. */
function renovacion_sugerida(?string $fechaCompra): ?string
{
    if (!$fechaCompra) return null;
    $anios = (time() - strtotime($fechaCompra)) / (365.25 * 86400);
    return $anios < 3 ? 'Bajo' : ($anios < 5 ? 'Medio' : 'Alto');
}

/** Querystring de filtros actuales más cambios. */
function computo_qs(array $f, array $cambios = [], string $page = 'computo_lista'): string
{
    $q = array_filter(array_merge($f, $cambios), fn($v) => $v !== null && $v !== '');
    return url($page, $q);
}

function lic_badge(?string $lic, ?string $nombre = null): string
{
    if (!$nombre && !$lic) return '<span class="muted small">Sin registrar</span>';
    return e($nombre ?: '—') . '<br>' . badge($lic ?: 'Sin registrar');
}

/** Formulario de filtros compartido por el dashboard y el listado. */
function computo_filtros_form(array $f, array $act, string $page, bool $compacto = false): string
{
    $marcas = array_column(all("SELECT DISTINCT marca FROM equipos WHERE marca IS NOT NULL AND marca <> '' AND tipo IN ('" . implode("','", COMPUTO_TIPOS) . "') ORDER BY marca"), 'marca');
    $sos = array_column(all("SELECT DISTINCT nombre FROM equipo_software WHERE categoria = 'so' ORDER BY nombre"), 'nombre');
    $licOpts = array_combine(LICENCIAS, LICENCIAS) + ['Sin registrar' => 'Sin registrar'];
    $sel = fn($name, $label, $opts, $todos = 'Todos') => '<label class="form-label mb-0">' . e($label)
        . '<select class="form-select" name="' . $name . '" data-autosubmit><option value="">' . e($todos) . '</option>' . options($opts, $f[$name] ?? '') . '</select></label>';
    $h = '<form method="get" class="panel" aria-label="Filtros"><input type="hidden" name="p" value="' . e($page) . '">';
    $h .= '<div class="d-flex gap-2 mb-3 flex-wrap"><input class="form-control grow" style="flex:1 1 280px" type="search" name="b" value="' . e($f['b'] ?? '') . '" placeholder="Buscar usuario, cargo, correo, serial, marca, modelo, procesador…">'
        . '<button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button></div>';
    $h .= '<div class="filters-grid">';
    if (alcance() === 'todos') $h .= $sel('empresa', 'Propietario', empresas_list());
    $h .= $sel('tipo', 'Tipo', array_combine(COMPUTO_TIPOS, COMPUTO_TIPOS));
    $h .= $sel('marca', 'Marca', array_combine($marcas, $marcas) ?: [], 'Todas');
    $h .= $sel('so', 'Sistema operativo', array_combine($sos, $sos) ?: []);
    $h .= $sel('lic', 'Licenciamiento', ['no_legal' => 'Con software no legal', 'legal' => 'Todo legal', 'av_vencido' => 'Antivirus vencido']);
    $h .= $sel('renov', 'Renovación', array_combine(RENOVACION, RENOVACION) + ['Sin definir' => 'Sin definir'], 'Todas');
    if (!$compacto) {
        $h .= $sel('lic_so', 'Licencia S.O.', $licOpts, 'Todas');
        $h .= $sel('lic_of', 'Licencia ofimática', $licOpts, 'Todas');
        $h .= $sel('lic_av', 'Licencia antivirus', $licOpts, 'Todas');
        $h .= '<label class="form-label mb-0">Estado<select class="form-select" name="estado" data-autosubmit>' . options(['vigentes' => 'Vigentes', 'todos' => 'Todos'] + array_combine(EQUIPO_ESTADOS, EQUIPO_ESTADOS), $f['estado'] ?? 'vigentes') . '</select></label>';
        $h .= '<label class="form-label mb-0">Compra desde<input class="form-control" type="date" name="desde" value="' . e($f['desde'] ?? '') . '" data-autosubmit></label>';
        $h .= '<label class="form-label mb-0">Compra hasta<input class="form-control" type="date" name="hasta" value="' . e($f['hasta'] ?? '') . '" data-autosubmit></label>';
    }
    $h .= '</div>';
    if ($act) {
        $h .= '<div class="d-flex flex-wrap gap-2 align-items-center mt-3"><span class="small muted">Filtros activos:</span>';
        foreach ($act as $k => $lbl) $h .= '<a class="chip-filter" href="' . e(computo_qs($f, [$k => null], $page)) . '" title="Quitar filtro">' . e($lbl) . ' <i class="bi bi-x"></i></a>';
        $h .= '<a class="small ms-1" href="' . url($page) . '">Limpiar todo</a></div>';
    }
    return $h . '</form>';
}

/** Construye el informe PDF (carta horizontal) del listado filtrado. */
function computo_pdf(array $rows, array $filtros): SimplePdf
{
    global $CONFIG;
    $pdf = new SimplePdf(true);
    $M = 30.0;
    $W = $pdf->w - 2 * $M;
    $navy = '#102130'; $teal = '#0b6e63'; $red = '#b4310a'; $muted = '#56616d'; $line = '#dfe4e9';
    $licColor = fn($l) => $l === 'Legal' ? $teal : ($l === 'No legal' ? $red : $muted);
    $cols = [
        ['Código', 40], ['Usuario / cargo', 90], ['Propietario', 62], ['Tipo', 46], ['Marca / modelo', 84], ['Procesador', 62],
        ['RAM', 30], ['DD', 46], ['S.O.', 64], ['Ofimática', 64], ['Antivirus', 64], ['Compra', 48], ['Renov.', 32],
    ];
    $props = all("SELECT nombre, nit FROM empresas WHERE tipo = 'propia' AND activo = 1 ORDER BY id LIMIT 2");
    $sub = implode(' · ', array_map(fn($p) => $p['nombre'] . ($p['nit'] ? ' (NIT ' . $p['nit'] . ')' : ''), $props));
    $appName = $CONFIG['app_name'] ?? 'MAVAL';

    $header = function () use ($pdf, $M, $W, $navy, $muted, $sub, $appName) {
        $pdf->rect($M, $M, 30, 30, '#0f8b7d');
        $pdf->text($M, $M + 20.5, mb_substr($appName, 0, 1), 15, true, '#ffffff', 'C', 30);
        $pdf->text($M + 40, $M + 13, 'Informe de equipos de cómputo', 15, true, '#17212b');
        $pdf->text($M + 40, $M + 27, $pdf->fit($sub, 430, 9), 9, false, $muted);
        $pdf->text($M, $M + 13, 'Generado: ' . date('d/m/Y H:i'), 8.5, false, '#3f4a55', 'R', $W);
        $pdf->text($M, $M + 25, 'Por: ' . (user()['nombre'] ?? ''), 8.5, false, '#3f4a55', 'R', $W);
        $pdf->line($M, $M + 38, $M + $W, $M + 38, $navy, 1.5);
    };
    $footer = function () use ($pdf, $M, $W, $muted, $line, $appName) {
        $y = $pdf->h - 22;
        $pdf->line($M, $y - 10, $M + $W, $y - 10, $line);
        $pdf->text($M, $y, $appName . ' - Inventario de cómputo', 7.5, false, $muted);
        $pdf->text($M, $y, 'Página ' . $pdf->pageNo() . ' de {nb}', 7.5, false, $muted, 'R', $W);
    };
    $tableHead = function (float $y) use ($pdf, $M, $W, $cols, $navy) {
        $pdf->rect($M, $y, $W, 18, $navy);
        $x = $M;
        foreach ($cols as [$t, $cw]) { $pdf->text($x + 4, $y + 12, $t, 7.5, true, '#ffffff'); $x += $cw; }
        return $y + 18;
    };

    $pdf->addPage();
    $header();
    $y = $M + 54;
    $pdf->text($M, $y, $pdf->fit('Filtros aplicados: ' . ($filtros ? implode(' · ', $filtros) : 'ninguno (todo el inventario vigente)'), $W, 8.5), 8.5, false, '#3f4a55');
    $y += 14;

    // Resumen
    $cnt = fn($k, $v) => count(array_filter($rows, fn($r) => ($r[$k] ?? '') === $v));
    $kpis = [
        ['Equipos en el informe', (string)count($rows), '#17212b'],
        ['S.O. no legal', (string)$cnt('so_lic', 'No legal'), $red],
        ['Ofimática no legal', (string)$cnt('of_lic', 'No legal'), $red],
        ['Antivirus no legal / sin', (string)($cnt('av_lic', 'No legal') + count(array_filter($rows, fn($r) => !$r['av_lic']))), $red],
        ['Renovación alta', (string)$cnt('renovacion', 'Alto'), '#17212b'],
    ];
    $kw = ($W - 4 * 10) / 5;
    foreach ($kpis as $i => [$lbl, $v, $c]) {
        $x = $M + $i * ($kw + 10);
        $pdf->rect($x, $y, $kw, 40, '', $line, 0.8);
        $pdf->text($x + 10, $y + 14, $lbl, 8, false, $muted);
        $pdf->text($x + 10, $y + 33, $v, 16, true, $v === '0' ? '#17212b' : $c);
    }
    $y += 54;
    $y = $tableHead($y);

    $rowH = 24.0;
    foreach ($rows as $i => $r) {
        if ($y + $rowH > $pdf->h - 44) {
            $footer();
            $pdf->addPage();
            $header();
            $y = $tableHead($M + 50);
        }
        if ($i % 2) $pdf->rect($M, $y, $W, $rowH, '#f6f8f9');
        $x = $M;
        $cells = [
            [$r['codigo'], '', null],
            [computo_usuario($r), $r['cargo'] ?? '', null],
            [$r['empresa'] ?? '—', '', null],
            [$r['tipo'], $r['estado'] !== 'Activo' ? $r['estado'] : '', null],
            [trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')) ?: '—', $r['serial'] ? 'S/N ' . $r['serial'] : '', null],
            [$r['procesador'] ?: '—', '', null],
            [$r['ram'] ?: '—', '', null],
            [$r['disco'] ?: '—', '', null],
            [$r['so_nombre'] ?: 'Sin registrar', $r['so_lic'] ?: '', $r['so_lic']],
            [$r['of_nombre'] ?: 'Sin registrar', $r['of_lic'] ?: '', $r['of_lic']],
            [$r['av_nombre'] ?: 'Sin registrar', $r['av_lic'] ? $r['av_lic'] . ($r['av_vence'] && $r['av_vence'] < today() ? ' · vencido' : '') : '', $r['av_vence'] && $r['av_vence'] < today() ? 'No legal' : $r['av_lic']],
            [$r['fecha_compra'] ? date('d/m/Y', strtotime($r['fecha_compra'])) : '—', '', null],
            [$r['renovacion'] ?: '—', '', null],
        ];
        foreach ($cells as $c => [$t1, $t2, $lic]) {
            $cw = $cols[$c][1] - 7;
            $boldMain = $c === 1;
            $renovColor = $c === 12 ? ($t1 === 'Alto' ? $red : ($t1 === 'Medio' ? '#8a5a00' : '#17212b')) : '#17212b';
            if ($t2 === '') {
                $pdf->text($x + 4, $y + 15, $pdf->fit((string)$t1, $cw, 7.3, $boldMain || $c === 12), 7.3, $boldMain || $c === 12, $renovColor);
            } else {
                $pdf->text($x + 4, $y + 10, $pdf->fit((string)$t1, $cw, 7.3, $boldMain), 7.3, $boldMain, '#17212b');
                $pdf->text($x + 4, $y + 19.5, $pdf->fit((string)$t2, $cw, 6.6, $lic !== null), 6.6, $lic !== null, $lic !== null ? $licColor($lic) : $muted);
            }
            $x += $cols[$c][1];
        }
        $y += $rowH;
        $pdf->line($M, $y, $M + $W, $y, $line, 0.4);
    }
    if (!$rows) $pdf->text($M, $y + 20, 'No hay equipos con los filtros seleccionados.', 9, false, $muted, 'C', $W);
    $footer();
    return $pdf;
}
