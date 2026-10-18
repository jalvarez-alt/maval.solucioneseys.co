<?php
/**
 * Importación masiva de equipos desde Excel/CSV: plantillas, mapeo de columnas, validación y carga.
 */

/** Columnas por inventario: campo => [encabezado de la plantilla, alias aceptados, ayuda]. */
function imp_campos(string $inv): array
{
    $comunes = [
        'codigo' => ['Código', ['codigo', 'cod', 'placa', 'codigo placa', 'activo', 'id activo'], 'Opcional. Si lo deja vacío se genera (PC-0001, LAP-0001…). Si ya existe, el equipo se actualiza.'],
    ];
    if ($inv === 'computo') {
        return $comunes + [
            'responsable' => ['Usuario', ['usuario', 'nombre usuario', 'responsable', 'asignado a', 'nombre'], 'Obligatorio para equipos nuevos. Nombre de quien lo usa, o el área (p. ej. «Bodega»).'],
            'cargo' => ['Cargo', ['cargo'], ''],
            'usuario_sistema' => ['Usuario mesa de ayuda', ['usuario mesa de ayuda', 'usuario de la mesa de ayuda', 'usuario mesa ayuda', 'usuario sistema', 'usuario del sistema', 'correo mesa de ayuda', 'login'], 'Correo (o nombre exacto) de un usuario registrado en la aplicación: verá el equipo y podrá reportar tickets sobre él. Escriba «ninguno» para quitarlo.'],
            'correos' => ['Correos', ['correos', 'correo', 'email', 'emails', 'e mail', 'correo electronico'], 'Varios separados por coma o punto y coma.'],
            'empresa' => ['Propietario', ['propietario', 'empresa', 'cliente'], 'Nombre o NIT de la empresa registrada en la aplicación.'],
            'tipo' => ['Tipo', ['tipo', 'tipo equipo', 'tipo de equipo'], 'Obligatorio para nuevos: Servidor, Escritorio, Portátil o Todo en uno.'],
            'marca' => ['Marca', ['marca'], ''],
            'modelo' => ['Modelo', ['modelo'], ''],
            'serial' => ['Serial', ['serial', 'serie', 'numero de serie', 'no serie', 's n', 'sn'], 'Si el código está vacío, el serial se usa para encontrar equipos existentes.'],
            'procesador' => ['Procesador', ['procesador', 'cpu'], ''],
            'ram' => ['RAM', ['ram', 'memoria', 'memoria ram'], 'Ej.: 16 GB'],
            'disco' => ['DD', ['dd', 'disco', 'disco duro', 'almacenamiento', 'hdd', 'ssd'], 'Ej.: SSD 512 GB'],
            'so_nombre' => ['Sistema operativo', ['sistema operativo', 'so', 's o'], 'Ej.: Windows 11 Pro'],
            'so_lic' => ['Licencia S.O.', ['licencia s o', 'licencia so', 'licencia sistema operativo', 'so legal', 's o legal'], 'Legal, No legal o Libre. Obligatorio si escribe el sistema operativo.'],
            'of_nombre' => ['Ofimática', ['ofimatica', 'office', 'paquete ofimatico'], 'Ej.: Microsoft 365, LibreOffice'],
            'of_lic' => ['Licencia ofimática', ['licencia ofimatica', 'licencia office', 'office legal', 'ofimatica legal'], 'Legal, No legal o Libre.'],
            'av_nombre' => ['Antivirus', ['antivirus', 'av'], 'Ej.: ESET Endpoint Security'],
            'av_lic' => ['Licencia antivirus', ['licencia antivirus', 'antivirus legal', 'licencia av'], 'Legal, No legal o Libre.'],
            'av_vence' => ['Antivirus vence', ['antivirus vence', 'vence antivirus', 'vencimiento antivirus', 'fecha vencimiento antivirus'], 'Fecha (dd/mm/aaaa).'],
            'otros_sw' => ['Otro software', ['otro software', 'otros software', 'otros programas', 'software adicional', 'software'], 'Separe con «;». Formato: Nombre: Licencia. Ej.: Siigo: Legal; AnyDesk: No legal; Chrome: Libre'],
            'fecha_compra' => ['Fecha compra', ['fecha compra', 'fecha de compra', 'compra', 'fecha adquisicion'], 'Fecha (dd/mm/aaaa).'],
            'valor' => ['Valor compra', ['valor compra', 'valor de compra', 'valor', 'costo', 'precio'], 'Número, sin símbolo.'],
            'garantia_hasta' => ['Garantía hasta', ['garantia hasta', 'garantia', 'fin garantia', 'vence garantia'], 'Fecha (dd/mm/aaaa).'],
            'renovacion' => ['Renovación', ['renovacion', 'prioridad renovacion'], 'Bajo, Medio o Alto. Vacío: se sugiere según la fecha de compra.'],
            'estado' => ['Estado', ['estado'], 'Activo, En reparación, Bodega o Dado de baja. Vacío = Activo.'],
            'ubicacion' => ['Ubicación', ['ubicacion', 'area', 'sede', 'oficina'], ''],
            'ip' => ['IP', ['ip', 'direccion ip'], ''],
            'notas' => ['Observación', ['observacion', 'observaciones', 'notas', 'comentarios'], ''],
        ];
    }
    return $comunes + [
        'tipo' => ['Tipo', ['tipo', 'tipo equipo', 'tipo de equipo'], 'Obligatorio para nuevos: ' . implode(', ', OTROS_TIPOS) . '.'],
        'marca' => ['Marca', ['marca'], ''],
        'modelo' => ['Modelo', ['modelo'], ''],
        'serial' => ['Serial', ['serial', 'serie', 'numero de serie', 'no serie', 's n', 'sn'], ''],
        'empresa' => ['Propietario', ['propietario', 'empresa', 'cliente'], 'Nombre o NIT de la empresa registrada en la aplicación.'],
        'responsable' => ['Asignado a', ['asignado a', 'responsable', 'usuario', 'asignado'], ''],
        'usuario_sistema' => ['Usuario mesa de ayuda', ['usuario mesa de ayuda', 'usuario de la mesa de ayuda', 'usuario mesa ayuda', 'usuario sistema', 'usuario del sistema', 'correo mesa de ayuda', 'login'], 'Correo (o nombre exacto) de un usuario registrado en la aplicación: verá el equipo y podrá reportar tickets sobre él. Escriba «ninguno» para quitarlo.'],
        'ubicacion' => ['Ubicación', ['ubicacion', 'area', 'sede', 'oficina'], ''],
        'estado' => ['Estado', ['estado'], 'Activo, En reparación, Bodega o Dado de baja. Vacío = Activo.'],
        'imei' => ['IMEI', ['imei'], 'Celulares y tablets.'],
        'linea' => ['Línea', ['linea', 'numero', 'numero celular', 'telefono'], 'Celulares.'],
        'ip' => ['IP', ['ip', 'direccion ip'], ''],
        'fecha_compra' => ['Fecha compra', ['fecha compra', 'fecha de compra', 'compra'], 'Fecha (dd/mm/aaaa).'],
        'valor' => ['Valor compra', ['valor compra', 'valor de compra', 'valor', 'costo', 'precio'], 'Número, sin símbolo.'],
        'garantia_hasta' => ['Garantía hasta', ['garantia hasta', 'garantia', 'fin garantia'], 'Fecha (dd/mm/aaaa).'],
        'especificaciones' => ['Especificaciones', ['especificaciones', 'caracteristicas', 'detalle'], ''],
        'notas' => ['Notas', ['notas', 'observacion', 'observaciones'], ''],
    ];
}

