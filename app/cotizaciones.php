<?php
/**
 * Cotizaciones: datos de la empresa emisora, cálculo de totales, envío por correo y generación del PDF en dos formatos:
 *  - items:     cotización con tabla de ítems (referencia, descripción, marca, unidad, cantidad, valores)
 *  - propuesta: carta con descripción, alcances, oferta comercial y condiciones comerciales
 */
require_once APP_DIR . '/lib/pdf.php';
require_once APP_DIR . '/richtext.php';

/** Datos de la empresa que cotiza (editables en Cotizaciones → Configuración). */
function cot_emisor(): array
{
    return [
        'nombre' => ajuste('cot_nombre', 'Soluciones E y S'),
        'direccion' => ajuste('cot_direccion', 'Carrera 12 # 6-38'),
        'telefonos' => ajuste('cot_telefonos', 'Cel - 3113082084'),
        'email' => ajuste('cot_email', 'jalvarez@solucioneseys.com'),
        'web' => ajuste('cot_web', 'https://solucioneseys.com/'),
        'ciudad' => ajuste('cot_ciudad', 'Yumbo'),
        'firmante' => ajuste('cot_firmante', 'Jefferson Álvarez'),
        'cargo' => ajuste('cot_cargo', 'Jefe Técnico'),
        'firma_lineas' => ajuste('cot_firma_lineas', "CEL 3113082084\n3762754"),
        'validez' => ajuste('cot_validez', '10 DIAS'),
        'entrega' => ajuste('cot_entrega', 'INMEDIATA'),
        'pago' => ajuste('cot_pago', 'CONTADO'),
        'texto_final' => (string)ajuste('cot_texto_final', ''),
    ];
}

function cot_num($n): string
{
    $n = (float)$n;
    return abs($n - round($n)) < 0.005 ? number_format($n, 0, ',', '.') : number_format($n, 2, ',', '.');
}

/** Subtotal y total (ítems + mano de obra). Las cotizaciones no manejan IVA. */
function cot_totales(array $items, float $manoObra): array
{
    $sub = 0.0;
    foreach ($items as $it) $sub += round((float)$it['cantidad'] * (float)$it['valor_unitario'], 2);
    $sub = round($sub, 2);
    return ['subtotal' => $sub, 'total' => round($sub + $manoObra, 2)];
}

