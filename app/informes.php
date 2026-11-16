<?php
/**
 * Informes de servicio: acta que se entrega al cliente al terminar una instalación (cámaras, conectividad y cableado estructurado, software).
 * Cada tipo define sus tablas y pruebas típicas; el PDF usa la identidad visual de las cotizaciones.
 */
require_once APP_DIR . '/cotizaciones.php';

const INF_ESTADOS = ['Borrador', 'Finalizado'];
const INF_RESULTADOS = ['OK', 'Con observación', 'Falla', 'No aplica'];

/** Tipos de servicio: nombre, icono, columnas de la tabla de equipos, datos técnicos propios y pruebas sugeridas. */
const INF_TIPOS = [
    'camaras' => [
        'nombre' => 'Instalación de cámaras de seguridad', 'corto' => 'Cámaras', 'icono' => 'camera-video',
        'equipos' => 'Cámaras instaladas',
        'cols' => ['Cámara', 'Ubicación', 'Modelo / serial', 'IP', 'Resolución', 'Estado'], 'anchos' => [58, 100, 120, 80, 62, 62],
        'estados' => ['Operativa', 'Con observación', 'Pendiente'],
        'datos' => [
            'nvr' => ['Grabador (NVR / DVR): modelo y serial', 'text'], 'nvr_ip' => ['IP del grabador', 'text'], 'disco' => ['Disco duro / capacidad', 'text'],
            'dias' => ['Días de grabación estimados', 'text'], 'acceso' => ['Acceso remoto (aplicación y usuarios creados; sin contraseñas)', 'area'],
        ],
        'pruebas' => ['Visión nocturna', 'Grabación continua', 'Detección de movimiento', 'Acceso remoto desde el celular', 'Fecha y hora sincronizadas'],
    ],
    'wifi' => [
        'nombre' => 'Conectividad y cableado estructurado', 'corto' => 'Conectividad', 'icono' => 'wifi',
        'equipos' => 'Equipos y puntos instalados',
        'cols' => ['Equipo / punto', 'Ubicación', 'Modelo / serial', 'Canal / cant.', 'Red (SSID) / puerto', 'Estado'], 'anchos' => [64, 100, 120, 44, 126, 28],
        'estados' => ['Operativo', 'Con observación', 'Pendiente'],
        'datos' => [
            'router' => ['Router / firewall: modelo e IP de gestión', 'text'], 'switch' => ['Switch / puertos PoE usados', 'text'],
            'internet' => ['Enlace de internet (proveedor, tipo, velocidad contratada e IP)', 'text'],
            'rack' => ['Rack / gabinete (tamaño, ubicación, patch panel, organización y rotulación del cableado)', 'area'],
            'ups' => ['UPS (marca, capacidad en VA, equipos que respalda y autonomía)', 'area'],
            'multitomas' => ['Multitomas / tomas eléctricas (cantidad, ubicación, polo a tierra)', 'area'],
            'redes' => ['Redes creadas (nombre, VLAN, seguridad y uso; sin contraseñas)', 'area'], 'cobertura' => ['Pruebas de cobertura (zona, señal en dBm y velocidad en Mbps)', 'area'],
        ],
        'pruebas' => ['Cobertura Wi-Fi en todas las zonas', 'Velocidad de internet medida', 'Cambio entre puntos de acceso (roaming)', 'Red de invitados aislada de la red interna', 'Acceso a internet desde varios equipos', 'Certificación / prueba del cableado', 'Rack ordenado y cableado rotulado', 'UPS respalda los equipos al cortar la energía', 'Tomas eléctricas con polo a tierra'],
    ],
    'software' => [
        'nombre' => 'Instalación de software', 'corto' => 'Software', 'icono' => 'pc-display',
        'equipos' => 'Equipos y software instalado',
        'cols' => ['Equipo', 'Usuario', 'Software', 'Versión', 'Licencia', 'Estado'], 'anchos' => [58, 100, 120, 62, 100, 40],
        'estados' => ['Instalado', 'Con observación', 'Pendiente'],
        'datos' => [
            'configuracion' => ['Configuración realizada (usuarios, respaldos, actualizaciones)', 'area'], 'capacitacion' => ['Capacitación: a quién se le dio y duración', 'text'],
            'respaldo' => ['Copia de seguridad: dónde queda y cada cuánto', 'text'],
        ],
        'pruebas' => ['Abre y guarda archivos', 'Licencia activada', 'Impresión', 'Actualizaciones al día', 'Copia de seguridad probada'],
    ],
];

function inf_siguiente(): int { return max(1, (int)val('SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM informes')); }
function inf_codigo(array $i): string { return 'IS-' . str_pad((string)$i['consecutivo'], 4, '0', STR_PAD_LEFT); }
function inf_archivo(array $i): string { return 'Informe_' . inf_codigo($i) . '.pdf'; }

