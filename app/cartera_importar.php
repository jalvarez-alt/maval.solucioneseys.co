<?php
/**
 * Cartera por Excel: importación de facturas (con sus abonos) y exportación, con plantilla, vista previa y validación.
 */
require_once APP_DIR . '/importar.php';           // imp_fecha()
require_once APP_DIR . '/empresas_importar.php';  // emp_norm(), emp_texto(), emp_nit_clave()

/** Columnas: campo => [encabezado de la plantilla, alias aceptados]. */
function car_campos(): array
{
    return [
        'empresa' => ['Empresa', ['empresa', 'empresa que factura', 'emisor', 'facturador', 'empresa emisora', 'sociedad']],
        'numero' => ['Factura', ['factura', 'numero', 'n factura', 'no factura', 'numero factura', 'n de factura', 'nro factura', 'consecutivo']],
        'cliente' => ['Cliente', ['cliente', 'razon social', 'nombre cliente', 'tercero']],
        'nit' => ['NIT', ['nit', 'nit cliente', 'identificacion', 'nit cc', 'cc o nit']],
        'emision' => ['Emisión', ['emision', 'fecha emision', 'fecha', 'fecha factura', 'fecha de emision']],
        'vencimiento' => ['Vencimiento', ['vencimiento', 'fecha vencimiento', 'vence', 'fecha de vencimiento']],
        'concepto' => ['Concepto', ['concepto', 'descripcion', 'detalle']],
        'subtotal' => ['Subtotal', ['subtotal', 'sub total', 'base', 'valor antes de iva']],
        'iva' => ['IVA', ['iva', 'impuesto']],
        'retenciones' => ['Retenciones', ['retenciones', 'retencion', 'rete']],
        'total' => ['Total', ['total', 'valor total', 'valor factura', 'valor']],
        'abonado' => ['Abonado', ['abonado', 'abonos', 'pagado', 'recaudado', 'abono']],
        'saldo' => ['Saldo', ['saldo', 'saldo pendiente']],
        'fecha_abono' => ['Fecha abono', ['fecha abono', 'fecha pago', 'fecha de pago', 'fecha del abono']],
        'notas' => ['Notas', ['notas', 'observaciones', 'observacion']],
    ];
}

function car_mapear(array $filas): array
{
    $alias = [];
    foreach (car_campos() as $k => [$lbl, $als]) { $alias[emp_norm($lbl)] = $k; foreach ($als as $a) $alias[emp_norm($a)] ??= $k; }
    $mejor = [-1, [], []];
    foreach (array_slice($filas, 0, 10, true) as $i => $f) {
        $map = []; $desc = [];
        foreach ($f as $c => $h) {
            $n = emp_norm($h);
            if ($n === '') continue;
            if (isset($alias[$n]) && !isset($map[$alias[$n]])) $map[$alias[$n]] = $c; else $desc[] = (string)$h;
        }
        if (count($map) > count($mejor[1])) $mejor = [$i, $map, $desc];
    }
    return $mejor;
}

/** Valor numérico de una celda (acepta 1.250.000, $ 400.000, 1250000,50…). */
function car_num($v): float { return (is_int($v) || is_float($v)) ? (float)$v : (float)parse_money((string)$v); }

/**
 * Valida todas las filas. $empresaDefecto = id de la empresa que factura cuando el archivo no trae la columna Empresa (0 = usar la del archivo).
 */
