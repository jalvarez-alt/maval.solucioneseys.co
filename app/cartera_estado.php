<?php
/**
 * Cartera por cliente: agrupa las facturas pendientes de cada cliente y arma el estado de cuenta que se envía por correo.
 * Las facturas guardan el cliente como texto (nombre y, a veces, NIT), así que el cliente se identifica por su nombre
 * normalizado y se unen los nombres que comparten NIT.
 */
require_once APP_DIR . '/empresas_importar.php';  // emp_norm(), emp_nit_clave()

/** Clave de un cliente: nombre en minúsculas, sin tildes, puntos ni espacios («Camiones S.A.S.» = «CAMIONES SAS»). */
function car_cli_clave(string $nombre): string { return mb_substr(str_replace(' ', '', emp_norm($nombre)), 0, 160); }

/**
 * Clientes con saldo pendiente (facturas no anuladas con saldo), ordenados por saldo descendente.
 * Devuelve clave => [clave, claves (todas las del grupo), nombre, nit, n, vencidas, total, pagado, saldo, vencido, facturas].
 */
function car_clientes(int $empresa = 0): array
{
    $hoy = today();
    $sql = "SELECT f.id, f.empresa_id, e.nombre AS empresa, f.numero, f.cliente_nombre, f.cliente_nit, f.fecha_emision, f.fecha_vencimiento, f.concepto,
            f.total, COALESCE(p.pagado, 0) AS pagado, (f.total - COALESCE(p.pagado, 0)) AS saldo
        FROM facturas f JOIN empresas e ON e.id = f.empresa_id
        LEFT JOIN (SELECT factura_id, SUM(valor) AS pagado FROM pagos GROUP BY factura_id) p ON p.factura_id = f.id
        WHERE f.anulada = 0 AND (f.total - COALESCE(p.pagado, 0)) > 0.5" . ($empresa ? ' AND f.empresa_id = ?' : '') . '
        ORDER BY f.fecha_vencimiento ASC, f.id ASC';
    $rows = all($sql, $empresa ? [$empresa] : []);

    // Unión de nombres que comparten NIT; la raíz es la clave menor para que no cambie según el orden de las facturas
    $padre = [];
    $raiz = function (string $k) use (&$padre) { while ($padre[$k] !== $k) $k = $padre[$k] = $padre[$padre[$k]]; return $k; };
    $porNit = [];
    foreach ($rows as $r) {
        $k = car_cli_clave($r['cliente_nombre']);
        $padre[$k] ??= $k;
        $nit = emp_nit_clave($r['cliente_nit']);
        if ($nit === '') continue;
        if (!isset($porNit[$nit])) { $porNit[$nit] = $k; continue; }
        [$a, $b] = [$raiz($k), $raiz($porNit[$nit])];
        if ($a !== $b) { if (strcmp($a, $b) < 0) $padre[$b] = $a; else $padre[$a] = $b; }
    }

    $cli = [];
    foreach ($rows as $r) {
        $k = car_cli_clave($r['cliente_nombre']);
        $g = $raiz($k);
        $cli[$g] ??= ['clave' => $g, 'claves' => [], 'nombres' => [], 'nit' => '', 'n' => 0, 'vencidas' => 0, 'total' => 0.0, 'pagado' => 0.0, 'saldo' => 0.0, 'vencido' => 0.0, 'facturas' => []];
        $c = &$cli[$g];
        $c['claves'][$k] = $k;
        $c['nombres'][$r['cliente_nombre']] = ($c['nombres'][$r['cliente_nombre']] ?? 0) + 1;
        if ($c['nit'] === '' && trim((string)$r['cliente_nit']) !== '') $c['nit'] = trim((string)$r['cliente_nit']);
        $r['dias'] = (int)floor((strtotime($hoy) - strtotime($r['fecha_vencimiento'])) / 86400);
        $c['n']++;
        $c['total'] += (float)$r['total'];
        $c['pagado'] += (float)$r['pagado'];
        $c['saldo'] += (float)$r['saldo'];
        if ($r['dias'] > 0) { $c['vencidas']++; $c['vencido'] += (float)$r['saldo']; }
        $c['facturas'][] = $r;
        unset($c);
    }
    foreach ($cli as &$c) {
        arsort($c['nombres']);
        $c['nombre'] = (string)array_key_first($c['nombres']);   // el nombre que más se repite
        $c['claves'] = array_values($c['claves']);
    }
    unset($c);
    uasort($cli, fn($a, $b) => $b['saldo'] <=> $a['saldo']);
    return $cli;
}

/** Cliente por su clave (sirve cualquiera de las claves del grupo). */
function car_cliente(string $clave, int $empresa = 0): ?array
{
    foreach (car_clientes($empresa) as $c) if (in_array($clave, $c['claves'], true)) return $c;
    return null;
}

/** Datos de contacto del cliente registrados en Empresas (por NIT o por nombre). */
function car_contacto(array $cli): ?array
{
    $nit = emp_nit_clave($cli['nit']);
    foreach (all("SELECT id, nombre, nit, email, contacto, telefono FROM empresas WHERE tipo = 'cliente' ORDER BY activo DESC, id") as $e) {
        if (($nit !== '' && emp_nit_clave($e['nit']) === $nit) || in_array(car_cli_clave($e['nombre']), $cli['claves'], true)) return $e;
    }
    return null;
}