function inf_obtener(int $id): ?array
{
    return one('SELECT i.*, cr.nombre AS creador, e.nombre AS empresa FROM informes i LEFT JOIN usuarios cr ON cr.id = i.creado_por LEFT JOIN empresas e ON e.id = i.empresa_id WHERE i.id = ?', [$id]);
}
/** Filas del informe agrupadas por sección: equipos, materiales y pruebas. */
function inf_filas(int $id): array
{
    $g = ['equipos' => [], 'materiales' => [], 'pruebas' => []];
    foreach (all('SELECT * FROM informe_filas WHERE informe_id = ? ORDER BY orden, id', [$id]) as $f) $g[$f['seccion']][] = $f;
    return $g;
}
function inf_datos(array $i): array { $d = json_decode((string)($i['datos'] ?? ''), true); return is_array($d) ? $d : []; }
/** Fotos del informe que se pueden incluir en el PDF (JPG o PNG). */
function inf_fotos(int $id): array
{
    return array_values(array_filter(adjuntos('informe', $id), fn($a) => in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)));
}
/** Si el servidor tiene GD, reduce las fotos grandes para que el PDF no pese demasiado. */
function inf_foto_datos(string $ruta): ?string
{
    $d = @file_get_contents($ruta);
    if ($d === false) return null;
    if (strlen($d) > 400000 && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($d);
        if ($im) {
            $w = imagesx($im); $h = imagesy($im); $max = 1400;
            if ($w > $max || $h > $max) {
                $r = min($max / $w, $max / $h); $n = imagecreatetruecolor((int)($w * $r), (int)($h * $r));
                imagefill($n, 0, 0, imagecolorallocate($n, 255, 255, 255));
                imagecopyresampled($n, $im, 0, 0, 0, 0, (int)($w * $r), (int)($h * $r), $w, $h); imagedestroy($im); $im = $n;
            }
            ob_start(); imagejpeg($im, null, 80); $o = ob_get_clean(); imagedestroy($im);
            if ($o) return $o;
        }
    }
    return $d;
}

/** Normaliza el envío del formulario: filas con al menos un dato en las columnas indicadas. */
function inf_filas_post(string $campo, int $cols): array
{
    $out = [];
    foreach ((array)($_POST[$campo] ?? []) as $f) {
        if (!is_array($f)) continue;
        $fila = [];
        for ($k = 1; $k <= $cols; $k++) $fila[$k] = mb_substr(trim((string)($f[$k] ?? '')), 0, 250);
        if (implode('', $fila) !== '') $out[] = $fila;
    }
    return $out;
}

