<?php
/**
 * Órdenes de servicio: orden de trabajo que se emite antes de ir donde el cliente (qué hay que hacer, quién va y cuándo)
 * y que el técnico completa con lo realizado, materiales, horas, fotos de antes y después y la firma del cliente.
 * Se puede crear desde un ticket o una cotización aceptada, y de la orden se genera el informe de servicio.
 */
require_once APP_DIR . '/informes.php';

const ORD_ESTADOS = ['Pendiente', 'Programada', 'En curso', 'Completada', 'Cancelada'];
const ORD_TIPOS = ['Instalación', 'Mantenimiento preventivo', 'Mantenimiento correctivo', 'Soporte en sitio', 'Revisión técnica', 'Visita técnica', 'Configuración', 'Otro'];
const ORD_PRIORIDADES = ['Normal', 'Alta', 'Urgente'];
const ORD_ABIERTAS = ['Pendiente', 'Programada', 'En curso'];

function ord_siguiente(): int { return max(1, (int)val('SELECT COALESCE(MAX(consecutivo), 0) + 1 FROM ordenes')); }
function ord_codigo(array $o): string { return 'OS-' . str_pad((string)$o['consecutivo'], 4, '0', STR_PAD_LEFT); }
function ord_archivo(array $o): string { return 'Orden_' . ord_codigo($o) . '.pdf'; }

function ord_obtener(int $id): ?array
{
    return one('SELECT o.*, cr.nombre AS creador, t.codigo AS ticket_codigo, i.consecutivo AS informe_consecutivo
        FROM ordenes o LEFT JOIN usuarios cr ON cr.id = o.creado_por LEFT JOIN tickets t ON t.id = o.ticket_id LEFT JOIN informes i ON i.id = o.informe_id WHERE o.id = ?', [$id]);
}
/** Filas de la orden agrupadas: materiales y horas. */
function ord_filas(int $id): array
{
    $g = ['materiales' => [], 'horas' => []];
    foreach (all('SELECT * FROM orden_filas WHERE orden_id = ? ORDER BY orden, id', [$id]) as $f) $g[$f['seccion']][] = $f;
    return $g;
}
/** Fotos de la orden: $momento = 'antes' o 'despues'. */
function ord_fotos(int $id, string $momento): array { return adjuntos('orden_' . $momento, $id); }
function ord_entidades(): array { return ['orden_antes', 'orden_despues']; }
/** Suma de horas de la tabla de horas (acepta coma decimal). */
function ord_total_horas(array $horas): float
{
    $t = 0.0;
    foreach ($horas as $f) $t += (float)str_replace(',', '.', (string)($f['c3'] ?? 0));
    return $t;
}
function ord_horas_txt(float $h): string { return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',') ?: '0'; }
function ord_badge(string $estado): string
{
    return badge($estado, ['Pendiente' => 'warning', 'Programada' => 'info', 'En curso' => 'primary', 'Completada' => 'success', 'Cancelada' => 'secondary'][$estado] ?? 'secondary');
}
function ord_prioridad_badge(string $p): string { return badge($p, ['Normal' => 'secondary', 'Alta' => 'warning', 'Urgente' => 'danger'][$p] ?? 'secondary'); }

/** Elimina la orden con sus fotos. */
function ord_eliminar(int $id): void
{
    foreach (ord_entidades() as $ent) {
        foreach (adjuntos($ent, $id) as $a) @unlink(UPLOAD_DIR . '/' . $a['archivo']);
        q('DELETE FROM adjuntos WHERE entidad = ? AND entidad_id = ?', [$ent, $id]);
    }
    q('DELETE FROM ordenes WHERE id = ?', [$id]);
}

/** Prioridad de la orden según la del ticket. */
function ord_prioridad_de_ticket(string $p): string { return ['Crítica' => 'Urgente', 'Alta' => 'Alta'][$p] ?? 'Normal'; }