function imp_norm($s): string
{
    $s = mb_strtolower(trim((string)$s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '*' => '']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Busca la fila de encabezados (en las primeras 10) y relaciona cada campo con su columna. */
function imp_mapear(string $inv, array $filas): array
{
    $campos = imp_campos($inv);
    $alias = [];
    foreach ($campos as $k => [$lbl, $als]) { $alias[imp_norm($lbl)] = $k; foreach ($als as $a) $alias[imp_norm($a)] ??= $k; }
    $mejor = [-1, [], []];
    foreach (array_slice($filas, 0, 10, true) as $i => $f) {
        $map = []; $desconocidas = [];
        foreach ($f as $c => $h) {
            $n = imp_norm($h);
            if ($n === '') continue;
            if (isset($alias[$n]) && !isset($map[$alias[$n]])) $map[$alias[$n]] = $c;
            else $desconocidas[] = (string)$h;
        }
        if (count($map) > count($mejor[1])) $mejor = [$i, $map, $desconocidas];
    }
    return $mejor; // [índice fila encabezado, mapa campo=>columna, encabezados no reconocidos]
}

function imp_fecha($v): array
{
    if ($v === '' || $v === null) return [null, false];
    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', $v))) {
        $n = (float)$v;
        if ($n > 10000 && $n < 80000) return [gmdate('Y-m-d', (int)round(($n - 25569) * 86400)), false];
        return [null, true];
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) $y += $y > 50 ? 1900 : 2000;
    } else return [null, true];
    return checkdate($mo, $d, $y) ? [sprintf('%04d-%02d-%02d', $y, $mo, $d), false] : [null, true];
}

