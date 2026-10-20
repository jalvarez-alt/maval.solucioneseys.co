<?php
/**
 * Cotizaciones: datos de la empresa emisora, cálculo de totales, envío por correo y generación del PDF en dos formatos:
 *  - items:     cotización con tabla de ítems (referencia, descripción, marca, unidad, cantidad, valores)
 *  - propuesta: carta con descripción, alcances, oferta comercial y condiciones comerciales
 */
require_once APP_DIR . '/lib/pdf.php';

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

/** Pie de página con los datos de contacto, centrado (se repite en cada página). */
function cot_pie_contacto(SimplePdf $pdf, array $em): void
{
    $pdf->line(80, 736, 532, 736, '#555555', 0.6);
    $pdf->text(40, 750, trim($em['direccion'] . ' ' . $em['telefonos']), 9, false, '#1f4e79', 'C', 532);
    $pdf->text(40, 762, (string)$em['email'], 9, false, '#1f4e79', 'C', 532);
    $pdf->text(40, 774, (string)$em['web'], 9, false, '#1f4e79', 'C', 532);
}

/** Total(es) de la cotización para listas y correos: «$ a» o «$ a / $ b» cuando hay dos ofertas. */
function cot_total_texto(array $c): string
{
    return money($c['total']) . ((float)($c['total2'] ?? 0) > 0 ? ' / ' . money($c['total2']) : '');
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
    $L = 40.0; $W = 532.0; $bottom = 718.0;
    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 28.0;
    if ($banner) {
        $bw = 400.0; $bh = $bw * $banner['h'] / $banner['w'];
        $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh);
        $y += $bh + 14;
    } else {
        $pdf->text($L, $y + 24, (string)$em['nombre'], 22, true, '#12304f');
        $y += 50;
    }

    // Datos del cliente
    $filas = [['Señores:', $c['cliente_nombre'], true], ['C.C o NIT:', $c['cliente_nit'], false], ['Dirección', $c['cliente_direccion'], false],
        ['Teléfono:', $c['cliente_telefono'], false], ['E-mail:', $c['cliente_email'], false], ['Estimado(a) Sr.(a):', $c['atencion'], true]];
    $y0 = $y;
    foreach ($filas as $i => [$lbl, $val, $b]) {
        $y += 13;
        $pdf->text($L, $y, $lbl, 9.5);
        // las tres primeras filas quedan por encima del recuadro «Cotización» y pueden ser más anchas
        $pdf->text($L + 112, $y, $pdf->fit((string)$val, $i < 3 ? 380 : 240, 9.5, $b), 9.5, $b, $lbl === 'E-mail:' ? '#1a56db' : '#000000');
    }
    // Recuadro Cotización / Páginas
    $bx = 404.0; $by = $y0 + 52; $cw = 84.0;
    $pdf->rect($bx, $by, $cw * 2, 30, '', '#000000', 1.4);
    $pdf->line($bx + $cw, $by, $bx + $cw, $by + 30, '#000000', 1.4);
    $pdf->line($bx, $by + 15, $bx + $cw * 2, $by + 15, '#000000', 1.4);
    $pdf->text($bx, $by + 11, 'Cotización', 9, true, '#000000', 'C', $cw);
    $pdf->text($bx + $cw, $by + 11, 'Páginas', 9, true, '#000000', 'C', $cw);
    $pdf->text($bx, $by + 26, (string)$c['consecutivo'], 9.5, true, '#000000', 'C', $cw);
    $pdf->text($bx + $cw, $by + 26, '1 de {nb}', 9.5, true, '#000000', 'C', $cw);
    $y += 14;
    $pdf->text($L, $y, 'En atención a su solicitud nos permitimos presentar a consideración nuestra oferta:', 9.5);
    $y += 8;

    // Tabla de ítems
    $cols = [['Ítem', 28, 'C'], ['Referencia', 62, 'L'], ['Descripción', 184, 'L'], ['Marca', 52, 'L'], ['Unidad', 40, 'C'], ['Cant', 34, 'C'], ['Valor Unit.', 64, 'R'], ['Valor Total', 68, 'R']];
    $xs = [$L]; foreach ($cols as $col) $xs[] = end($xs) + $col[1];
    $cabecera = function () use ($pdf, $cols, $xs, $L, $W, &$y) {
        $pdf->rect($L, $y, $W, 17, '', '#000000', 1.4);
        foreach ($cols as $i => [$t, $w, $al]) {
            if ($i) $pdf->line($xs[$i], $y, $xs[$i], $y + 17, '#000000', 0.8);
            $pdf->text($xs[$i], $y + 12, $t, 9, true, '#000000', 'C', $w);
        }
        $y += 17;
    };
    $pie = function (int $pagina) use ($pdf, $c, $L, $W, $em) {
        $pdf->text($L, 726, 'Cotización ' . $c['consecutivo'], 8, false, '#555555');
        $pdf->text($L, 726, 'Página ' . $pagina . ' de {nb}', 8, false, '#555555', 'R', $W);
        cot_pie_contacto($pdf, $em);
    };
    $cabecera();
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $desc = $pdf->wrap((string)$it['descripcion'], 184 - 8, 9);
        $h = max(17, count($desc) * 11 + 6);
        if ($y + $h > $bottom - 30) {
            $pie($pdf->pageNo());
            $pdf->addPage(); $y = 40; $cabecera();
        }
        $pdf->rect($L, $y, $W, $h, '', '#000000', 0.6);
        foreach ($xs as $i => $x) if ($i > 0 && $i < count($cols)) $pdf->line($x, $y, $x, $y + $h, '#000000', 0.5);
        $pdf->text($xs[0], $y + 12, (string)$n, 9, false, '#000000', 'C', $cols[0][1]);
        $pdf->text($xs[1] + 3, $y + 12, $pdf->fit((string)$it['referencia'], $cols[1][1] - 6, 8.5), 8.5);
        foreach ($desc as $k => $ln) $pdf->text($xs[2] + 4, $y + 12 + $k * 11, $ln, 9);
        $pdf->text($xs[3] + 3, $y + 12, $pdf->fit((string)$it['marca'], $cols[3][1] - 6, 8.5), 8.5);
        $pdf->text($xs[4], $y + 12, (string)$it['unidad'], 8.5, false, '#000000', 'C', $cols[4][1]);
        $pdf->text($xs[5], $y + 12, cot_num($it['cantidad']), 9, false, '#000000', 'C', $cols[5][1]);
        $pdf->text($xs[6] + 3, $y + 12, money($it['valor_unitario']), 9, false, '#000000', 'R', $cols[6][1] - 6);
        $pdf->text($xs[7] + 3, $y + 12, money($it['valor_total']), 9, false, '#000000', 'R', $cols[7][1] - 6);
        $y += $h;
    }
    // Totales
    if ($y + 130 > $bottom) { $pie($pdf->pageNo()); $pdf->addPage(); $y = 40; }
    $y += 8;
    $tot = [['Sub Total', $c['subtotal'] + $c['mano_obra'], false]];
    $tot[] = ['TOTAL', $c['total'], true];
    foreach ($tot as [$lbl, $v, $b]) {
        $pdf->rect($xs[6], $y, $cols[6][1] + $cols[7][1], 17, '', '#000000', $b ? 1.4 : 0.8);
        $pdf->line($xs[7], $y, $xs[7], $y + 17, '#000000', $b ? 1.4 : 0.8);
        $pdf->text($xs[6], $y + 12, $lbl, 9, true, '#000000', 'R', $cols[6][1] - 4);
        $pdf->text($xs[7], $y + 12, money($v), 9.5, $b, '#000000', 'R', $cols[7][1] - 6);
        $y += 17;
    }
    $y += 14;
    // Cuadros inferiores: saludo y condiciones comerciales
    $hBox = 78;
    $pdf->rect($L, $y, 205, $hBox, '', '#000000', 0.8);
    $linea = $y + 18;
    $pdf->text($L, $linea, 'Cordial saludo', 10, true, '#000000', 'C', 205); $linea += 14;
    $pdf->text($L, $linea, (string)$em['firmante'], 10, true, '#000000', 'C', 205); $linea += 14;
    foreach (preg_split('/\R/', trim((string)$em['firma_lineas'])) as $ln) { if ($linea > $y + $hBox - 6) break; $pdf->text($L, $linea, $ln, 10, true, '#000000', 'C', 205); $linea += 14; }
    $cx = $L + 205 + 14; $cw2 = $W - 205 - 14 - 90;
    $pdf->rect($cx, $y, $cw2 + 0, $hBox, '', '#000000', 0.8);
    $linea = $y + 18;
    $pdf->text($cx, $linea, 'Condiciones Comerciales', 10, true, '#000000', 'C', $cw2); $linea += 14;
    foreach ([['Validez de la oferta : ', $c['validez']], ['Tiempo de entrega : ', $c['tiempo_entrega']], ['Forma de pago ', $c['forma_pago']]] as [$k, $v]) {
        $pdf->text($cx, $linea, $k . $v, 10, true, '#000000', 'C', $cw2); $linea += 14;
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
    $L = 40.0; $W = 532.0; $limite = 722.0;
    $y = 0.0;
    $pie = function () use ($pdf, $em) { cot_pie_contacto($pdf, $em); };
    $nueva = function () use ($pdf, $pie, &$y) { if ($pdf->pageNo() > 0) $pie(); $pdf->addPage(); $y = 40.0; };
    $asegurar = function (float $h) use (&$y, $limite, $nueva) { if ($y + $h > $limite) $nueva(); };
    $parrafo = function (string $txt, float $size = 10.5, bool $bold = false, float $x = 40.0, float $w = 532.0) use ($pdf, &$y, $asegurar) {
        foreach ($pdf->wrap($txt, $w, $size, $bold) as $ln) { $asegurar(15); $pdf->text($x, $y, $ln, $size, $bold); $y += 14.5; }
    };

    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 24.0;
    if ($banner) { $bw = 300.0; $bh = $bw * $banner['h'] / $banner['w']; $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh); $y += $bh + 16; }
    else { $pdf->text($L, $y + 20, (string)$em['nombre'], 20, true, '#12304f'); $y += 44; }
    $pdf->text($L, $y, ($c['ciudad'] ?: $em['ciudad']) . ', ' . fecha_larga($c['fecha']), 10.5);
    $pdf->text($L, $y, 'Cotización No. ' . $c['consecutivo'], 10.5, true, '#000000', 'R', $W);
    $y += 28;
    $pdf->text($L, $y, 'Señores', 10.5); $y += 14.5;
    $pdf->text($L, $y, (string)$c['cliente_nombre'], 10.5, true); $y += 14.5;
    if ($c['atencion']) { $pdf->text($L, $y, 'Atn: ' . $c['atencion'], 10.5); $y += 14.5; }
    $y += 10;
    $pdf->text($L, $y, 'Índice:', 10.5); $y += 14.5;
    $nOf = count(array_unique(array_map(fn($i) => (int)($i['oferta'] ?? 1), $items)));
    foreach (['1. Descripción', '2. Alcances de la propuesta', $nOf > 1 ? '3. Ofertas comerciales' : '3. Oferta comercial', '4. Condiciones comerciales'] as $ln) { $pdf->text($L, $y, $ln, 10.5); $y += 14.5; }
    $y += 12;

    $parrafo('1. DESCRIPCIÓN:', 10.5, true);
    $parrafo((string)$c['descripcion']);
    $y += 10;
    $parrafo('2. ALCANCES DE PROPUESTA:', 10.5, true);
    $parrafo((string)$c['alcances']);
    $y += 10;

    // 3. Oferta comercial (una o dos ofertas)
    $porOferta = [];
    foreach ($items as $it) $porOferta[((int)($it['oferta'] ?? 1)) ?: 1][] = $it;
    ksort($porOferta);
    $ofertas = [];
    foreach ($porOferta as $n => $its) {
        $ofertas[] = $n === 2
            ? ['n' => 2, 'titulo' => (string)($c['titulo2'] ?? ''), 'items' => $its, 'mano' => $c['mano_obra2'] ?? 0, 'iva' => $c['iva2'] ?? 0, 'total' => $c['total2'] ?? 0]
            : ['n' => 1, 'titulo' => (string)($c['titulo1'] ?? ''), 'items' => $its, 'mano' => $c['mano_obra'], 'iva' => $c['iva'], 'total' => $c['total']];
    }
    $varias = count($ofertas) > 1;
    $asegurar(120);
    $parrafo($varias ? '3. OFERTAS COMERCIALES:' : '3. OFERTA COMERCIAL:', 10.5, true);
    $y += 4;
    $tw = [200.0, 40.0, 80.0, 80.0]; $tx = ($W - array_sum($tw)) / 2 + $L; $xs = [$tx];
    foreach ($tw as $w) $xs[] = end($xs) + $w;
    $azul = '#2e75b5';
    $cab = function () use ($pdf, $tw, $xs, $azul, &$y) {
        $pdf->rect($xs[0], $y, array_sum($tw), 15, $azul, '#000000', 0.6);
        foreach (['Item', 'Cant', 'Valor/Unitario', 'Valor/ Total'] as $i => $t) $pdf->text($xs[$i], $y + 11, $t, 9, true, '#ffffff', 'C', $tw[$i]);
        $y += 15;
    };
    foreach ($ofertas as $of) {
        if ($varias) {
            $asegurar(70);
            $parrafo('Oferta ' . $of['n'] . ($of['titulo'] !== '' ? ': ' . $of['titulo'] : ''), 10.5, true);
            $y += 2;
        }
        $cab();
        foreach ($of['items'] as $it) {
            $desc = $pdf->wrap((string)$it['descripcion'], $tw[0] - 8, 9);
            $h = max(14, count($desc) * 11 + 4);
            if ($y + $h > $limite) { $nueva(); $cab(); }
            $pdf->rect($xs[0], $y, array_sum($tw), $h, '', '#000000', 0.5);
            for ($i = 1; $i < 4; $i++) $pdf->line($xs[$i], $y, $xs[$i], $y + $h, '#000000', 0.4);
            foreach ($desc as $k => $ln) $pdf->text($xs[0], $y + 10.5 + $k * 11, $ln, 9, false, '#000000', $k === 0 && count($desc) === 1 ? 'C' : 'L', $tw[0]);
            $pdf->text($xs[1], $y + 10.5, cot_num($it['cantidad']), 9, false, '#000000', 'C', $tw[1]);
            $pdf->text($xs[2] + 4, $y + 10.5, money($it['valor_unitario']), 9, false, '#000000', 'R', $tw[2] - 8);
            $pdf->text($xs[3] + 4, $y + 10.5, money($it['valor_total']), 9, false, '#000000', 'R', $tw[3] - 8);
            $y += $h;
        }
        $asegurar(70);
        $extra = [];
        if ((float)$of['mano'] > 0) $extra[] = ['Mano de Obra', $of['mano']];
        foreach ($extra as [$lbl, $v]) {
            $pdf->rect($xs[0], $y, array_sum($tw), 14, '', '#000000', 0.5);
            $pdf->line($xs[3], $y, $xs[3], $y + 14, '#000000', 0.4);
            $pdf->text($xs[0] + 3, $y + 10.5, $lbl, 9);
            $pdf->text($xs[3] + 4, $y + 10.5, money($v), 9, false, '#000000', 'R', $tw[3] - 8);
            $y += 14;
        }
        $y += 8;
        $pdf->rect($xs[0], $y, array_sum($tw), 16, '', '#000000', 1.4);
        $pdf->line($xs[3], $y, $xs[3], $y + 16, '#000000', 1.0);
        $pdf->text($xs[0], $y + 12, $varias ? 'Total oferta ' . $of['n'] : 'Total', 10, false, '#000000', 'C', $tw[0] + $tw[1] + $tw[2]);
        $pdf->text($xs[3] + 4, $y + 12, money($of['total']), 10, true, '#000000', 'R', $tw[3] - 8);
        $y += 34;
    }
    // 4. Condiciones comerciales
    $asegurar(110);
    $parrafo('4. CONDICIONES COMERCIALES:', 10.5, true);
    $y += 4;
    foreach ([['Tiempo de entrega: ', $c['tiempo_entrega']], ['Forma de pago: ', $c['forma_pago']], ['Vigencia de tarifas: ', $c['validez']]] as [$k, $v]) {
        if (trim((string)$v) === '') continue;
        $asegurar(16);
        $pdf->line($L + 16, $y - 3, $L + 19.5, $y + 0.5, '#000000', 1.2);   // marca ✓
        $pdf->line($L + 19.5, $y + 0.5, $L + 25.5, $y - 8, '#000000', 1.2);
        $pdf->text($L + 36, $y, $k . $v, 10.5);
        $y += 17;
    }
    $y += 8;
    $parrafo('Cualquier inquietud será atendida a la mayor brevedad posible');
    $y += 10;
    $asegurar(70);
    $pdf->text($L, $y, 'Atentamente,', 10.5); $y += 28;
    $pdf->text($L, $y, (string)$em['firmante'], 10.5); $y += 14.5;
    $pdf->text($L, $y, (string)$em['cargo'], 10.5); $y += 14.5;
    $pdf->text($L, $y, (string)$em['nombre'], 10.5);
    $pie();
    return $pdf;
}