/** Avisa por correo al técnico al que se le asignó la orden (cuenta general de la mesa de ayuda). */
function notificar_orden_asignada(int $ordenId): void
{
    if (!correo_activo()) return;
    $actor = (int)(user()['id'] ?? 0);
    despues(function () use ($ordenId, $actor) {
        $o = ord_obtener($ordenId);
        if (!$o || !$o['tecnico_id'] || (int)$o['tecnico_id'] === $actor) return;
        $tec = one('SELECT nombre, email FROM usuarios WHERE id = ? AND activo = 1', [(int)$o['tecnico_id']]);
        if (!$tec || !filter_var($tec['email'], FILTER_VALIDATE_EMAIL)) return;
        $cod = ord_codigo($o);
        $ficha = correo_ficha(['Orden' => '<strong>' . e($cod) . '</strong>', 'Cliente' => e($o['cliente_nombre']), 'Sede' => e($o['sede'] ?? ''), 'Servicio' => e($o['tipo']), 'Prioridad' => e($o['prioridad']),
            'Programada' => $o['fecha_programada'] ? e(fdate($o['fecha_programada']) . ($o['hora_programada'] ? ' ' . $o['hora_programada'] : '')) : null, 'Contacto' => e(trim(($o['contacto'] ?? '') . ' ' . ($o['telefono'] ?? '')))]);
        $trabajo = trim((string)$o['descripcion']) !== '' ? '<div style="margin:12px 0;padding:10px 14px;border-left:3px solid #0f766e;background:#f8fafc;white-space:pre-wrap">' . e(mb_strimwidth((string)$o['descripcion'], 0, 600, '…')) . '</div>' : '';
        enviar_correo($tec['email'], "[$cod] Se le asignó una orden de servicio: " . $o['cliente_nombre'],
            correo_plantilla('Orden de servicio ' . $cod, '<p style="margin:0 0 10px">Se le asignó esta orden de servicio.</p>' . $trabajo . $ficha, app_link('orden', ['id' => $ordenId]), 'Ver la orden'), ['motivo' => 'orden']);
    });
}