// =====================================================================
// PDF
// =====================================================================
function inf_pdf(array $i, array $filas): SimplePdf
{
    $em = cot_emisor();
    $t = INF_TIPOS[$i['tipo']] ?? INF_TIPOS['software'];
    $datos = inf_datos($i);
    $pdf = new SimplePdf(false);
    $L = 40.0; $W = 532.0; $limite = 706.0; $y = 0.0;
    $pie = function () use ($pdf, $i, $L, $W, $em) {
        $pdf->text($L, 721, 'Informe de servicio ' . inf_codigo($i), 8, false, COT_GRIS);
        $pdf->text($L, 721, 'Página ' . $pdf->pageNo() . ' de {nb}', 8, false, COT_GRIS, 'R', $W);
        cot_pie_contacto($pdf, $em);
    };
    $nueva = function () use ($pdf, $pie, &$y) { if ($pdf->pageNo() > 0) $pie(); $pdf->addPage(); $y = 40.0; };
    $asegurar = function (float $h) use (&$y, $limite, $nueva) { if ($y + $h > $limite) $nueva(); };
    $parrafo = function (string $txt, float $size = 10) use ($pdf, &$y, $asegurar, $L, $W) {
        foreach (preg_split('/\R/u', trim($txt)) as $para) {
            $para = rtrim($para);
            if ($para === '') { $y += 6; continue; }
            $lista = (bool)preg_match('/^\s*[-•*]\s+/u', $para);
            $x = $L; $ancho = $W;
            if ($lista) { $para = preg_replace('/^\s*[-•*]\s+/u', '', $para); $x += 14; $ancho -= 14; }
            $lineas = $pdf->wrap($para, $ancho, $size);
            foreach ($lineas as $k => $ln) {
                $asegurar(14);
                if ($lista && $k === 0) $pdf->text($L + 3, $y, "\u{2022}", $size, true, COT_CORAL);
                if ($k < count($lineas) - 1) $pdf->textJ($x, $y, $ln, $size, false, COT_TEXTO, $ancho); else $pdf->text($x, $y, $ln, $size, false, COT_TEXTO);
                $y += 14;
            }
        }
    };
    $titulo = function (string $s) use ($pdf, &$y, $asegurar, $L) {
        $asegurar(40);
        $pdf->rect($L, $y - 8, 7, 7, COT_CORAL);
        $pdf->text($L + 14, $y, $s, 10.5, true, COT_NAVY);
        $y += 17;
    };
    /** Tabla: $cab = encabezados, $filas = filas de texto, $anchos = anchos (se ajustan a $W). */
    $tabla = function (array $cab, array $rows, array $anchos) use ($pdf, &$y, $asegurar, $L, $W) {
        $s = array_sum($anchos); $anchos = array_map(fn($a) => $a * $W / $s, $anchos);
        $dibujaCab = function () use ($pdf, &$y, $cab, $anchos, $L, $W) {
            $pdf->rect($L, $y, $W, 18, COT_NAVY);
            $x = $L; foreach ($cab as $k => $h) { $pdf->text($x + 4, $y + 12.5, $pdf->fit($h, $anchos[$k] - 8, 8.5, true), 8.5, true, '#ffffff'); $x += $anchos[$k]; }
            $y += 18;
        };
        $asegurar(18 + 24); $dibujaCab();
        foreach ($rows as $n => $fila) {
            $cel = []; $lin = 1;
            foreach ($cab as $k => $_) { $cel[$k] = $pdf->wrap((string)($fila[$k] ?? ''), $anchos[$k] - 8, 8.5); $lin = max($lin, count($cel[$k])); }
            $h = $lin * 11 + 7;
            if ($y + $h > 706) { $asegurar(1000); $dibujaCab(); }
            $pdf->rect($L, $y, $W, $h, $n % 2 ? '#ffffff' : COT_TINTE, '', 0);
            $x = $L;
            foreach ($cab as $k => $_) {
                foreach ($cel[$k] as $j => $ln) $pdf->text($x + 4, $y + 11 + $j * 11, $ln, 8.5, false, COT_TEXTO);
                $x += $anchos[$k];
            }
            $pdf->line($L, $y + $h, $L + $W, $y + $h, COT_LINEA, 0.5);
            $y += $h;
        }
        $y += 20;
    };

    // Encabezado: banner, acento, fecha y código del informe
    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 24.0;
    if ($banner) { $bw = 300.0; $bh = $bw * $banner['h'] / $banner['w']; $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh); $y += $bh + 8; }
    else { $pdf->text($L, $y + 20, (string)$em['nombre'], 20, true, COT_NAVY); $y += 40; }
    cot_acento($pdf, $L, $y, $W);
    $y += 24;
    $pdf->text($L, $y, $em['ciudad'] . ', ' . fecha_larga($i['fecha_fin'] ?: $i['fecha_inicio']), 10.5, false, COT_GRIS);
    $pdf->rect($L + $W - 170, $y - 14, 170, 20, COT_NAVY);
    $pdf->text($L + $W - 170, $y, 'Informe de servicio ' . inf_codigo($i), 10.5, true, '#ffffff', 'C', 170);
    $y += 28;
    $pdf->text($L, $y, mb_strtoupper($t['nombre']), 13, true, COT_NAVY); $y += 8;
    $y += 14;

    // Ficha del cliente y del servicio (dos columnas)
    $ficha = [['Cliente', $i['cliente_nombre']], ['NIT / C.C.', $i['cliente_nit']], ['Contacto', $i['contacto']], ['Sede / dirección', $i['sede']],
        ['Técnico', $i['tecnico_nombre']], ['Referencia', $i['referencia']],
        ['Inicio', $i['fecha_inicio'] ? fdate($i['fecha_inicio']) : ''], ['Finalización', $i['fecha_fin'] ? fdate($i['fecha_fin']) : '']];
    $hf = 4 * 16 + 12;
    $asegurar($hf + 10);
    $pdf->rect($L, $y - 12, $W, $hf, COT_TINTE, COT_LINEA, 0.8); $pdf->rect($L, $y - 12, 4, $hf, COT_NAVY);
    foreach ($ficha as $k => [$lbl, $v]) {
        $col = $k % 2; $fila = intdiv($k, 2);
        $x = $L + 14 + $col * ($W / 2);
        $pdf->text($x, $y + 4 + $fila * 16, $lbl . ':', 9, true, COT_NAVY);
        $pdf->text($x + 88, $y + 4 + $fila * 16, $pdf->fit((string)($v ?: '—'), $W / 2 - 110, 9), 9, false, COT_TEXTO);
    }
    $y += $hf + 14;

    $n = 0;
    if (trim((string)$i['resumen']) !== '') { $titulo(++$n . '. RESUMEN DEL TRABAJO'); $parrafo((string)$i['resumen']); $y += 8; }
    if (trim((string)$i['actividades']) !== '') { $titulo(++$n . '. ACTIVIDADES REALIZADAS'); $parrafo((string)$i['actividades']); $y += 8; }
    if ($filas['equipos']) {
        $titulo(++$n . '. ' . mb_strtoupper($t['equipos']));
        $tabla($t['cols'], array_map(fn($f) => [$f['c1'], $f['c2'], $f['c3'], $f['c4'], $f['c5'], $f['c6']], $filas['equipos']), $t['anchos']);
    }
    if ($filas['materiales']) {
        $titulo(++$n . '. MATERIALES Y EQUIPOS ENTREGADOS');
        $tabla(['Ítem', 'Marca', 'Serial / referencia', 'Cant.'], array_map(fn($f) => [$f['c1'], $f['c2'], $f['c3'], $f['c4']], $filas['materiales']), [220, 100, 160, 52]);
    }
    $hayDatos = array_filter(array_intersect_key($datos, $t['datos']), fn($v) => trim((string)$v) !== '');
    if ($hayDatos) {
        $titulo(++$n . '. DATOS TÉCNICOS');
        foreach ($t['datos'] as $k => [$lbl, $tipo]) {
            if (trim((string)($datos[$k] ?? '')) === '') continue;
            $asegurar(30);
            $pdf->text($L, $y, $lbl, 9, true, COT_NAVY); $y += 13;
            $parrafo((string)$datos[$k], 9.5); $y += 3;
        }
        $y += 5;
    }
    if ($filas['pruebas']) {
        $titulo(++$n . '. PRUEBAS Y RESULTADOS');
        $tabla(['Prueba', 'Resultado', 'Observación'], array_map(fn($f) => [$f['c1'], $f['c2'] ?: '—', $f['c3']], $filas['pruebas']), [230, 80, 222]);
    }
    if (trim((string)$i['recomendaciones']) !== '') { $titulo(++$n . '. RECOMENDACIONES Y PENDIENTES'); $parrafo((string)$i['recomendaciones']); $y += 8; }

    // Evidencia fotográfica: dos por fila
    $fotos = inf_fotos((int)$i['id']);
    if ($fotos) {
        $titulo(++$n . '. EVIDENCIA FOTOGRÁFICA');
        $cw = ($W - 14) / 2; $col = 0; $altoFila = 0.0; $yFila = $y;
        foreach ($fotos as $a) {
            $datosImg = inf_foto_datos(UPLOAD_DIR . '/' . $a['archivo']);
            $img = $datosImg ? $pdf->addImage($datosImg) : null;
            if (!$img) continue;
            $ih = min(190.0, $cw * $img['h'] / $img['w']); $iw = $ih * $img['w'] / $img['h'];
            if ($col === 0) { if ($y + $ih + 24 > $limite) { $nueva(); } $yFila = $y; $altoFila = 0.0; }
            $x = $L + $col * ($cw + 14);
            $pdf->rect($x, $yFila, $cw, $ih + 22, '#ffffff', COT_LINEA, 0.6);
            $pdf->image($img, $x + ($cw - $iw) / 2, $yFila + 3, $iw, $ih);
            $pdf->text($x + 4, $yFila + $ih + 15, $pdf->fit(pathinfo($a['nombre_original'], PATHINFO_FILENAME), $cw - 8, 8), 8, false, COT_GRIS);
            $altoFila = max($altoFila, $ih + 22);
            if ($col === 1) { $y = $yFila + $altoFila + 10; $col = 0; } else $col = 1;
        }
        if ($col === 1) $y = $yFila + $altoFila + 10;
        $y += 6;
    }

    // Aceptación
    $asegurar(110);
    $titulo(++$n . '. ACEPTACIÓN DEL SERVICIO');
    $parrafo('El cliente recibe el servicio descrito en este informe a satisfacción, con las pruebas y observaciones indicadas.', 9.5);
    $y += 34;
    $mitad = ($W - 40) / 2;
    $pdf->line($L, $y, $L + $mitad, $y, COT_TEXTO, 0.7); $pdf->line($L + $mitad + 40, $y, $L + $W, $y, COT_TEXTO, 0.7);
    $pdf->text($L, $y + 12, $pdf->fit((string)($i['tecnico_nombre'] ?: $em['firmante']), $mitad, 9.5, true), 9.5, true, COT_NAVY);
    $pdf->text($L, $y + 24, 'Entrega · ' . $em['nombre'], 8.5, false, COT_GRIS);
    $pdf->text($L + $mitad + 40, $y + 12, $pdf->fit((string)($i['firma_nombre'] ?: $i['cliente_nombre']), $mitad, 9.5, true), 9.5, true, COT_NAVY);
    $pdf->text($L + $mitad + 40, $y + 24, 'Recibe' . ($i['firma_cargo'] ? ' · ' . $i['firma_cargo'] : '') . ' · ' . $i['cliente_nombre'], 8.5, false, COT_GRIS);
    $y += 34;

    $pie();
    return $pdf;
}