function imp_lista($v, array $mapa): ?string
{
    $n = imp_norm($v);
    if ($n === '') return null;
    foreach ($mapa as $valor => $alias) if (in_array($n, $alias, true)) return $valor;
    return '?';
}
function imp_tipo($v): ?string
{
    return imp_lista($v, [
        'Servidor' => ['servidor', 'server'], 'Escritorio' => ['escritorio', 'computador de escritorio', 'pc', 'desktop', 'torre', 'cpu', 'computador'],
        'Portátil' => ['portatil', 'laptop', 'notebook', 'computador portatil'], 'Todo en uno' => ['todo en uno', 'aio', 'all in one', 'allinone'],
        'Monitor' => ['monitor', 'pantalla'], 'Celular' => ['celular', 'telefono celular', 'movil', 'smartphone'], 'Tablet' => ['tablet', 'tableta'],
        'Impresora' => ['impresora', 'multifuncional'], 'Escáner' => ['escaner', 'scanner'], 'Cámara' => ['camara', 'camara ip'], 'DVR / NVR' => ['dvr', 'nvr', 'dvr nvr', 'grabador'],
        'Router / Switch' => ['router', 'switch', 'router switch', 'firewall'], 'Access point' => ['access point', 'ap', 'punto de acceso'], 'UPS' => ['ups', 'regulador'],
        'Teléfono IP' => ['telefono ip', 'telefono', 'voip'], 'Otro' => ['otro', 'otros'],
    ]);
}
function imp_licencia($v): ?string
{
    return imp_lista($v, [
        'Legal' => ['legal', 'si', 'sí', 'original', 'licenciado', 'licenciada', 'con licencia', 'ok', 'x'],
        'No legal' => ['no legal', 'no', 'ilegal', 'pirata', 'sin licencia', 'no licenciado', 'crackeado'],
        'Libre' => ['libre', 'gratis', 'gratuito', 'gratuita', 'free', 'freeware', 'open source', 'codigo abierto'],
    ]);
}
function imp_renov($v): ?string { return imp_lista($v, ['Bajo' => ['bajo', 'baja', 'b'], 'Medio' => ['medio', 'media', 'm'], 'Alto' => ['alto', 'alta', 'a']]); }
function imp_estado($v): ?string
{
    return imp_lista($v, ['Activo' => ['activo', 'en uso', 'asignado'], 'En reparación' => ['en reparacion', 'reparacion', 'mantenimiento'],
        'Bodega' => ['bodega', 'disponible', 'almacenado'], 'Dado de baja' => ['dado de baja', 'baja', 'de baja', 'retirado']]);
}
/** Busca un usuario activo por correo o por nombre exacto. Devuelve la fila, null o 'ambiguo'. */
function imp_usuario(string $v, ?array &$cache)
{
    if ($cache === null) {
        $cache = ['mail' => [], 'nom' => []];
        foreach (all('SELECT id, nombre, email, empresa_id FROM usuarios WHERE activo = 1') as $u) {
            $cache['mail'][mb_strtolower($u['email'])] = $u;
            $cache['nom'][imp_norm($u['nombre'])][] = $u;
        }
    }
    $m = mb_strtolower(trim($v));
    if (isset($cache['mail'][$m])) return $cache['mail'][$m];
    $c = $cache['nom'][imp_norm($v)] ?? [];
    return count($c) === 1 ? $c[0] : (count($c) > 1 ? 'ambiguo' : null);
}

