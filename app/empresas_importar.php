<?php
/**
 * Importación y exportación de empresas desde/hacia Excel (.xlsx) o CSV: plantilla, validación con vista previa y carga.
 */

/** Columnas: campo => [encabezado de la plantilla, alias aceptados]. */
function emp_campos(): array
{
    return [
        'nombre' => ['Razón social', ['razon social', 'nombre', 'empresa', 'nombre empresa', 'cliente', 'razon social nombre']],
        'nit' => ['NIT', ['nit', 'identificacion', 'documento', 'cc o nit', 'nit cc']],
        'tipo' => ['Tipo', ['tipo', 'tipo empresa', 'clase']],
        'contacto' => ['Contacto', ['contacto', 'persona de contacto', 'responsable']],
        'telefono' => ['Teléfono', ['telefono', 'tel', 'celular', 'movil', 'telefono celular']],
        'email' => ['Correo', ['correo', 'email', 'e mail', 'correo electronico']],
        'direccion' => ['Dirección', ['direccion', 'domicilio', 'ubicacion']],
        'activo' => ['Estado', ['estado', 'activo', 'activa']],
    ];
}

function emp_norm($s): string
{
    $s = mb_strtolower(trim((string)$s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}
/** Dígitos del NIT sin el dígito de verificación (900.123.456-7 → 900123456). */
function emp_nit_clave($nit): string { return preg_replace('/\D/', '', explode('-', (string)$nit)[0]); }
/** Texto de una celda: los números de Excel (NIT, teléfonos) llegan como float y se pasan sin decimales. */
function emp_texto($v): string
{
    if (is_float($v) || is_int($v)) return (float)$v == (int)$v ? (string)(int)$v : (string)$v;
    return trim((string)$v);
}

/** Busca la fila de encabezados y relaciona cada campo con su columna. Devuelve [fila_encabezado, mapa, columnas_desconocidas]. */
function emp_mapear(array $filas): array
{
    $alias = [];
    foreach (emp_campos() as $k => [$lbl, $als]) { $alias[emp_norm($lbl)] = $k; foreach ($als as $a) $alias[emp_norm($a)] ??= $k; }
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

/** Valida todas las filas y decide qué se hará con cada una (nueva, actualizar, omitir o error). */
function emp_analizar(array $filas, bool $actualizar): array
{
    [$hIdx, $map, $desconocidas] = emp_mapear($filas);
    if (!isset($map['nombre']) && !isset($map['nit'])) throw new RuntimeException('No se reconocieron los encabezados. Use la plantilla: Razón social, NIT, Tipo, Contacto, Teléfono, Correo, Dirección y Estado.');
    $porNit = []; $porNombre = [];
    foreach (all('SELECT id, nombre, nit, tipo FROM empresas') as $e) {
        if ($k = emp_nit_clave($e['nit'])) $porNit[$k] ??= $e;
        $porNombre[emp_norm($e['nombre'])] ??= $e;
    }
    $items = []; $vistosNit = []; $vistosNom = [];
    foreach ($filas as $i => $f) {
        if ($i <= $hIdx) continue;
        $g = fn($k) => isset($map[$k]) ? emp_texto($f[$map[$k]] ?? '') : '';
        if (implode('', array_map(fn($v) => trim((string)$v), $f)) === '') continue;
        if (str_starts_with(mb_strtoupper($g('nombre')), 'EJEMPLO')) continue;
        if (count($items) >= 2000) throw new RuntimeException('El archivo supera el máximo de 2.000 empresas por importación. Divídalo en varios archivos.');
        $err = []; $av = []; $d = [];
        $nombre = mb_substr($g('nombre'), 0, 150);
        $nit = mb_substr($g('nit'), 0, 30);
        if ($nombre !== '') $d['nombre'] = $nombre;
        if ($nit !== '') $d['nit'] = $nit;
        foreach (['contacto' => 120, 'telefono' => 40, 'direccion' => 200] as $k => $max) if ($g($k) !== '') $d[$k] = mb_substr($g($k), 0, $max);
        if ($g('email') !== '') {
            $m = mb_strtolower($g('email'));
            if (filter_var($m, FILTER_VALIDATE_EMAIL)) $d['email'] = mb_substr($m, 0, 150); else $err[] = 'Correo «' . $g('email') . '» no válido.';
        }
        if ($g('tipo') !== '') {
            $t = emp_norm($g('tipo'));
            if (in_array($t, ['propia', 'propio', 'propias', 'mia', 'nuestra'], true)) $d['tipo'] = 'propia';
            elseif (in_array($t, ['cliente', 'clientes', 'externa', 'tercero'], true)) $d['tipo'] = 'cliente';
            else $err[] = 'Tipo «' . $g('tipo') . '» no reconocido (Propia o Cliente).';
        }
        if ($g('activo') !== '') {
            $a = emp_norm($g('activo'));
            if (in_array($a, ['activa', 'activo', 'si', 's', '1', 'true', 'vigente'], true)) $d['activo'] = 1;
            elseif (in_array($a, ['inactiva', 'inactivo', 'no', 'n', '0', 'false', 'retirada'], true)) $d['activo'] = 0;
            else $err[] = 'Estado «' . $g('activo') . '» no reconocido (Activa o Inactiva).';
        }
        // ¿Existe? Primero por NIT, luego por nombre
        $exist = ($nit !== '' && emp_nit_clave($nit) !== '' ? ($porNit[emp_nit_clave($nit)] ?? null) : null) ?? ($nombre !== '' ? ($porNombre[emp_norm($nombre)] ?? null) : null);
        // Duplicados dentro del archivo
        if ($nit !== '' && emp_nit_clave($nit) !== '') {
            if (isset($vistosNit[emp_nit_clave($nit)])) $err[] = 'NIT ' . $nit . ' repetido en el archivo (fila ' . $vistosNit[emp_nit_clave($nit)] . ').'; else $vistosNit[emp_nit_clave($nit)] = $i + 1;
        }
        if ($nombre !== '') {
            if (isset($vistosNom[emp_norm($nombre)])) $err[] = 'Empresa «' . $nombre . '» repetida en el archivo (fila ' . $vistosNom[emp_norm($nombre)] . ').'; else $vistosNom[emp_norm($nombre)] = $i + 1;
        }
        if ($exist) {
            $accion = $actualizar ? 'actualizar' : 'omitir';
            if (!$actualizar) $av[] = 'Ya existe («' . $exist['nombre'] . '»): se omite.';
            if ($actualizar && ($d['tipo'] ?? $exist['tipo']) !== 'propia' && $exist['tipo'] === 'propia' && (int)val('SELECT COUNT(*) FROM facturas WHERE empresa_id = ?', [$exist['id']]) > 0) $err[] = 'Tiene facturas en cartera: no puede cambiarse a tipo Cliente.';
        } else {
            $accion = 'nuevo';
            if ($nombre === '') $err[] = 'Falta la razón social.';
            $d += ['tipo' => 'cliente', 'activo' => 1];
        }
        if ($err) $accion = 'error';
        $items[] = ['fila' => $i + 1, 'accion' => $accion, 'id' => $exist['id'] ?? null, 'existente' => $exist['nombre'] ?? null, 'datos' => $d, 'errores' => $err, 'avisos' => $av,
            'original' => array_map(fn($v) => emp_texto($v), $f)];
    }
    return ['items' => $items, 'desconocidas' => $desconocidas, 'columnas' => array_map(fn($k) => emp_campos()[$k][0], array_keys($map)), 'encabezados' => array_map(fn($v) => emp_texto($v), $filas[$hIdx] ?? [])];
}

/** Ejecuta la importación ya validada. Devuelve los conteos [nuevo, actualizar, omitir, error]. */
function emp_ejecutar(array $analisis): array
{
    $pdo = db();
    $cnt = ['nuevo' => 0, 'actualizar' => 0, 'omitir' => 0, 'error' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($analisis['items'] as $it) {
            $cnt[$it['accion']]++;
            if ($it['accion'] === 'nuevo') insert('empresas', $it['datos'] + ['creado' => now()]);
            elseif ($it['accion'] === 'actualizar' && $it['datos']) update('empresas', $it['datos'], (int)$it['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    error_log('MAVAL: ' . (user()['email'] ?? '?') . " importó empresas: {$cnt['nuevo']} nuevas, {$cnt['actualizar']} actualizadas.");
    return $cnt;
}

/** Plantilla de Excel con listas desplegables, ejemplos e instrucciones. */
function emp_plantilla(): void
{
    require_once APP_DIR . '/lib/xlsx.php';
    $headers = array_map(fn($c) => $c[0], array_values(emp_campos()));
    xlsx_download('plantilla_empresas.xlsx', [
        ['name' => 'Empresas', 'headers' => $headers,
         'rows' => [['EJEMPLO Comercial Andina S.A.S.', '900123456-7', 'Cliente', 'Laura Gómez', '3001234567', 'laura@andina.com', 'Calle 10 # 5-20, Cali', 'Activa'],
                    ['EJEMPLO Mi Empresa Ltda.', '800987654-3', 'Propia', '', '3187654321', 'contabilidad@miempresa.com', '', 'Activa']],
         'widths' => [34, 16, 11, 22, 16, 30, 34, 11],
         'validations' => [['col' => 2, 'list' => ['Cliente', 'Propia']], ['col' => 7, 'list' => ['Activa', 'Inactiva']]]],
        ['name' => 'Instrucciones', 'headers' => ['Columna', 'Qué escribir'], 'rows' => [
            ['Razón social', 'Obligatoria para empresas nuevas.'], ['NIT', 'Opcional. Se usa para reconocer empresas que ya existen (si no hay NIT, se compara el nombre).'],
            ['Tipo', 'Cliente o Propia (las propias son las que emiten facturas en Cartera). Vacío = Cliente.'], ['Contacto / Teléfono / Correo / Dirección', 'Opcionales.'],
            ['Estado', 'Activa o Inactiva. Vacío = Activa.'], ['', ''],
            ['Importante', 'Las filas cuya razón social empieza por EJEMPLO se ignoran: bórrelas o reemplácelas.'],
            ['Actualizar', 'Si la empresa ya existe, se actualiza con las columnas que tengan valor; las celdas vacías no borran datos.'],
            ['Exportar y volver a importar', 'El Excel que descarga desde el listado también se puede editar y volver a importar.']], 'widths' => [38, 100]],
    ]);
}

/** Excel con las empresas del listado (mismas columnas que la plantilla). */
function emp_exportar(array $rows): void
{
    require_once APP_DIR . '/lib/xlsx.php';
    $headers = array_map(fn($c) => $c[0], array_values(emp_campos()));
    $out = array_map(fn($r) => [$r['nombre'], $r['nit'], $r['tipo'] === 'propia' ? 'Propia' : 'Cliente', $r['contacto'], $r['telefono'], $r['email'], $r['direccion'], $r['activo'] ? 'Activa' : 'Inactiva'], $rows);
    xlsx_download('empresas_' . date('Ymd_Hi') . '.xlsx', [['name' => 'Empresas', 'headers' => $headers, 'rows' => $out, 'widths' => [34, 16, 11, 22, 16, 30, 34, 11],
        'validations' => [['col' => 2, 'list' => ['Cliente', 'Propia']], ['col' => 7, 'list' => ['Activa', 'Inactiva']]]]]);
}
