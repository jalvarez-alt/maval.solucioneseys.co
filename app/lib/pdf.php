<?php
/**
 * Generador de PDF mínimo (sin dependencias) para informes en tabla.
 * Fuentes estándar Helvetica / Helvetica-Bold con codificación WinAnsi (acentos y ñ).
 * Coordenadas en puntos, origen arriba a la izquierda.
 */
class SimplePdf
{
    public float $w;
    public float $h;
    private array $pages = [];
    private int $page = -1;
    private array $imgs = [];
    private const W = [
        'regular' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,537,333,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500],
        'bold' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,556,333,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,549,611,611,611,611,611,556,611,556],
    ];

    public function __construct(bool $landscape = true)
    {
        [$this->w, $this->h] = $landscape ? [792.0, 612.0] : [612.0, 792.0]; // Carta
    }

    public function addPage(): void { $this->pages[] = ''; $this->page = count($this->pages) - 1; }
    public function pageNo(): int { return $this->page + 1; }

    public static function enc(string $s): string
    {
        $s = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $s === false ? '' : $s;
    }
    public function width(string $utf8, float $size, bool $bold = false): float
    {
        $t = self::W[$bold ? 'bold' : 'regular'];
        $w = 0;
        foreach (str_split(self::enc($utf8)) as $c) $w += $t[ord($c)] ?? 556;
        return $w * $size / 1000;
    }
    /** Recorta con «…» para que quepa en $max puntos. */
    public function fit(string $s, float $max, float $size, bool $bold = false): string
    {
        if ($this->width($s, $size, $bold) <= $max) return $s;
        while ($s !== '' && $this->width($s . '…', $size, $bold) > $max) $s = mb_substr($s, 0, -1);
        return rtrim($s) . '…';
    }
    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }
    private function out(string $s): void { $this->pages[$this->page] .= $s . "\n"; }

    public function rect(float $x, float $y, float $w, float $h, string $fill = '', string $stroke = '', float $lw = 0.5): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $s = $fill ? self::rgb($fill) . ' rg ' : '';
        $s .= $stroke ? self::rgb($stroke) . ' RG ' . sprintf('%.2F w ', $lw) : '';
        $this->out($s . sprintf('%.2F %.2F %.2F %.2F re %s', $x, $this->h - $y - $h, $w, $h, $op));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#dfe4e9', float $lw = 0.5): void
    {
        $this->out(self::rgb($color) . ' RG ' . sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $lw, $x1, $this->h - $y1, $x2, $this->h - $y2));
    }
    /** Escribe texto; $y es la línea base. $align: L, R o C dentro de $w. */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, string $color = '#17212b', string $align = 'L', float $w = 0): void
    {
        if ($s === '') return;
        if ($align !== 'L' && $w > 0) {
            $tw = $this->width($s, $size, $bold);
            $x += $align === 'R' ? $w - $tw : ($w - $tw) / 2;
        }
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s));
        $this->out(sprintf('BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', self::rgb($color), $bold ? 'F2' : 'F1', $size, $x, $this->h - $y, $e));
    }

    /** Divide $s en líneas que quepan en $max puntos (respeta los saltos de línea). */
    public function wrap(string $s, float $max, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) as $para) {
            $para = trim($para);
            if ($para === '') { $lines[] = ''; continue; }
            $cur = '';
            foreach (preg_split('/\s+/u', $para) as $w) {
                $t = $cur === '' ? $w : $cur . ' ' . $w;
                if ($cur === '' || $this->width($t, $size, $bold) <= $max) $cur = $t;
                else { $lines[] = $cur; $cur = $w; }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /** Registra una imagen JPEG o PNG (8 bits, sin entrelazado) y devuelve su identificador, o null si no es compatible. */
    public function addImage(string $data): ?array
    {
        $info = @getimagesizefromstring($data);
        if (!$info) return null;
        [$w, $h] = $info;
        if ($info[2] === IMAGETYPE_JPEG) {
            $ch = (int)($info['channels'] ?? 3);
            $img = ['w' => $w, 'h' => $h, 'cs' => $ch === 1 ? '/DeviceGray' : ($ch === 4 ? '/DeviceCMYK' : '/DeviceRGB'), 'bpc' => 8, 'filter' => 'DCTDecode', 'data' => $data, 'extra' => $ch === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : ''];
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $img = self::parsePng($data, $w, $h);
            if (!$img) return null;
        } else return null;
        $this->imgs[] = $img;
        return ['n' => count($this->imgs) - 1, 'w' => $w, 'h' => $h];
    }

    private static function parsePng(string $d, int $w, int $h): ?array
    {
        $pos = 8; $idat = ''; $plte = ''; $ihdr = null;
        while ($pos + 8 <= strlen($d)) {
            $len = unpack('N', substr($d, $pos, 4))[1];
            $t = substr($d, $pos + 4, 4);
            $body = substr($d, $pos + 8, $len);
            if ($t === 'IHDR') $ihdr = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $body);
            elseif ($t === 'PLTE') $plte = $body;
            elseif ($t === 'IDAT') $idat .= $body;
            $pos += 12 + $len;
        }
        if (!$ihdr || $ihdr['il'] !== 0 || $ihdr['bd'] > 8) return null;
        $ct = $ihdr['ct']; $bd = $ihdr['bd'];
        if ($ct === 0 || $ct === 2 || $ct === 3) {
            $cols = $ct === 2 ? 3 : 1;
            $cs = $ct === 2 ? '/DeviceRGB' : ($ct === 0 ? '/DeviceGray' : '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]');
            return ['w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => $bd, 'filter' => 'FlateDecode', 'data' => $idat, 'extra' => " /DecodeParms << /Predictor 15 /Colors $cols /BitsPerComponent $bd /Columns $w >>"];
        }
        if (($ct !== 4 && $ct !== 6) || $bd !== 8) return null;
        // Con canal alfa: se reconstruyen las filas y se separa el color de la transparencia (máscara suave)
        $raw = @gzuncompress($idat);
        if ($raw === false) return null;
        $ch = $ct === 6 ? 4 : 2; $stride = $w * $ch; $prev = str_repeat("\0", $stride);
        $color = ''; $alpha = ''; $p = 0;
        for ($y = 0; $y < $h; $y++) {
            $ft = ord($raw[$p++]); $row = substr($raw, $p, $stride); $p += $stride; $out = '';
            for ($i = 0; $i < $stride; $i++) {
                $x = ord($row[$i]); $a = $i >= $ch ? ord($out[$i - $ch]) : 0; $b = ord($prev[$i]); $c = $i >= $ch ? ord($prev[$i - $ch]) : 0;
                switch ($ft) {
                    case 1: $x += $a; break;
                    case 2: $x += $b; break;
                    case 3: $x += ($a + $b) >> 1; break;
                    case 4: $pa = abs($b - $c); $pb = abs($a - $c); $pc = abs($a + $b - 2 * $c); $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); break;
                }
                $out .= chr($x & 255);
            }
            $prev = $out;
            for ($i = 0; $i < $w; $i++) {
                $px = substr($out, $i * $ch, $ch);
                $color .= $ct === 6 ? substr($px, 0, 3) : $px[0];
                $alpha .= $px[$ch - 1];
            }
        }
        return ['w' => $w, 'h' => $h, 'cs' => $ct === 6 ? '/DeviceRGB' : '/DeviceGray', 'bpc' => 8, 'filter' => 'FlateDecode', 'data' => gzcompress($color), 'extra' => '', 'smask' => gzcompress($alpha)];
    }

    /** Dibuja una imagen registrada con addImage() en el rectángulo indicado. */
    public function image(array $img, float $x, float $y, float $w, float $h): void
    {
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $w, $h, $x, $this->h - $y - $h, $img['n']));
    }
    /** Devuelve el PDF como cadena. "{nb}" en el texto se reemplaza por el total de páginas. */
    public function output(): string
    {
        $nb = (string)count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        $num = 5 + 2 * count($this->pages);
        $xobj = '';
        foreach ($this->imgs as $k => &$im) {
            $im['obj'] = $num++;
            if (isset($im['smask'])) $im['smo'] = $num++;
            $xobj .= sprintf('/Im%d %d 0 R ', $k, $im['obj']);
        }
        unset($im);
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj ? ' /XObject << ' . $xobj . '>>' : '') . ' >>';
        foreach ($this->pages as $content) {
            $content = str_replace('{nb}', $nb, $content);
            $stream = function_exists('gzcompress') ? gzcompress($content) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objs[$n] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
            $objs[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', $this->w, $this->h, $res, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        foreach ($this->imgs as $im) {
            $objs[$im['obj']] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d /Filter /%s%s%s /Length %d >>', $im['w'], $im['h'], $im['cs'], $im['bpc'], $im['filter'], $im['extra'], isset($im['smo']) ? ' /SMask ' . $im['smo'] . ' 0 R' : '', strlen($im['data'])) . "\nstream\n" . $im['data'] . "\nendstream";
            if (isset($im['smo'])) $objs[$im['smo']] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>', $im['w'], $im['h'], strlen($im['smask'])) . "\nstream\n" . $im['smask'] . "\nendstream";
        }
        $n = $num;
        $objs[$n] = '<< /Producer (MAVAL) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $o) { $offsets[$i] = strlen($pdf); $pdf .= "$i 0 obj\n$o\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $n 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    /** Envía el PDF al navegador; con $inline = true se abre en la pestaña en lugar de descargarse. */
    public function download(string $filename, bool $inline = false): void
    {
        $data = $this->output();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
}