function imp_empresa($v, array &$cache): array
{
    $n = imp_norm($v);
    if ($n === '') return [null, null];
    if (!isset($cache['_all'])) {
        $cache['_all'] = [];
        foreach (all('SELECT id, nombre, nit FROM empresas') as $e) {
            $cache['_all'][imp_norm($e['nombre'])] = (int)$e['id'];
            if ($e['nit']) $cache['_all']['nit:' . preg_replace('/\D/', '', explode('-', $e['nit'])[0])] = (int)$e['id'];
        }
    }
    $dig = preg_replace('/\D/', '', explode('-', (string)$v)[0]);
    $id = $cache['_all'][$n] ?? (strlen($dig) >= 6 ? ($cache['_all']['nit:' . $dig] ?? null) : null);
    return [$id, trim((string)$v)];
}

/** Valida todas las filas y decide qué se hará con cada una. */
function imp_analizar(string $inv, array $filas, array $opts): array
{
    [$hIdx, $map, $desconocidas] = imp_mapear($inv, $filas);
    if (count($map) < 2) throw new RuntimeException('No se reconocieron los encabezados. Use la plantilla o asegúrese de que la primera fila tenga los nombres de las columnas (Usuario, Tipo, Marca…).');
    $campos = imp_campos($inv);
    $items = [];
    $vistos = ['codigo' => [], 'serial' => []];
    $cacheEmp = [];
    $cacheUsr = null;
    $empNuevas = [];
    $tiposOk = $inv === 'computo' ? COMPUTO_TIPOS : OTROS_TIPOS;

    foreach ($filas as $i => $f) {
        if ($i <= $hIdx) continue;
        $g = fn($k) => isset($map[$k]) ? ($f[$map[$k]] ?? '') : '';
        $gs = fn($k) => trim((string)$g($k));
        if (implode('', array_map(fn($v) => trim((string)$v), $f)) === '') continue;
        $codigo = mb_strtoupper($gs('codigo'));
        if (str_starts_with($codigo, 'EJEMPLO')) continue;
        if (count($items) >= 3000) throw new RuntimeException('El archivo supera el máximo de 3.000 equipos por importación. Divídalo en varios archivos.');

        $err = []; $av = [];
        $d = [];
        foreach (['responsable', 'cargo', 'marca', 'modelo', 'serial', 'procesador', 'ram', 'disco', 'ubicacion', 'ip', 'notas', 'imei', 'linea', 'especificaciones'] as $k) {
            if (isset($campos[$k]) && $gs($k) !== '') $d[$k] = mb_substr($gs($k), 0, $k === 'notas' || $k === 'especificaciones' ? 5000 : 120);
        }
        if (isset($d['serial'])) $d['serial'] = mb_strtoupper($d['serial']);
        if ($codigo !== '') $d['codigo'] = mb_substr($codigo, 0, 40);

        // Tipo
        if ($gs('tipo') !== '') {
            $t = imp_tipo($gs('tipo'));
            if ($t === '?' ) $err[] = 'Tipo «' . $gs('tipo') . '» no reconocido.';
            elseif (!in_array($t, $tiposOk, true)) $err[] = 'El tipo «' . $t . '» pertenece a ' . ($inv === 'computo' ? 'Otros equipos' : 'Inventario de cómputo') . '; use la plantilla correspondiente.';
            else $d['tipo'] = $t;
        }
        // Correos
        if (isset($campos['correos']) && $gs('correos') !== '') {
            $ok = [];
            foreach (preg_split('/[,;\s]+/', $gs('correos')) as $c) {
                if ($c === '') continue;
                if (filter_var($c, FILTER_VALIDATE_EMAIL)) $ok[] = mb_strtolower($c); else $av[] = 'Correo «' . $c . '» no válido: se omitió.';
            }
            if ($ok) $d['correos'] = mb_substr(implode(', ', array_unique($ok)), 0, 500);
        }
        // Usuario de la mesa de ayuda (usuario registrado en la aplicación)
        $usrSis = null;
        if ($gs('usuario_sistema') !== '') {
            $vu = $gs('usuario_sistema');
            if (in_array(imp_norm($vu), ['ninguno', 'ninguna', 'sin usuario', 'n a', 'na'], true)) $d['usuario_id'] = null;
            else {
                $usrSis = imp_usuario($vu, $cacheUsr);
                if ($usrSis === 'ambiguo') { $err[] = 'Hay varios usuarios llamados «' . $vu . '»: use su correo.'; $usrSis = null; }
                elseif (!$usrSis) $err[] = 'Usuario de mesa de ayuda «' . $vu . '» no existe o está inactivo (créelo en Usuarios o use su correo exacto).';
                else $d['usuario_id'] = $usrSis['id'];
            }
        }
        // Propietario
        if ($gs('empresa') !== '') {
            [$eid, $enom] = imp_empresa($gs('empresa'), $cacheEmp);
            if ($eid) $d['empresa_id'] = $eid;
            elseif (!empty($opts['crear_empresas'])) { $d['_empresa_nueva'] = $enom; $empNuevas[imp_norm($enom)] = $enom; $av[] = 'Se creará la empresa «' . $enom . '».'; }
            else $err[] = 'Propietario «' . $enom . '» no existe en Empresas (o active «Crear empresas que no existan»).';
        }
        // Fechas
        foreach (['fecha_compra' => 'Fecha de compra', 'garantia_hasta' => 'Garantía hasta'] as $k => $lbl) {
            if (!isset($map[$k]) || $gs($k) === '') continue;
            [$fd, $bad] = imp_fecha($g($k));
            if ($bad) $err[] = $lbl . ' «' . $gs($k) . '» no es una fecha válida (use dd/mm/aaaa).'; else $d[$k] = $fd;
        }
        if ($gs('valor') !== '') { $v = $g('valor'); $d['valor'] = is_int($v) || is_float($v) ? (float)$v : parse_money($v); }
        if ($gs('estado') !== '') { $e = imp_estado($gs('estado')); if ($e === '?') $err[] = 'Estado «' . $gs('estado') . '» no reconocido.'; else $d['estado'] = $e; }
        if (isset($campos['renovacion']) && $gs('renovacion') !== '') { $r = imp_renov($gs('renovacion')); if ($r === '?') $err[] = 'Renovación «' . $gs('renovacion') . '» no reconocida (Bajo, Medio o Alto).'; else $d['renovacion'] = $r; }

        // Software (solo cómputo). null = el archivo no trae esa categoría (se conserva lo existente al actualizar).
        $soft = [];
        if ($inv === 'computo') {
            foreach (['so' => ['so', 'sistema operativo'], 'ofimatica' => ['of', 'ofimática'], 'antivirus' => ['av', 'antivirus']] as $cat => [$p, $lbl]) {
                $nom = $gs($p . '_nombre'); $licRaw = $gs($p . '_lic');
                if (in_array(imp_norm($licRaw), ['sin registrar', 'n a', 'na'], true)) $licRaw = '';
                if ($nom === '' && $licRaw === '') continue;
                if ($nom === '') { $err[] = 'Indicó la licencia de ' . $lbl . ' pero no el producto.'; continue; }
                $lic = imp_licencia($licRaw);
                if ($lic === null) { $err[] = 'Falta indicar si la licencia de ' . $lbl . ' («' . $nom . '») es Legal, No legal o Libre.'; continue; }
                if ($lic === '?') { $err[] = 'Licencia de ' . $lbl . ' «' . $licRaw . '» no reconocida (Legal, No legal o Libre).'; continue; }
                $vence = null;
                if ($cat === 'antivirus' && $gs('av_vence') !== '') { [$vence, $bad] = imp_fecha($g('av_vence')); if ($bad) $err[] = 'Vencimiento del antivirus no es una fecha válida.'; }
                $soft[$cat] = [['nombre' => mb_substr($nom, 0, 150), 'licencia' => $lic, 'vence' => $vence]];
            }
            if ($gs('otros_sw') !== '') {
                $lista = [];
                foreach (preg_split('/[;\n|]+/', $gs('otros_sw')) as $pz) {
                    $pz = trim($pz);
                    if ($pz === '') continue;
                    if (preg_match('/^(.+?)\s*[:=]\s*([^:=]+)$/u', $pz, $m) || preg_match('/^(.+?)\s*\(([^()]+)\)$/u', $pz, $m)) {
                        $lic = imp_licencia($m[2]);
                        if ($lic === null || $lic === '?') { $err[] = 'Otro software «' . $pz . '»: estado de licencia no reconocido.'; continue; }
                        $lista[] = ['nombre' => mb_substr(trim($m[1]), 0, 150), 'licencia' => $lic, 'vence' => null];
                    } else $err[] = 'Otro software «' . $pz . '»: falta el estado. Escriba «' . $pz . ': Legal», «: No legal» o «: Libre».';
                }
                $soft['otro'] = $lista;
            }
        }

        // ¿Existe?
        $exist = null;
        if ($codigo !== '') $exist = one('SELECT id, codigo, tipo FROM equipos WHERE codigo = ?', [$codigo]);
        if (!$exist && !empty($d['serial'])) $exist = one('SELECT id, codigo, tipo FROM equipos WHERE UPPER(serial) = ?', [$d['serial']]);
        if ($exist && es_computo($exist['tipo']) !== ($inv === 'computo')) { $err[] = 'El código ' . $exist['codigo'] . ' ya existe en ' . (es_computo($exist['tipo']) ? 'Inventario de cómputo' : 'Otros equipos') . '.'; $exist = null; }

        // Duplicados dentro del archivo
        foreach (['codigo' => $codigo, 'serial' => $d['serial'] ?? ''] as $k => $val) {
            if ($val === '') continue;
            if (isset($vistos[$k][$val])) $err[] = ucfirst($k) . ' ' . $val . ' repetido en el archivo (fila ' . $vistos[$k][$val] . ').';
            else $vistos[$k][$val] = $i + 1;
        }

        if ($exist) {
            $accion = ($opts['duplicados'] ?? 'actualizar') === 'omitir' ? 'omitir' : 'actualizar';
            if ($accion === 'omitir') $av[] = 'Ya existe (' . $exist['codigo'] . '): se omite.';
        } else {
            $accion = 'nuevo';
            if (empty($d['tipo']) && !array_filter($err, fn($e) => str_starts_with($e, 'Tipo') || str_starts_with($e, 'El tipo'))) $err[] = 'Falta el tipo de equipo.';
            if ($usrSis && empty($d['responsable']) && $inv === 'computo') $d['responsable'] = $usrSis['nombre'];
            if ($usrSis && empty($d['empresa_id']) && empty($d['_empresa_nueva']) && $usrSis['empresa_id']) $d['empresa_id'] = (int)$usrSis['empresa_id'];
            if ($inv === 'computo' && empty($d['responsable'])) $err[] = 'Falta el usuario del equipo (columna Usuario o Usuario mesa de ayuda).';
            if ($inv === 'computo' && empty($d['renovacion']) && !empty($d['fecha_compra'])) { $d['renovacion'] = renovacion_sugerida($d['fecha_compra']); $av[] = 'Renovación sugerida por antigüedad: ' . $d['renovacion'] . '.'; }
            if ($codigo !== '' && $codigo !== $d['codigo']) $err[] = 'Código demasiado largo.';
        }
        if ($err) $accion = 'error';

        $items[] = [
            'fila' => $i + 1, 'accion' => $accion, 'id' => $exist['id'] ?? null, 'codigo' => $exist['codigo'] ?? ($codigo ?: '(automático)'),
            'titulo' => $inv === 'computo' ? ($d['responsable'] ?? '') : trim(($d['tipo'] ?? '') . ' ' . ($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? '')),
            'datos' => $d, 'soft' => $soft, 'errores' => $err, 'avisos' => $av, 'original' => array_map(fn($v) => (string)$v, $f),
        ];
    }
    return ['items' => $items, 'desconocidas' => $desconocidas, 'columnas' => array_map(fn($k) => $campos[$k][0], array_keys($map)), 'empresas_nuevas' => array_values($empNuevas), 'encabezados' => array_map(fn($v) => (string)$v, $filas[$hIdx] ?? [])];
}