function car_analizar(array $filas, int $empresaDefecto, bool $actualizar, bool $puedeAbonar): array
{
    [$hIdx, $map, $desconocidas] = car_mapear($filas);
    // Solo el número de factura es imprescindible: cliente, fechas y valores se exigen por fila únicamente en las facturas nuevas.
    if (!isset($map['numero'])) throw new RuntimeException('Falta la columna «Factura». Use la plantilla.');
    $propias = all("SELECT id, nombre, nit FROM empresas WHERE tipo = 'propia' AND activo = 1");
    $porNom = []; $porNit = [];
    foreach ($propias as $e) { $porNom[emp_norm($e['nombre'])] = $e; if ($k = emp_nit_clave($e['nit'])) $porNit[$k] = $e; }
    $defecto = $empresaDefecto ? (array_values(array_filter($propias, fn($e) => (int)$e['id'] === $empresaDefecto))[0] ?? null) : null;
    if (!$defecto && !isset($map['empresa']) && count($propias) === 1) $defecto = $propias[0];
    if (!$defecto && !isset($map['empresa'])) throw new RuntimeException('Indique la empresa que factura (o agregue la columna «Empresa» al archivo).');

    $items = []; $vistos = [];
    foreach ($filas as $i => $f) {
        if ($i <= $hIdx) continue;
        $g = fn($k) => isset($map[$k]) ? ($f[$map[$k]] ?? '') : '';
        $gs = fn($k) => emp_texto($g($k));
        if (implode('', array_map(fn($v) => trim((string)$v), $f)) === '') continue;
        if (str_starts_with(mb_strtoupper($gs('numero')), 'EJEMPLO')) continue;
        if (count($items) >= 3000) throw new RuntimeException('El archivo supera el máximo de 3.000 facturas por importación. Divídalo en varios archivos.');
        $err = []; $av = [];
        // Empresa que factura
        $emp = $defecto;
        if (isset($map['empresa']) && $gs('empresa') !== '') {
            $n = emp_norm($gs('empresa'));
            $emp = $porNom[$n] ?? ($porNit[emp_nit_clave($gs('empresa'))] ?? null);
            if (!$emp) $err[] = 'La empresa «' . $gs('empresa') . '» no existe entre las empresas propias.';
        } elseif (!$emp) $err[] = 'Falta la empresa que factura.';
        $numero = mb_strtoupper(mb_substr($gs('numero'), 0, 40));
        $d = ['numero' => $numero, 'cliente_nombre' => mb_substr($gs('cliente'), 0, 150), 'cliente_nit' => mb_substr($gs('nit'), 0, 30) ?: null,
            'concepto' => mb_substr($gs('concepto'), 0, 255) ?: null, 'notas' => $gs('notas') ?: null];
        if ($emp) $d['empresa_id'] = (int)$emp['id'];
        $exist = ($emp && $numero !== '') ? one('SELECT id, total, fecha_emision FROM facturas WHERE empresa_id = ? AND numero = ?', [$emp['id'], $numero]) : null;
        $nueva = !$exist || !$actualizar;   // en una actualización lo que venga vacío se conserva
        if ($numero === '') $err[] = 'Falta el número de factura.';
        if ($d['cliente_nombre'] === '') { if ($nueva) $err[] = 'Falta el cliente.'; else unset($d['cliente_nombre']); }
        // Fechas
        [$fe, $malE] = imp_fecha($g('emision'));
        if ($malE || (!$fe && $nueva)) $err[] = 'Fecha de emisión ' . ($gs('emision') === '' ? 'vacía' : '«' . $gs('emision') . '» no válida') . ' (use dd/mm/aaaa).';
        [$fv, $malV] = imp_fecha($g('vencimiento'));
        if ($malV) { $err[] = 'Vencimiento «' . $gs('vencimiento') . '» no es una fecha válida.'; $fv = null; }
        if ($fe || $fv) {
            $base = $fe ?: ($exist['fecha_emision'] ?? null);
            if ($fe) $d['fecha_emision'] = $fe;
            if ($base) { $fv = $fv ?: ($fe ? $fe : null); if ($fv && $fv < $base) $err[] = 'El vencimiento es anterior a la emisión.'; if ($fv) $d['fecha_vencimiento'] = $fv; }
        }
        // Valores
        $sub = $gs('subtotal') !== '' ? car_num($g('subtotal')) : null;
        $iva = $gs('iva') !== '' ? car_num($g('iva')) : 0.0;
        $ret = $gs('retenciones') !== '' ? car_num($g('retenciones')) : 0.0;
        $tot = $gs('total') !== '' ? car_num($g('total')) : null;
        $sinValores = $tot === null && $sub === null;   // fila de una factura existente que solo trae abonos u otros datos
        if ($sinValores && !$nueva) { $tot = (float)$exist['total']; }
        else {
            if ($tot === null) $tot = $sub === null ? 0.0 : round($sub + $iva - $ret, 2);
            if ($sub === null) $sub = round($tot - $iva + $ret, 2);
            if ($tot <= 0) $err[] = 'El total debe ser mayor que cero.';
            $d += ['subtotal' => round($sub, 2), 'iva' => round($iva, 2), 'retenciones' => round($ret, 2), 'total' => round($tot, 2)];
        }
        // Abonos: «Abonado» o, si no viene, «Saldo» (abonado = total - saldo)
        $abonado = null;
        if ($gs('abonado') !== '') $abonado = car_num($g('abonado'));
        elseif ($gs('saldo') !== '') { $saldo = car_num($g('saldo')); $abonado = round($tot - $saldo, 2); if ($saldo < 0 || $saldo > $tot + 0.5) $err[] = 'El saldo debe estar entre 0 y el total.'; }
        if ($abonado !== null && ($abonado < 0 || $abonado - $tot > 0.5)) { $err[] = 'Lo abonado (' . money($abonado) . ') no puede ser negativo ni superar el total.'; $abonado = null; }
        [$fa, $malA] = imp_fecha($g('fecha_abono'));
        if ($malA) { $err[] = 'Fecha de abono «' . $gs('fecha_abono') . '» no válida.'; $fa = null; }
        // ¿Existe? (misma empresa y mismo número)
        if ($emp && $numero !== '') {
            $k = $emp['id'] . '|' . $numero;
            if (isset($vistos[$k])) $err[] = 'La factura ' . $numero . ' está repetida en el archivo (fila ' . $vistos[$k] . ').'; else $vistos[$k] = $i + 1;
        }
        $pagado = $exist ? (float)val('SELECT COALESCE(SUM(valor),0) FROM pagos WHERE factura_id = ?', [$exist['id']]) : 0.0;
        $abono = 0.0;
        if ($exist) {
            $accion = $actualizar ? 'actualizar' : 'omitir';
            if (!$actualizar) $av[] = 'Ya existe: se omite.';
            elseif ($pagado - $tot > 0.5) $err[] = 'El nuevo total es menor que lo ya abonado (' . money($pagado) . ').';
            if ($actualizar && $abonado !== null) { $abono = round($abonado - $pagado, 2); if ($abono < -0.5) { $av[] = 'Ya tiene abonos por ' . money($pagado) . ' (más que lo indicado): no se modifican.'; $abono = 0.0; } }
        } else {
            $accion = 'nuevo';
            $abono = $abonado ?? 0.0;
        }
        if ($abono > 0.5 && !$puedeAbonar) { $av[] = 'Su rol no puede registrar abonos: se ignoran.'; $abono = 0.0; }
        if ($err) $accion = 'error';
        $items[] = ['fila' => $i + 1, 'accion' => $accion, 'id' => $exist['id'] ?? null, 'empresa' => $emp['nombre'] ?? '—', 'datos' => $d, 'abono' => max(0, $abono), 'fecha_abono' => $fa,
            'errores' => $err, 'avisos' => $av, 'original' => array_map(fn($v) => emp_texto($v), $f)];
    }
    return ['items' => $items, 'desconocidas' => $desconocidas, 'columnas' => array_map(fn($k) => car_campos()[$k][0], array_keys($map)), 'encabezados' => array_map(fn($v) => emp_texto($v), $filas[$hIdx] ?? [])];
}