// =====================================================================
// PDF
// =====================================================================
function ord_pdf(array $o, array $filas): SimplePdf
{
    $em = cot_emisor();
    $pdf = new SimplePdf(false);
    $L = 40.0; $W = 532.0; $limite = 706.0; $y = 0.0;
    $pie = function () use ($pdf, $o, $L, $W, $em) {
        $pdf->text($L, 721, 'Orden de servicio ' . ord_codigo($o), 8, false, COT_GRIS);
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
    /** Fotos en dos columnas. */
    $galeria = function (array $fotos) use ($pdf, &$y, $nueva, $limite, $L, $W) {
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
    };

    // Encabezado
    $pdf->addPage();
    $banner = cot_banner($pdf);
    $y = 24.0;
    if ($banner) { $bw = 300.0; $bh = $bw * $banner['h'] / $banner['w']; $pdf->image($banner, (612 - $bw) / 2, $y, $bw, $bh); $y += $bh + 8; }
    else { $pdf->text($L, $y + 20, (string)$em['nombre'], 20, true, COT_NAVY); $y += 40; }
    cot_acento($pdf, $L, $y, $W);
    $y += 24;
    $pdf->text($L, $y, $em['ciudad'] . ', ' . fecha_larga(substr((string)($o['fecha_fin'] ?: $o['fecha_programada'] ?: $o['creado']), 0, 10)), 10.5, false, COT_GRIS);
    $pdf->rect($L + $W - 170, $y - 14, 170, 20, COT_NAVY);
    $pdf->text($L + $W - 170, $y, 'Orden de servicio ' . ord_codigo($o), 10.5, true, '#ffffff', 'C', 170);
    $y += 28;
    $pdf->text($L, $y, mb_strtoupper('Orden de servicio · ' . $o['tipo']), 13, true, COT_NAVY); $y += 22;

    // Ficha
    $prog = $o['fecha_programada'] ? fdate($o['fecha_programada']) . ($o['hora_programada'] ? ' · ' . $o['hora_programada'] : '') : '';
    $ref = trim(($o['ticket_codigo'] ? 'Ticket ' . $o['ticket_codigo'] : '') . ($o['ticket_codigo'] && $o['referencia'] ? ' · ' : '') . ($o['referencia'] ?? ''));
    $ficha = [['Cliente', $o['cliente_nombre']], ['NIT / C.C.', $o['cliente_nit']], ['Contacto', $o['contacto']], ['Teléfono', $o['telefono']],
        ['Sede / dirección', $o['sede']], ['Prioridad', $o['prioridad']], ['Técnico', $o['tecnico_nombre']], ['Programada', $prog],
        ['Inicio', $o['fecha_inicio'] ? fdate($o['fecha_inicio'], true) : ''], ['Finalización', $o['fecha_fin'] ? fdate($o['fecha_fin'], true) : ''], ['Referencia', $ref], ['Estado', $o['estado']]];
    $filasF = (int)ceil(count($ficha) / 2); $hf = $filasF * 16 + 12;
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
    if (trim((string)$o['descripcion']) !== '') { $titulo(++$n . '. TRABAJO A REALIZAR'); $parrafo((string)$o['descripcion']); $y += 8; }
    if (trim((string)$o['trabajo_realizado']) !== '') { $titulo(++$n . '. TRABAJO REALIZADO'); $parrafo((string)$o['trabajo_realizado']); $y += 8; }
    if ($filas['materiales']) {
        $titulo(++$n . '. MATERIALES Y REPUESTOS USADOS');
        $tabla(['Cant.', 'Descripción', 'Marca / referencia', 'Observación'], array_map(fn($f) => [$f['c1'], $f['c2'], $f['c3'], $f['c4']], $filas['materiales']), [44, 220, 130, 138]);
    }
    if ($filas['horas']) {
        $titulo(++$n . '. HORAS DE TRABAJO');
        $tabla(['Fecha', 'Técnico', 'Horas', 'Detalle'], array_map(fn($f) => [$f['c1'], $f['c2'], $f['c3'], $f['c4']], $filas['horas']), [70, 130, 48, 284]);
        $y -= 10; $pdf->text($L, $y, 'Total de horas: ' . ord_horas_txt(ord_total_horas($filas['horas'])), 9.5, true, COT_NAVY); $y += 24;
    }
    if (trim((string)$o['observaciones']) !== '') { $titulo(++$n . '. OBSERVACIONES Y PENDIENTES'); $parrafo((string)$o['observaciones']); $y += 8; }
    $antes = ord_fotos((int)$o['id'], 'antes'); $despues = ord_fotos((int)$o['id'], 'despues');
    $esImg = fn($a) => in_array(strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
    $antes = array_values(array_filter($antes, $esImg)); $despues = array_values(array_filter($despues, $esImg));
    if ($antes) { $asegurar(240); $titulo(++$n . '. FOTOS ANTES DEL SERVICIO'); $galeria($antes); }   // el título no se queda solo al final de la página
    if ($despues) { $asegurar(240); $titulo(++$n . '. FOTOS DESPUÉS DEL SERVICIO'); $galeria($despues); }

    // Conformidad
    $asegurar(110);
    $titulo(++$n . '. CONFORMIDAD DEL SERVICIO');
    $parrafo('El cliente recibe el servicio descrito en esta orden a satisfacción, con las observaciones indicadas.', 9.5);
    $y += 34;
    $mitad = ($W - 40) / 2;
    $pdf->line($L, $y, $L + $mitad, $y, COT_TEXTO, 0.7); $pdf->line($L + $mitad + 40, $y, $L + $W, $y, COT_TEXTO, 0.7);
    $pdf->text($L, $y + 12, $pdf->fit((string)($o['tecnico_nombre'] ?: $em['firmante']), $mitad, 9.5, true), 9.5, true, COT_NAVY);
    $pdf->text($L, $y + 24, 'Técnico · ' . $em['nombre'], 8.5, false, COT_GRIS);
    $pdf->text($L + $mitad + 40, $y + 12, $pdf->fit((string)($o['firma_nombre'] ?: $o['cliente_nombre']), $mitad, 9.5, true), 9.5, true, COT_NAVY);
    $pdf->text($L + $mitad + 40, $y + 24, 'Recibe' . ($o['firma_cargo'] ? ' · ' . $o['firma_cargo'] : '') . ' · ' . $o['cliente_nombre'], 8.5, false, COT_GRIS);

    $pie();
    return $pdf;
}