/** Ejecuta la importación ya validada. Devuelve [creados, actualizados, omitidos, con error]. */
function imp_ejecutar(string $inv, array $analisis, string $archivo): array
{
    $pdo = db();
    $uid = (int)user()['id'];
    $cnt = ['nuevo' => 0, 'actualizar' => 0, 'omitir' => 0, 'error' => 0];
    $empCreadas = [];
    $pdo->beginTransaction();
    try {
        foreach ($analisis['items'] as $it) {
            $cnt[$it['accion']]++;
            if (!in_array($it['accion'], ['nuevo', 'actualizar'], true)) continue;
            $d = $it['datos'];
            if (isset($d['_empresa_nueva'])) {
                $k = imp_norm($d['_empresa_nueva']);
                $empCreadas[$k] ??= insert('empresas', ['nombre' => mb_substr($d['_empresa_nueva'], 0, 150), 'tipo' => 'cliente', 'activo' => 1, 'creado' => now()]);
                $d['empresa_id'] = $empCreadas[$k];
                unset($d['_empresa_nueva']);
            }
            if ($it['accion'] === 'nuevo') {
                if (empty($d['codigo'])) $d['codigo'] = siguiente_codigo($d['tipo']);
                $d += ['estado' => 'Activo'];
                $d['creado'] = now();
                $id = insert('equipos', $d);
                insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => mb_substr('Registrado por importación masiva (' . $archivo . ', fila ' . $it['fila'] . ').', 0, 500), 'fecha' => now()]);
            } else {
                $id = (int)$it['id'];
                unset($d['codigo']);
                if ($d) { $d['actualizado'] = now(); update('equipos', $d, $id); }
                insert('equipo_movimientos', ['equipo_id' => $id, 'usuario_id' => $uid, 'descripcion' => mb_substr('Actualizado por importación masiva (' . $archivo . ', fila ' . $it['fila'] . ').', 0, 500), 'fecha' => now()]);
            }
            foreach ($it['soft'] as $cat => $lista) {
                q('DELETE FROM equipo_software WHERE equipo_id = ? AND categoria = ?', [$id, $cat]);
                foreach ($lista as $s) insert('equipo_software', ['equipo_id' => $id, 'categoria' => $cat, 'nombre' => $s['nombre'], 'licencia' => $s['licencia'], 'vence' => $s['vence']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $cnt;
}

/** Plantilla .xlsx con listas desplegables, dos filas de ejemplo y una hoja de instrucciones. */
function imp_plantilla(string $inv): void
{
    require_once APP_DIR . '/lib/xlsx.php';
    $campos = imp_campos($inv);
    $keys = array_keys($campos);
    $headers = array_values(array_map(fn($c) => $c[0], $campos));
    $pos = array_flip($keys);
    $lic = ['Legal', 'No legal', 'Libre'];
    $val = [['col' => $pos['tipo'], 'list' => $inv === 'computo' ? COMPUTO_TIPOS : OTROS_TIPOS], ['col' => $pos['estado'], 'list' => EQUIPO_ESTADOS]];
    if ($inv === 'computo') {
        foreach (['so_lic', 'of_lic', 'av_lic'] as $k) $val[] = ['col' => $pos[$k], 'list' => $lic];
        $val[] = ['col' => $pos['renovacion'], 'list' => RENOVACION];
        $ej = [
            ['EJEMPLO-1', 'Carlos Méndez', 'Contador', 'cmendez@empresa.com', 'cmendez@empresa.com', 'Nombre o NIT de la empresa', 'Escritorio', 'HP', 'ProDesk 400 G4', 'MXL82012', 'Intel Core i5-7500', '8 GB', 'HDD 1 TB',
                'Windows 10 Pro', 'Legal', 'Office 2016', 'No legal', 'ESET Endpoint Security', 'Legal', '2027-06-30', 'Siigo: Legal; AnyDesk: No legal; Chrome: Libre', '2018-03-14', 2500000, '', 'Alto', 'Activo', 'Contabilidad', '192.168.1.20', 'Disco lento'],
            ['EJEMPLO-2', 'Paola Rincón', 'Recepción', 'ninguno', 'recepcion@empresa.com', 'Nombre o NIT de la empresa', 'Portátil', 'Lenovo', 'ThinkPad E14', 'PF3XYZ', 'Intel Core i5-1235U', '16 GB', 'SSD 512 GB',
                'Windows 11 Pro', 'Legal', 'Microsoft 365', 'Legal', 'Microsoft Defender', 'Libre', '', '', '2025-01-15', 3450000, '2028-01-15', '', 'Activo', 'Recepción', '', ''],
        ];
        $widths = [12, 24, 18, 26, 28, 24, 14, 12, 18, 14, 22, 9, 13, 20, 13, 20, 13, 22, 13, 14, 40, 13, 13, 14, 12, 14, 16, 14, 30];
    } else {
        $ej = [
            ['EJEMPLO-1', 'Impresora', 'Epson', 'L3250', 'X5ABC123', 'Nombre o NIT de la empresa', 'Contabilidad', '', 'Piso 2', 'Activo', '', '', '192.168.1.50', '2024-05-10', 890000, '2025-05-10', 'Tinta continua', ''],
            ['EJEMPLO-2', 'Celular', 'Samsung', 'Galaxy A54', 'R58T90', 'Nombre o NIT de la empresa', 'Andrés Torres', 'atorres@empresa.com', '', 'Activo', '351234567890123', '3001234567', '', '2025-02-01', 1500000, '', '128 GB', 'Plan corporativo'],
        ];
        $widths = [12, 16, 12, 16, 14, 24, 20, 26, 16, 14, 18, 14, 14, 13, 13, 14, 24, 24];
    }
    $instr = [];
    foreach ($campos as $k => [$lbl, $als, $ayuda]) $instr[] = [$lbl, $ayuda, implode(', ', array_slice($als, 0, 4))];
    $instr[] = ['', '', ''];
    $instr[] = ['Importante', 'Las filas cuyo código empieza por EJEMPLO se ignoran: bórrelas o reemplácelas con sus equipos.', ''];
    $instr[] = ['Actualizar', 'Si el código (o el serial) ya existe, el equipo se actualiza con las columnas que tengan valor; las celdas vacías no borran datos.', ''];
    $instr[] = ['Exportar y volver a importar', 'El Excel que descarga desde el listado también se puede editar y volver a importar.', ''];
    xlsx_download(($inv === 'computo' ? 'plantilla_inventario_computo' : 'plantilla_otros_equipos') . '.xlsx', [
        ['name' => 'Equipos', 'headers' => $headers, 'rows' => $ej, 'widths' => $widths, 'validations' => $val],
        ['name' => 'Instrucciones', 'headers' => ['Columna', 'Qué escribir', 'También se reconoce como'], 'rows' => $instr, 'widths' => [22, 90, 40]],
    ]);
}