/** Último envío y número de envíos por cliente: clave => [fecha, ok, n]. */
function car_envios_resumen(): array
{
    $out = [];
    foreach (all('SELECT cliente_clave, fecha, ok FROM cartera_envios ORDER BY fecha DESC, id DESC') as $r) {
        $k = $r['cliente_clave'];
        if (!isset($out[$k])) $out[$k] = ['fecha' => $r['fecha'], 'ok' => (int)$r['ok'], 'n' => 0];
        $out[$k]['n']++;
    }
    return $out;
}

/** Une el resumen de envíos de todas las claves del grupo del cliente. */
function car_envio_cliente(array $cli, array $resumen): ?array
{
    $r = null;
    foreach ($cli['claves'] as $k) {
        if (!isset($resumen[$k])) continue;
        $n = ($r['n'] ?? 0) + $resumen[$k]['n'];
        if (!$r || $resumen[$k]['fecha'] > $r['fecha']) $r = $resumen[$k];
        $r['n'] = $n;
    }
    return $r;
}

/** Nombre visible del remitente del estado de cuenta: «Cartera - Empresa» si todas las facturas son de una misma empresa emisora. */
function car_remitente(array $facturas): string
{
    $emisores = array_unique(array_column($facturas, 'empresa'));
    return count($emisores) === 1 ? 'Cartera - ' . reset($emisores) : 'Cartera';
}

/** Valida una lista de correos separados por coma o punto y coma. Devuelve [válidos, inválidos]. */
function car_correos(string $lista): array
{
    $ok = $mal = [];
    foreach (preg_split('/[,;\s]+/', $lista) as $d) {
        $d = trim($d);
        if ($d === '') continue;
        if (filter_var($d, FILTER_VALIDATE_EMAIL)) $ok[mb_strtolower($d)] = $d; else $mal[] = $d;
    }
    return [array_values($ok), $mal];
}

/** HTML del estado de cuenta (cuerpo completo del correo). $facturas: las seleccionadas del cliente. */
function car_estado_html(array $cli, array $facturas, string $mensaje): string
{
    $saldo = array_sum(array_map(fn($f) => (float)$f['saldo'], $facturas));
    $vencido = array_sum(array_map(fn($f) => $f['dias'] > 0 ? (float)$f['saldo'] : 0, $facturas));
    $emisores = array_unique(array_column($facturas, 'empresa'));
    $th = 'padding:7px 8px;background:#f1f5f9;color:#334155;font-size:12px;text-align:left;border-bottom:1px solid #e2e8f0';
    $td = 'padding:7px 8px;border-bottom:1px solid #eef2f6;font-size:12px;vertical-align:top';
    $r = 'text-align:right;white-space:nowrap';

    $h = $mensaje !== '' ? '<div style="white-space:pre-wrap;margin:0 0 14px">' . e($mensaje) . '</div>' : '';
    $h .= correo_ficha([
        'Cliente' => '<strong>' . e($cli['nombre']) . '</strong>',
        'NIT' => e($cli['nit']),
        'Fecha de corte' => e(fecha_larga(today())),
        'Facturas pendientes' => (string)count($facturas),
        'Total vencido' => $vencido > 0 ? '<span style="color:#b91c1c">' . e(money($vencido)) . '</span>' : null,
    ]);
    $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:14px 0 0">'
        . '<tr><th style="' . $th . '">Factura</th><th style="' . $th . '">Emisión</th><th style="' . $th . '">Vencimiento</th>'
        . '<th style="' . $th . ';' . $r . '">Valor</th><th style="' . $th . ';' . $r . '">Saldo</th></tr>';
    foreach ($emisores as $emisor) {
        $grupo = array_filter($facturas, fn($f) => $f['empresa'] === $emisor);
        if (count($emisores) > 1) $h .= '<tr><td colspan="5" style="' . $td . ';background:#f8fafc;font-weight:600;color:#0f766e">Emitidas por ' . e($emisor) . '</td></tr>';
        foreach ($grupo as $f) {
            $venc = e(fdate($f['fecha_vencimiento'])) . ($f['dias'] > 0 ? '<br><span style="color:#b91c1c;font-size:11px">Vencida hace ' . $f['dias'] . ' día' . ($f['dias'] === 1 ? '' : 's') . '</span>' : '');
            $h .= '<tr><td style="' . $td . '"><strong>' . e($f['numero']) . '</strong>' . ($f['concepto'] ? '<br><span style="color:#64748b;font-size:11px">' . e(mb_strimwidth($f['concepto'], 0, 60, '…')) . '</span>' : '') . '</td>'
                . '<td style="' . $td . ';white-space:nowrap">' . e(fdate($f['fecha_emision'])) . '</td><td style="' . $td . '">' . $venc . '</td>'
                . '<td style="' . $td . ';' . $r . '">' . e(money($f['total'])) . '</td><td style="' . $td . ';' . $r . ';font-weight:600">' . e(money($f['saldo'])) . '</td></tr>';
        }
        if (count($emisores) > 1) {
            $sub = array_sum(array_map(fn($f) => (float)$f['saldo'], $grupo));
            $h .= '<tr><td colspan="4" style="' . $td . ';' . $r . ';color:#64748b">Subtotal ' . e($emisor) . '</td><td style="' . $td . ';' . $r . '">' . e(money($sub)) . '</td></tr>';
        }
    }
    $h .= '<tr><td colspan="4" style="padding:10px 8px;font-size:14px;font-weight:700;' . $r . '">Total cartera pendiente</td>'
        . '<td style="padding:10px 8px;font-size:14px;font-weight:700;color:#0f766e;' . $r . '">' . e(money($saldo)) . '</td></tr></table>';
    return correo_plantilla('Estado de cuenta', $h, null, null, 'Si tiene alguna inquietud sobre este estado de cuenta o ya realizó el pago, responda a este correo con el soporte.');
}