/** Normaliza un encabezado: minúsculas, sin tildes ni símbolos. */
function cot_norm($s): string
{
    $s = mb_strtolower(trim((string)$s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Número de una celda de Excel/CSV: acepta 1.250.000, 1250000,50, $ 400.000, etc. */
function cot_numero($v): float
{
    if (is_int($v) || is_float($v)) return (float)$v;
    return (float)parse_money((string)$v);
}

/**
 * Lee un archivo plano (.xlsx o .csv) con las columnas Referencia, Descripción, Marca, Unidad, Cant y Valor unitario.
 * Reconoce los encabezados por nombre (en cualquier orden); si no hay encabezados usa ese orden. Devuelve [items, avisos].
 */
function cot_importar_items(string $ruta, string $ext): array
{
    require_once APP_DIR . '/lib/xlsx_read.php';
    $filas = leer_tabla($ruta, $ext, 'Items');
    $alias = [
        'referencia' => ['referencia', 'ref', 'codigo', 'cod', 'sku', 'item codigo'],
        'descripcion' => ['descripcion', 'item', 'detalle', 'producto', 'concepto', 'articulo', 'nombre'],
        'marca' => ['marca', 'fabricante'],
        'unidad' => ['unidad', 'und', 'u m', 'um', 'unidad de medida'],
        'cantidad' => ['cant', 'cantidad', 'cantid', 'qty', 'unidades'],
        'valor_unitario' => ['valor unitario', 'valor unit', 'vr unitario', 'vlr unitario', 'precio unitario', 'precio', 'costo', 'valor', 'v unitario'],
    ];
    $mapa = []; $fila0 = -1;
    foreach (array_slice($filas, 0, 10, true) as $i => $f) {
        $m = [];
        foreach ($f as $c => $h) {
            $n = cot_norm($h);
            if ($n === '') continue;
            foreach ($alias as $campo => $lista) if (!isset($m[$campo]) && in_array($n, $lista, true)) { $m[$campo] = $c; break; }
        }
        if (isset($m['descripcion']) && count($m) > count($mapa)) { $mapa = $m; $fila0 = $i; }
    }
    if (!$mapa) {   // sin encabezados: orden de la plantilla
        $mapa = ['referencia' => 0, 'descripcion' => 1, 'marca' => 2, 'unidad' => 3, 'cantidad' => 4, 'valor_unitario' => 5];
        $fila0 = -1;
        if (!$filas || count($filas[0]) < 2) throw new RuntimeException('No se reconocieron las columnas. Use la plantilla: Referencia, Descripción, Marca, Unidad, Cant, Valor unitario.');
    }
    $items = []; $avisos = [];
    foreach ($filas as $i => $f) {
        if ($i <= $fila0) continue;
        $g = fn($k) => isset($mapa[$k]) ? ($f[$mapa[$k]] ?? '') : '';
        if (implode('', array_map(fn($v) => trim((string)$v), $f)) === '') continue;
        $desc = trim((string)$g('descripcion'));
        if ($desc === '') { $avisos[] = 'Fila ' . ($i + 1) . ': sin descripción, se omitió.'; continue; }
        $cant = trim((string)$g('cantidad')) === '' ? 1.0 : cot_numero($g('cantidad'));
        $vu = cot_numero($g('valor_unitario'));
        if ($cant <= 0) { $avisos[] = 'Fila ' . ($i + 1) . ': cantidad no válida (' . $g('cantidad') . '), se usó 1.'; $cant = 1.0; }
        if ($vu < 0) { $avisos[] = 'Fila ' . ($i + 1) . ': valor unitario negativo, se usó 0.'; $vu = 0.0; }
        if (count($items) >= 500) { $avisos[] = 'Se importaron los primeros 500 ítems; el resto se omitió.'; break; }
        $items[] = ['referencia' => mb_substr(trim((string)$g('referencia')), 0, 60), 'descripcion' => mb_substr($desc, 0, 500), 'marca' => mb_substr(trim((string)$g('marca')), 0, 60),
            'unidad' => mb_substr(trim((string)$g('unidad')) ?: 'UND', 0, 20), 'cantidad' => round($cant, 2), 'valor_unitario' => round($vu, 2)];
    }
    if (!$items) throw new RuntimeException('El archivo no trae ítems con descripción.');
    return [$items, $avisos];
}

function cot_obtener(int $id): ?array
{
    return one('SELECT c.*, u.nombre AS creador FROM cotizaciones c LEFT JOIN usuarios u ON u.id = c.creado_por WHERE c.id = ?', [$id]);
}
function cot_items(int $id): array
{
    return all('SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
}

/** Banner del encabezado: el cargado en la configuración o el predeterminado. */
function cot_banner(SimplePdf $pdf): ?array
{
    foreach ([glob(UPLOAD_DIR . '/cot_banner.*')[0] ?? null, APP_ROOT . '/assets/img/banner_cotizacion.png'] as $f) {
        if ($f && is_file($f) && ($img = $pdf->addImage((string)file_get_contents($f)))) return $img;
    }
    return null;
}

function cot_pdf(array $c, array $items): SimplePdf
{
    return $c['formato'] === 'propuesta' ? cot_pdf_propuesta($c, $items) : cot_pdf_items($c, $items);
}


// ---------- Identidad visual (colores del logo) ----------
const COT_NAVY = '#0e2f56';
const COT_CORAL = '#ff304f';
const COT_TEXTO = '#17212b';
const COT_GRIS = '#5b6b7f';
const COT_LINEA = '#cfd6df';
const COT_TINTE = '#eef3f9';

/** Logo cuadrado de la empresa (se registra una sola vez por PDF). */
function cot_logo(SimplePdf $pdf): ?array
{
    static $cache = [];
    $k = spl_object_id($pdf);
    if (!isset($cache[$k]) || $cache[$k][0] !== $pdf) {
        $f = APP_ROOT . '/assets/img/logo_cotizacion.png';
        $cache[$k] = [$pdf, is_file($f) ? $pdf->addImage((string)file_get_contents($f)) : null];
    }
    return $cache[$k][1];
}

/** Franja inferior azul con el logo y los datos de contacto, centrados (se repite en cada página). */
function cot_pie_contacto(SimplePdf $pdf, array $em): void
{
    $pdf->rect(0, 730, 612, 62, COT_NAVY);
    $pdf->rect(0, 730, 612, 3, COT_CORAL);
    if ($logo = cot_logo($pdf)) $pdf->image($logo, 36, 740, 44, 44);
    $pdf->text(0, 753, trim($em['direccion'] . ' ' . $em['telefonos']), 9, false, '#ffffff', 'C', 612);
    $pdf->text(0, 766, (string)$em['email'], 9, false, '#c9d6e6', 'C', 612);
    $pdf->text(0, 779, (string)$em['web'], 9, false, '#c9d6e6', 'C', 612);
}

/** Línea de acento bajo el encabezado: azul con un tramo coral. */
function cot_acento(SimplePdf $pdf, float $x, float $y, float $w): void
{
    $pdf->rect($x, $y, $w, 2.5, COT_NAVY);
    $pdf->rect($x, $y, 90, 2.5, COT_CORAL);
}

/** Total(es) de la cotización para listas y correos: un total, «$ a / $ b» con dos ofertas o la suma si se decidió sumarlas. */
function cot_total_texto(array $c): string
{
    $t2 = (float)($c['total2'] ?? 0);
    $s1 = (int)($c['sumar1'] ?? 1) === 1; $s2 = (int)($c['sumar2'] ?? 1) === 1;
    if ($t2 > 0 && !empty($c['sumar_ofertas']) && $s1 && $s2) return money((float)$c['total'] + $t2) . ' (suma de 2 ofertas)';
    $a = $s1 ? money($c['total']) : 'sin total';
    return $t2 > 0 ? $a . ' / ' . ($s2 ? money($t2) : 'sin total') : ($s1 ? $a : 'Sin total');
}

/** Nombre de archivo del PDF. */
function cot_archivo(array $c): string { return 'Cotizacion_' . $c['consecutivo'] . '.pdf'; }

// =====================================================================
// Formato 1: cotización con ítems
// =====================================================================
function cot_pdf_items(array $c, array $items): SimplePdf
{
    $em = cot_emisor();
    $pdf = new SimplePdf(false);
    $L = 40.0; $W = 532.0; $bottom = 712.0;
    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 24.0;
    if ($banner) {
        $bw = 400.0; $bh = $bw * $banner['h'] / $banner['w'];
        $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh);
        $y += $bh + 8;
    } else {
        $pdf->text($L, $y + 24, (string)$em['nombre'], 22, true, COT_NAVY);
        $y += 46;
    }
    cot_acento($pdf, $L, $y, $W);
    $y += 8;

    // Datos del cliente
    $filas = [['Señores:', $c['cliente_nombre'], true], ['C.C o NIT:', $c['cliente_nit'], false], ['Dirección', $c['cliente_direccion'], false],
        ['Teléfono:', $c['cliente_telefono'], false], ['E-mail:', $c['cliente_email'], false], ['Estimado(a) Sr.(a):', $c['atencion'], true]];
    $y0 = $y;
    foreach ($filas as $i => [$lbl, $val, $b]) {
        $y += 13;
        $pdf->text($L, $y, $lbl, 9.5, false, COT_GRIS);
        // las tres primeras filas quedan por encima del recuadro «Cotización» y pueden ser más anchas
        $pdf->text($L + 112, $y, $pdf->fit((string)$val, $i < 3 ? 380 : 240, 9.5, $b), 9.5, $b, $lbl === 'E-mail:' ? '#1a56db' : ($b ? COT_NAVY : COT_TEXTO));
    }
    // Recuadro Cotización / Páginas
    $bx = 404.0; $by = $y0 + 52; $cw = 84.0;
    $pdf->rect($bx, $by, $cw * 2, 15, COT_NAVY, COT_NAVY, 0.6);
    $pdf->rect($bx, $by + 15, $cw * 2, 16, COT_TINTE, COT_NAVY, 0.8);
    $pdf->line($bx + $cw, $by + 15, $bx + $cw, $by + 31, COT_NAVY, 0.6);
    $pdf->text($bx, $by + 11, 'Cotización', 9, true, '#ffffff', 'C', $cw);
    $pdf->text($bx + $cw, $by + 11, 'Páginas', 9, true, '#ffffff', 'C', $cw);
    $pdf->text($bx, $by + 27, (string)$c['consecutivo'], 10, true, COT_CORAL, 'C', $cw);
    $pdf->text($bx + $cw, $by + 27, '1 de {nb}', 9.5, true, COT_NAVY, 'C', $cw);
    $y += 14;
    $pdf->text($L, $y, 'En atención a su solicitud nos permitimos presentar a consideración nuestra oferta:', 9.5, false, COT_TEXTO);
    $y += 8;

    // Tabla de ítems
    $cols = [['Ítem', 28, 'C'], ['Referencia', 62, 'L'], ['Descripción', 184, 'L'], ['Marca', 52, 'L'], ['Unidad', 40, 'C'], ['Cant', 34, 'C'], ['Valor Unit.', 64, 'R'], ['Valor Total', 68, 'R']];
    $xs = [$L]; foreach ($cols as $col) $xs[] = end($xs) + $col[1];
    $cabecera = function () use ($pdf, $cols, $xs, $L, $W, &$y) {
        $pdf->rect($L, $y, $W, 18, COT_NAVY, COT_NAVY, 0.6);
        foreach ($cols as $i => [$t, $w, $al]) $pdf->text($xs[$i], $y + 12.5, $t, 9, true, '#ffffff', 'C', $w);
        $y += 18;
    };
    $pie = function (int $pagina) use ($pdf, $c, $L, $W, $em) {
        $pdf->text($L, 721, 'Cotización ' . $c['consecutivo'], 8, false, COT_GRIS);
        $pdf->text($L, 721, 'Página ' . $pagina . ' de {nb}', 8, false, COT_GRIS, 'R', $W);
        cot_pie_contacto($pdf, $em);
    };
    $cabecera();
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $desc = $pdf->wrap((string)$it['descripcion'], 184 - 8, 9);
        $h = max(18, count($desc) * 11 + 7);
        if ($y + $h > $bottom - 30) {
            $pie($pdf->pageNo());
            $pdf->addPage(); $y = 40; $cabecera();
        }
        $pdf->rect($L, $y, $W, $h, $n % 2 === 0 ? COT_TINTE : '', COT_LINEA, 0.6);
        foreach ($xs as $i => $x) if ($i > 0 && $i < count($cols)) $pdf->line($x, $y, $x, $y + $h, COT_LINEA, 0.5);
        $pdf->text($xs[0], $y + 12.5, (string)$n, 9, false, COT_GRIS, 'C', $cols[0][1]);
        $pdf->text($xs[1] + 3, $y + 12.5, $pdf->fit((string)$it['referencia'], $cols[1][1] - 6, 8.5), 8.5, false, COT_TEXTO);
        foreach ($desc as $k => $ln) $pdf->text($xs[2] + 4, $y + 12.5 + $k * 11, $ln, 9, false, COT_TEXTO);
        $pdf->text($xs[3] + 3, $y + 12.5, $pdf->fit((string)$it['marca'], $cols[3][1] - 6, 8.5), 8.5, false, COT_TEXTO);
        $pdf->text($xs[4], $y + 12.5, (string)$it['unidad'], 8.5, false, COT_TEXTO, 'C', $cols[4][1]);
        $pdf->text($xs[5], $y + 12.5, cot_num($it['cantidad']), 9, false, COT_TEXTO, 'C', $cols[5][1]);
        $pdf->text($xs[6] + 3, $y + 12.5, money($it['valor_unitario']), 9, false, COT_TEXTO, 'R', $cols[6][1] - 6);
        $pdf->text($xs[7] + 3, $y + 12.5, money($it['valor_total']), 9, true, COT_TEXTO, 'R', $cols[7][1] - 6);
        $y += $h;
    }
    $pdf->line($L, $y, $L + $W, $y, COT_NAVY, 1.2);
    // Totales
    if ($y + 140 > $bottom) { $pie($pdf->pageNo()); $pdf->addPage(); $y = 40; }
    $y += 10;
    $tot = (int)($c['sumar1'] ?? 1) ? [['Sub Total', $c['subtotal'] + $c['mano_obra'], false], ['TOTAL', $c['total'], true]] : [];   // el usuario decide si se suman los ítems
    foreach ($tot as [$lbl, $v, $b]) {
        $pdf->rect($xs[6], $y, $cols[6][1] + $cols[7][1], 19, $b ? COT_NAVY : COT_TINTE, $b ? COT_NAVY : COT_LINEA, 0.8);
        $pdf->text($xs[6], $y + 13, $lbl, 9, true, $b ? '#ffffff' : COT_GRIS, 'R', $cols[6][1] - 4);
        $pdf->text($xs[7], $y + 13, money($v), 10, true, $b ? '#ffffff' : COT_TEXTO, 'R', $cols[7][1] - 6);
        $y += 19;
    }
    $y += 16;
    // Texto del usuario (con formato): va después de los totales y antes de los cuadros «Cordial saludo» y «Condiciones Comerciales»
    $hBox = 80;
    if (trim(strip_tags((string)($c['texto_final'] ?? ''))) !== '') {
        $asegurar = function (float $h) use (&$y, $bottom, $pdf, $pie) { if ($y + $h > $bottom) { $pie($pdf->pageNo()); $pdf->addPage(); $y = 40.0; } };
        $y += 4;
        cot_rich_pdf($pdf, (string)$c['texto_final'], $y, $L, $W, $asegurar);
        $y += 14;
    }
    if ($y + $hBox > $bottom) { $pie($pdf->pageNo()); $pdf->addPage(); $y = 40.0; }   // los cuadros no se parten entre páginas
    // Cuadros inferiores: saludo y condiciones comerciales
    $pdf->rect($L, $y, 205, $hBox, COT_TINTE, COT_LINEA, 0.8);
    $pdf->rect($L, $y, 4, $hBox, COT_CORAL);
    $linea = $y + 19;
    $pdf->text($L, $linea, 'Cordial saludo', 10, true, COT_CORAL, 'C', 205); $linea += 14;
    $pdf->text($L, $linea, (string)$em['firmante'], 10, true, COT_NAVY, 'C', 205); $linea += 14;
    foreach (preg_split('/\R/', trim((string)$em['firma_lineas'])) as $ln) { if ($linea > $y + $hBox - 6) break; $pdf->text($L, $linea, $ln, 10, true, COT_TEXTO, 'C', 205); $linea += 14; }
    $cx = $L + 205 + 14; $cw2 = $W - 205 - 14 - 60;
    $pdf->rect($cx, $y, $cw2, $hBox, COT_TINTE, COT_LINEA, 0.8);
    $pdf->rect($cx, $y, 4, $hBox, COT_NAVY);
    $linea = $y + 19;
    $pdf->text($cx, $linea, 'Condiciones Comerciales', 10, true, COT_CORAL, 'C', $cw2); $linea += 14;
    foreach ([['Validez de la oferta : ', $c['validez']], ['Tiempo de entrega : ', $c['tiempo_entrega']], ['Forma de pago ', $c['forma_pago']]] as [$k, $v]) {
        $pdf->text($cx, $linea, $k . $v, 10, true, COT_NAVY, 'C', $cw2); $linea += 14;
    }
    $pie($pdf->pageNo());
    return $pdf;
}

// =====================================================================
// Formato 2: propuesta (carta)
// =====================================================================
function cot_pdf_propuesta(array $c, array $items): SimplePdf
{
    $em = cot_emisor();
    $pdf = new SimplePdf(false);
    $L = 40.0; $W = 532.0; $limite = 706.0;
    $y = 0.0;
    $pie = function () use ($pdf, $em) { cot_pie_contacto($pdf, $em); };
    $nueva = function () use ($pdf, $pie, &$y) { if ($pdf->pageNo() > 0) $pie(); $pdf->addPage(); $y = 40.0; };
    $asegurar = function (float $h) use (&$y, $limite, $nueva) { if ($y + $h > $limite) $nueva(); };
    /** Párrafo justificado (la última línea de cada párrafo queda alineada a la izquierda). */
    $parrafo = function (string $txt, float $size = 10.5, bool $bold = false, bool $justificar = true, string $color = COT_TEXTO) use ($pdf, &$y, $asegurar, $L, $W) {
        foreach (preg_split('/\R/u', $txt) as $para) {
            $lineas = $pdf->wrap($para, $W, $size, $bold);
            foreach ($lineas as $i => $ln) {
                $asegurar(15);
                if ($justificar && $i < count($lineas) - 1) $pdf->textJ($L, $y, $ln, $size, $bold, $color, $W);
                else $pdf->text($L, $y, $ln, $size, $bold, $color);
                $y += 14.5;
            }
        }
    };
    /** Título de sección con cuadro de acento coral. */
    $titulo = function (string $t) use ($pdf, &$y, $asegurar, $L) {
        $asegurar(34);
        $pdf->rect($L, $y - 8, 7, 7, COT_CORAL);
        $pdf->text($L + 14, $y, $t, 10.5, true, COT_NAVY);
        $y += 16;
    };

    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 24.0;
    if ($banner) { $bw = 300.0; $bh = $bw * $banner['h'] / $banner['w']; $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh); $y += $bh + 8; }
    else { $pdf->text($L, $y + 20, (string)$em['nombre'], 20, true, COT_NAVY); $y += 40; }
    cot_acento($pdf, $L, $y, $W);
    $y += 24;
    $pdf->text($L, $y, ($c['ciudad'] ?: $em['ciudad']) . ', ' . fecha_larga($c['fecha']), 10.5, false, COT_GRIS);
    $pdf->rect($L + $W - 150, $y - 14, 150, 20, COT_NAVY);
    $pdf->text($L + $W - 150, $y, 'Cotización No. ' . $c['consecutivo'], 10.5, true, '#ffffff', 'C', 150);
    $y += 30;
    $pdf->text($L, $y, 'Señores', 10.5, false, COT_GRIS); $y += 14.5;
    $pdf->text($L, $y, (string)$c['cliente_nombre'], 11.5, true, COT_NAVY); $y += 14.5;
    if ($c['atencion']) { $pdf->text($L, $y, 'Atn: ' . $c['atencion'], 10.5); $y += 14.5; }
    $y += 10;
    $nOf = count(array_unique(array_map(fn($i) => (int)($i['oferta'] ?? 1), $items)));
    $pdf->text($L, $y, 'Índice:', 10.5, true, COT_NAVY); $y += 14.5;
    foreach (['1.' => 'Descripción', '2.' => 'Alcances de la propuesta', '3.' => $nOf > 1 ? 'Ofertas comerciales' : 'Oferta comercial', '4.' => 'Condiciones comerciales'] as $num => $ln) {
        $pdf->text($L + 6, $y, $num, 10.5, true, COT_CORAL); $pdf->text($L + 22, $y, $ln, 10.5, false, COT_TEXTO); $y += 14.5;
    }
    $y += 14;

    $titulo('1. DESCRIPCIÓN:');
    $parrafo((string)$c['descripcion']);
    $y += 10;
    $titulo('2. ALCANCES DE PROPUESTA:');
    cot_rich_pdf($pdf, (string)$c['alcances'], $y, $L, $W, $asegurar);   // con formato: negrillas, tamaño, tipo de letra y viñetas
    $y += 10;

    // 3. Oferta comercial (una o dos ofertas)
    $porOferta = [];
    foreach ($items as $it) $porOferta[((int)($it['oferta'] ?? 1)) ?: 1][] = $it;
    ksort($porOferta);
    $ofertas = [];
    foreach ($porOferta as $n => $its) {
        $ofertas[] = $n === 2
            ? ['n' => 2, 'titulo' => (string)($c['titulo2'] ?? ''), 'items' => $its, 'mano' => $c['mano_obra2'] ?? 0, 'total' => $c['total2'] ?? 0, 'sumar' => (int)($c['sumar2'] ?? 1)]
            : ['n' => 1, 'titulo' => (string)($c['titulo1'] ?? ''), 'items' => $its, 'mano' => $c['mano_obra'], 'total' => $c['total'], 'sumar' => (int)($c['sumar1'] ?? 1)];
    }
    $varias = count($ofertas) > 1;
    $titulo($varias ? '3. OFERTAS COMERCIALES:' : '3. OFERTA COMERCIAL:');
    $y += 2;
    $tw = [200.0, 40.0, 80.0, 80.0]; $tx = ($W - array_sum($tw)) / 2 + $L; $xs = [$tx];
    foreach ($tw as $w) $xs[] = end($xs) + $w;
    $cab = function () use ($pdf, $tw, $xs, &$y) {
        $pdf->rect($xs[0], $y, array_sum($tw), 17, COT_NAVY, COT_NAVY, 0.6);
        foreach (['Item', 'Cant', 'Valor/Unitario', 'Valor/ Total'] as $i => $t) $pdf->text($xs[$i], $y + 12, $t, 9, true, '#ffffff', 'C', $tw[$i]);
        $y += 17;
    };
    foreach ($ofertas as $of) {
        // Cada oferta se mantiene completa en una página cuando cabe
        $estimado = 17 + 16 + 8 + 19 + 40;
        foreach ($of['items'] as $it) $estimado += max(16, count($pdf->wrap((string)$it['descripcion'], 192, 9)) * 11 + 6);
        if ($estimado < 560) $asegurar($estimado + ($varias ? 14 : 0));
        if ($varias) {
            $asegurar(70);
            $pdf->text($L + 6, $y, 'Oferta ' . $of['n'] . ($of['titulo'] !== '' ? ': ' . $of['titulo'] : ''), 10.5, true, COT_CORAL);
            $y += 12;
        }
        $cab();
        $k2 = 0;
        foreach ($of['items'] as $it) {
            $k2++;
            $desc = $pdf->wrap((string)$it['descripcion'], $tw[0] - 8, 9);
            $extra = trim(($it['marca'] ? 'Marca: ' . $it['marca'] : '') . ($it['referencia'] ? '   Ref.: ' . $it['referencia'] : ''));
            $h = max(16, count($desc) * 11 + 6 + ($extra !== '' ? 10 : 0));
            if ($y + $h > $limite) { $nueva(); $cab(); }
            $pdf->rect($xs[0], $y, array_sum($tw), $h, $k2 % 2 === 0 ? COT_TINTE : '', COT_LINEA, 0.6);
            for ($i = 1; $i < 4; $i++) $pdf->line($xs[$i], $y, $xs[$i], $y + $h, COT_LINEA, 0.5);
            foreach ($desc as $k => $ln) $pdf->text($xs[0], $y + 11.5 + $k * 11, $ln, 9, false, COT_TEXTO, $k === 0 && count($desc) === 1 ? 'C' : 'L', $tw[0]);
            if ($extra !== '') $pdf->text($xs[0], $y + 11.5 + count($desc) * 11, $pdf->fit($extra, $tw[0] - 8, 7.5), 7.5, false, COT_GRIS, count($desc) === 1 ? 'C' : 'L', $tw[0]);
            $pdf->text($xs[1], $y + 11.5, cot_num($it['cantidad']), 9, false, COT_TEXTO, 'C', $tw[1]);
            $pdf->text($xs[2] + 4, $y + 11.5, money($it['valor_unitario']), 9, false, COT_TEXTO, 'R', $tw[2] - 8);
            $pdf->text($xs[3] + 4, $y + 11.5, money($it['valor_total']), 9, true, COT_TEXTO, 'R', $tw[3] - 8);
            $y += $h;
        }
        $asegurar(70);
        if ((float)$of['mano'] > 0) {
            $pdf->rect($xs[0], $y, array_sum($tw), 16, '', COT_LINEA, 0.6);
            $pdf->line($xs[3], $y, $xs[3], $y + 16, COT_LINEA, 0.5);
            $pdf->text($xs[0] + 4, $y + 11.5, 'Mano de Obra', 9, false, COT_TEXTO);
            $pdf->text($xs[3] + 4, $y + 11.5, money($of['mano']), 9, true, COT_TEXTO, 'R', $tw[3] - 8);
            $y += 16;
        }
        $y += 8;
        if (!$of['sumar']) { $y += $varias ? 24 : 20; continue; }   // sin total: solo los valores de cada ítem
        $pdf->rect($xs[0], $y, array_sum($tw), 19, COT_TINTE, COT_NAVY, 1.2);
        $pdf->line($xs[3], $y, $xs[3], $y + 19, COT_NAVY, 1.0);
        $pdf->text($xs[0], $y + 13, $varias ? 'Total oferta ' . $of['n'] : 'Total', 10, true, COT_NAVY, 'C', $tw[0] + $tw[1] + $tw[2]);
        $pdf->text($xs[3] + 4, $y + 13, money($of['total']), 10.5, true, COT_NAVY, 'R', $tw[3] - 8);
        $y += $varias ? 32 : 36;
    }
    // Decisión del usuario: sumar las dos ofertas en un total combinado
    if ($varias && !empty($c['sumar_ofertas']) && $ofertas[0]['sumar'] && $ofertas[1]['sumar']) {
        $asegurar(40);
        $suma = (float)$c['total'] + (float)($c['total2'] ?? 0);
        $pdf->rect($xs[0], $y, array_sum($tw), 21, COT_NAVY, COT_NAVY, 1.2);
        $pdf->text($xs[0], $y + 14, 'TOTAL OFERTA 1 + OFERTA 2', 10, true, '#ffffff', 'C', $tw[0] + $tw[1] + $tw[2]);
        $pdf->text($xs[3] + 4, $y + 14, money($suma), 11, true, '#ffffff', 'R', $tw[3] - 8);
        $y += 48;
    }

    // 4. Condiciones comerciales
    $asegurar(110);
    $titulo('4. CONDICIONES COMERCIALES:');
    $y += 2;
    foreach ([['Tiempo de entrega: ', $c['tiempo_entrega']], ['Forma de pago: ', $c['forma_pago']], ['Vigencia de tarifas: ', $c['validez']]] as [$k, $v]) {
        if (trim((string)$v) === '') continue;
        $asegurar(16);
        $pdf->line($L + 16, $y - 3, $L + 19.5, $y + 0.5, COT_CORAL, 1.6);   // marca ✓
        $pdf->line($L + 19.5, $y + 0.5, $L + 25.5, $y - 8, COT_CORAL, 1.6);
        $pdf->text($L + 36, $y, $k, 10.5, false, COT_GRIS);
        $pdf->text($L + 36 + $pdf->width($k, 10.5), $y, (string)$v, 10.5, true, COT_TEXTO);
        $y += 17;
    }
    $y += 8;
    $parrafo('Cualquier inquietud será atendida a la mayor brevedad posible', 10.5, false, false);
    $y += 10;
    $asegurar(72);
    $pdf->text($L, $y, 'Atentamente,', 10.5, false, COT_GRIS); $y += 28;
    $pdf->text($L, $y, (string)$em['firmante'], 11, true, COT_NAVY); $y += 14.5;
    $pdf->text($L, $y, (string)$em['cargo'], 10.5, false, COT_TEXTO); $y += 14.5;
    $pdf->text($L, $y, (string)$em['nombre'], 10.5, false, COT_GRIS);
    $pie();
    return $pdf;
}