/** Ejecuta la importación ya validada. Devuelve los conteos y la suma importada. */
function car_ejecutar(array $analisis): array
{
    $pdo = db();
    $uid = (int)user()['id'];
    $cnt = ['nuevo' => 0, 'actualizar' => 0, 'omitir' => 0, 'error' => 0, 'abonos' => 0, 'valor' => 0.0];
    $pdo->beginTransaction();
    try {
        foreach ($analisis['items'] as $it) {
            $cnt[$it['accion']]++;
            if (!in_array($it['accion'], ['nuevo', 'actualizar'], true)) continue;
            $d = $it['datos'];
            if ($it['accion'] === 'nuevo') { $fid = insert('facturas', $d + ['creado_por' => $uid, 'creado' => now()]); $cnt['valor'] += $d['total']; }
            else { $fid = (int)$it['id']; unset($d['empresa_id']); update('facturas', array_filter($d, fn($v) => $v !== null), $fid); }
            if (($it['abono'] ?? 0) > 0.5) {
                insert('pagos', ['factura_id' => $fid, 'fecha' => $it['fecha_abono'] ?: today(), 'valor' => $it['abono'], 'metodo' => 'Otro', 'referencia' => null, 'notas' => 'Importado desde Excel', 'creado_por' => $uid, 'creado' => now()]);
                $cnt['abonos']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    error_log('MAVAL: ' . (user()['email'] ?? '?') . " importó cartera: {$cnt['nuevo']} facturas nuevas, {$cnt['actualizar']} actualizadas, {$cnt['abonos']} abonos.");
    return $cnt;
}

function car_plantilla(): void
{
    require_once APP_DIR . '/lib/xlsx.php';
    $headers = array_map(fn($c) => $c[0], array_values(car_campos()));
    $propias = array_values(array_column(all("SELECT nombre FROM empresas WHERE tipo = 'propia' AND activo = 1 ORDER BY nombre"), 'nombre'));
    $emp = $propias[0] ?? 'Mi Empresa S.A.S.';
    xlsx_download('plantilla_cartera.xlsx', [
        ['name' => 'Facturas', 'headers' => $headers,
         'rows' => [[$emp, 'EJEMPLO-001', 'Comercial Andina S.A.S.', '900123456-7', '2026-10-01', '2026-10-31', 'Soporte técnico mensual', 1000000, 190000, 0, 1190000, 500000, '', '2026-10-15', ''],
                    [$emp, 'EJEMPLO-002', 'Laura Gómez', '1130456789', '2026-10-05', '2026-11-04', 'Mantenimiento de equipos', 800000, 0, 0, 800000, '', '', '', 'Sin abonos']],
         'widths' => [26, 14, 30, 16, 12, 13, 30, 13, 11, 12, 13, 13, 12, 13, 24]],
        ['name' => 'Instrucciones', 'headers' => ['Columna', 'Qué escribir'], 'rows' => [
            ['Empresa', 'Empresa propia que emite la factura (como está en Empresas). Si todas son de la misma empresa puede omitir la columna y elegirla al subir el archivo.'],
            ['Factura', 'Obligatoria. Número de la factura (único por empresa).'], ['Cliente / NIT', 'Cliente obligatorio; NIT opcional.'],
            ['Emisión / Vencimiento', 'Fechas dd/mm/aaaa o con formato de fecha de Excel. Vencimiento vacío = igual a la emisión.'],
            ['Subtotal / IVA / Retenciones / Total', 'Número sin símbolo. Total = Subtotal + IVA − Retenciones; si solo trae Total, el subtotal se calcula.'],
            ['Abonado', 'Lo que el cliente ya pagó. Se registra como un abono con «Fecha abono» (vacía = hoy).'],
            ['Saldo', 'Alternativa a «Abonado»: lo que aún debe. Abonado = Total − Saldo.'], ['', ''],
            ['Importante', 'Las filas cuyo número de factura empieza por EJEMPLO se ignoran: bórrelas o reemplácelas.'],
            ['Actualizar', 'Si la factura ya existe (misma empresa y número) se actualiza; los abonos solo se agregan, nunca se borran.'],
            ['Exportar y volver a importar', 'El Excel que descarga desde Cartera también se puede editar y volver a importar.']], 'widths' => [34, 110]],
    ]);
}

/** Excel de la cartera filtrada (mismas columnas que la plantilla más Saldo, Días vencida y Estado). */
function car_exportar(array $rows, string $hoy): void
{
    require_once APP_DIR . '/lib/xlsx.php';
    $headers = ['Empresa', 'Factura', 'Cliente', 'NIT', 'Emisión', 'Vencimiento', 'Concepto', 'Subtotal', 'IVA', 'Retenciones', 'Total', 'Abonado', 'Saldo', 'Días vencida', 'Estado'];
    $out = [];
    foreach ($rows as $r) {
        $dv = $r['saldo'] > 0.5 && !$r['anulada'] ? max(0, (int)((strtotime($hoy) - strtotime($r['fecha_vencimiento'])) / 86400)) : 0;
        $out[] = [$r['empresa'], $r['numero'], $r['cliente_nombre'], $r['cliente_nit'], $r['fecha_emision'], $r['fecha_vencimiento'], $r['concepto'], (float)$r['subtotal'], (float)$r['iva'], (float)$r['retenciones'],
            (float)$r['total'], (float)$r['pagado'], (float)$r['saldo'], $dv, factura_estado($r)];
    }
    xlsx_download('cartera_' . date('Ymd_Hi') . '.xlsx', [['name' => 'Facturas', 'headers' => $headers, 'rows' => $out, 'widths' => [26, 14, 30, 16, 12, 13, 30, 13, 11, 12, 13, 13, 13, 12, 12]]]);
}
