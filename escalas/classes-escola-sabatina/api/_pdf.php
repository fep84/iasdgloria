<?php
/**
 * api/_pdf.php
 * Tira a ilustração da primeira página do PDF da lição impressa:
 * a maior imagem desenhada na página 1. Só o necessário para os PDFs
 * da Escola Sabatina (sem bibliotecas extras; usa zlib e GD).
 * Não é um endpoint (o "_" no nome indica isso).
 */

class PdfSimples {
    private $dados;
    private $objetos = [];   // número => texto do objeto (dicionário + stream)

    public function __construct($dados) {
        $this->dados = $dados;
        // Objetos normais: "12 0 obj ... endobj"
        if (preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $dados, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => $num) {
                $ini = $m[0][$i][1] + strlen($m[0][$i][0]);
                $fim = strpos($dados, 'endobj', $ini);
                if ($fim === false) continue;
                $this->objetos[(int)$num[0]] = substr($dados, $ini, $fim - $ini);
            }
        }
        // Objetos guardados dentro de "object streams" (/Type /ObjStm)
        foreach ($this->objetos as $num => $texto) {
            if (!preg_match('#/Type\s*/ObjStm#', $this->dicionario($texto))) continue;
            $conteudo = $this->streamDecodificado($num);
            if ($conteudo === null) continue;
            $dic = $this->dicionario($texto);
            $n = (int)$this->valor($dic, 'N');
            $primeiro = (int)$this->valor($dic, 'First');
            $cab = preg_split('/\s+/', trim(substr($conteudo, 0, $primeiro)));
            $lista = [];
            for ($i = 0; $i + 1 < count($cab) && count($lista) < $n; $i += 2) $lista[] = [(int)$cab[$i], (int)$cab[$i + 1]];
            foreach ($lista as $k => [$numObj, $desloc]) {
                $proximo = isset($lista[$k + 1]) ? $lista[$k + 1][1] : strlen($conteudo) - $primeiro;
                if (!isset($this->objetos[$numObj])) {
                    $this->objetos[$numObj] = substr($conteudo, $primeiro + $desloc, $proximo - $desloc);
                }
            }
        }
    }

    /** Parte do objeto antes de "stream" (o dicionário). */
    private function dicionario($texto) {
        $p = strpos($texto, 'stream');
        return $p === false ? $texto : substr($texto, 0, $p);
    }

    /** Valor simples de uma chave: número, nome ou referência. */
    private function valor($dic, $chave) {
        if (preg_match('#/' . $chave . '\s*(\d+\s+\d+\s+R|/[^\s/<>\[\]()]+|-?[\d.]+)#', $dic, $m)) return trim($m[1]);
        return null;
    }

    private function ref($texto) {
        return preg_match('/^(\d+)\s+\d+\s+R$/', trim((string)$texto), $m) ? (int)$m[1] : null;
    }

    private function obj($num) { return $this->objetos[$num] ?? ''; }

    /** Bytes brutos do stream de um objeto. */
    public function streamBruto($num) {
        $texto = $this->obj($num);
        $p = strpos($texto, 'stream');
        if ($p === false) return null;
        $ini = $p + 6;
        if (substr($texto, $ini, 2) === "\r\n") $ini += 2; elseif (in_array(substr($texto, $ini, 1), ["\n", "\r"], true)) $ini += 1;
        $dic = $this->dicionario($texto);
        $tam = $this->valor($dic, 'Length');
        if ($tam !== null && ($r = $this->ref($tam)) !== null) $tam = trim($this->obj($r));
        $tam = is_numeric($tam) ? (int)$tam : null;
        $fim = strrpos($texto, 'endstream');
        $bruto = substr($texto, $ini, $tam !== null ? $tam : ($fim === false ? null : $fim - $ini));
        return $bruto;
    }

    private function streamDecodificado($num) {
        $bruto = $this->streamBruto($num);
        if ($bruto === null) return null;
        if (preg_match('#/FlateDecode#', $this->dicionario($this->obj($num)))) {
            $d = @gzuncompress($bruto);
            if ($d === false) $d = @gzinflate(substr($bruto, 2));
            return $d === false ? null : $d;
        }
        return $bruto;
    }

    /** Resolve "12 0 R" ou devolve o próprio texto. */
    private function resolver($texto) {
        $r = $this->ref($texto);
        return $r === null ? $texto : $this->dicionario($this->obj($r));
    }

    /** Pega um subdicionário << ... >> de uma chave (com aninhamento), já resolvido. */
    private function subdic($dic, $chave) {
        if (!preg_match('#/' . $chave . '\s*#', $dic, $m, PREG_OFFSET_CAPTURE)) return null;
        $p = $m[0][1] + strlen($m[0][0]);
        if (preg_match('/^(\d+)\s+\d+\s+R/', substr($dic, $p), $r)) return $this->dicionario($this->obj((int)$r[1]));
        if (substr($dic, $p, 2) !== '<<') return null;
        $nivel = 0;
        for ($i = $p; $i < strlen($dic) - 1; $i++) {
            if (substr($dic, $i, 2) === '<<') { $nivel++; $i++; }
            elseif (substr($dic, $i, 2) === '>>') { $nivel--; $i++; if ($nivel === 0) return substr($dic, $p, $i - $p + 1); }
        }
        return null;
    }

    /** Dicionário da primeira página. */
    private function primeiraPagina() {
        foreach ($this->objetos as $num => $t) {
            if (preg_match('#/Type\s*/Catalog#', $this->dicionario($t))) {
                $no = $this->ref($this->valor($this->dicionario($t), 'Pages'));
                for ($volta = 0; $no !== null && $volta < 20; $volta++) {
                    $d = $this->dicionario($this->obj($no));
                    if (preg_match('#/Type\s*/Page\b(?!s)#', $d)) return [$no, $d];
                    if (!preg_match('#/Kids\s*\[\s*(\d+)\s+\d+\s+R#', $d, $k)) return null;
                    $no = (int)$k[1];
                }
            }
        }
        return null;
    }

    /** Recursos herdados: sobe pelos /Parent até achar /Resources. */
    private function recursos($numPagina, $dic) {
        for ($volta = 0; $volta < 20; $volta++) {
            $r = $this->subdic($dic, 'Resources');
            if ($r !== null) return $r;
            $pai = $this->ref($this->valor($dic, 'Parent'));
            if ($pai === null) return null;
            $dic = $this->dicionario($this->obj($pai));
        }
        return null;
    }

    /** Número do objeto da maior imagem da página 1 (olha também dentro de Forms). */
    public function maiorImagemPagina1() {
        $pg = $this->primeiraPagina();
        if (!$pg) return null;
        $melhor = null; $area = 0; $vistos = [];
        $fila = [$this->recursos($pg[0], $pg[1])];
        while ($fila) {
            $res = array_shift($fila);
            if (!$res) continue;
            $xo = $this->subdic($res, 'XObject');
            if (!$xo || !preg_match_all('#/[^\s/<>\[\]]+\s*(\d+)\s+\d+\s+R#', $xo, $m)) continue;
            foreach ($m[1] as $n) {
                $n = (int)$n;
                if (isset($vistos[$n])) continue;
                $vistos[$n] = true;
                $d = $this->dicionario($this->obj($n));
                if (preg_match('#/Subtype\s*/Image#', $d)) {
                    if (preg_match('#/ImageMask\s+true#', $d)) continue;
                    $a = (int)$this->valor($d, 'Width') * (int)$this->valor($d, 'Height');
                    if ($a > $area) { $area = $a; $melhor = $n; }
                } elseif (preg_match('#/Subtype\s*/Form#', $d)) {
                    $fila[] = $this->subdic($d, 'Resources');
                }
            }
        }
        return $melhor;
    }

    /** Converte a imagem (objeto $num) em imagem GD. */
    public function imagemGD($num) {
        $d = $this->dicionario($this->obj($num));
        $w = (int)$this->valor($d, 'Width'); $h = (int)$this->valor($d, 'Height');
        $bpc = (int)($this->valor($d, 'BitsPerComponent') ?: 8);
        if ($w <= 0 || $h <= 0) return null;

        if (preg_match('#/DCTDecode#', $d)) {
            $jpeg = $this->streamBruto($num);
            if ($this->espacoCores($d) === 4) {
                // JPEG de gráfica (CMYK): o GD não abre direito; precisa do Imagick
                if (!class_exists('Imagick')) return null;
                try {
                    $im = new Imagick(); $im->readImageBlob($jpeg);
                    $w = $im->getImageWidth(); $h = $im->getImageHeight();
                    $bytes = pack('C*', ...$im->exportImagePixels(0, 0, $w, $h, 'CMYK', Imagick::PIXEL_CHAR));
                    // Alguns JPEGs de gráfica (Adobe) guardam a tinta invertida (255 = sem tinta).
                    // Compara com o claro/escuro que o próprio Imagick enxerga numa miniatura.
                    $t = clone $im; $t->scaleImage(48, 0);
                    $tw = $t->getImageWidth(); $th = $t->getImageHeight();
                    $inv = $this->tintaInvertida($t->exportImagePixels(0, 0, $tw, $th, 'CMYK', Imagick::PIXEL_CHAR),
                                                 $t->exportImagePixels(0, 0, $tw, $th, 'I', Imagick::PIXEL_CHAR));
                } catch (Throwable $e) { return null; }
                if ($inv) $bytes = strtr($bytes, implode('', array_map('chr', range(0, 255))), implode('', array_map('chr', range(255, 0, -1))));
                return $this->cmykParaGD($bytes, $w, $h);
            }
            $img = @imagecreatefromstring($jpeg);
            return $img ?: null;
        }
        if (!preg_match('#/FlateDecode#', $d) || $bpc !== 8) return null;
        $px = $this->streamDecodificado($num);
        if ($px === null) return null;
        $comp = $this->espacoCores($d);
        if (!$comp) return null;
        $parms = $this->subdic($d, 'DecodeParms') ?? '';
        $pred = (int)($this->valor($parms, 'Predictor') ?: 1);
        if ($pred >= 10) $px = $this->desfazerPNG($px, $w * $comp, $h, $comp);
        if (strlen($px) < $w * $h * $comp) return null;

        if ($comp === 4) return $this->cmykParaGD($px, $w, $h);
        $img = imagecreatetruecolor($w, $h);
        $i = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i += $comp) {
                if ($comp === 3) { $r = ord($px[$i]); $g = ord($px[$i + 1]); $b = ord($px[$i + 2]); }
                else { $r = $g = $b = ord($px[$i]); }
                imagesetpixel($img, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
        return $img;
    }

    /**
     * CMYK de gráfica -> RGB de tela. Modelo de tintas ajustado com as revistas
     * da Escola Sabatina (fica bem próximo de como o PDF aparece na tela).
     */
    private function cmykParaGD($px, $w, $h) {
        static $lut = null;
        if ($lut === null) {
            // cor de cada tinta pura (R, G, B de 0 a 1) e curva de intensidade
            $tintas = [[0, .682, .952], [.9, 0, .55], [1, .95, 0], [0, .214, .233]];
            $gama = .844;
            $lut = [];
            for ($v = 0; $v < 256; $v++) {
                $q = pow($v / 255, $gama);
                foreach ($tintas as $t => $cor) {
                    $lut[$t][$v] = [1 - $q * (1 - $cor[0]), 1 - $q * (1 - $cor[1]), 1 - $q * (1 - $cor[2])];
                }
            }
        }
        $img = imagecreatetruecolor($w, $h);
        $i = 0; $cache = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i += 4) {
                $chave = substr($px, $i, 4);
                if (!isset($cache[$chave])) {
                    $c = $lut[0][ord($chave[0])]; $m = $lut[1][ord($chave[1])]; $a = $lut[2][ord($chave[2])]; $k = $lut[3][ord($chave[3])];
                    $r = (int)round(255 * $c[0] * $m[0] * $a[0] * $k[0]);
                    $g = (int)round(255 * $c[1] * $m[1] * $a[1] * $k[1]);
                    $b = (int)round(255 * $c[2] * $m[2] * $a[2] * $k[2]);
                    $cache[$chave] = ($r << 16) | ($g << 8) | $b;
                    if (count($cache) > 200000) $cache = [];
                }
                imagesetpixel($img, $x, $y, $cache[$chave]);
            }
        }
        return $img;
    }

    /** Se quase tudo tem tinta máxima, os valores vieram invertidos. */
    /** true se mais tinta coincide com mais claro (tinta guardada invertida). */
    private function tintaInvertida($cmyk, $claro) {
        $n = count($claro); if ($n < 2) return false;
        $ti = []; for ($i = 0; $i < $n; $i++) $ti[] = $cmyk[4*$i] + $cmyk[4*$i+1] + $cmyk[4*$i+2] + 2 * $cmyk[4*$i+3];
        $mt = array_sum($ti) / $n; $mc = array_sum($claro) / $n; $cov = 0;
        for ($i = 0; $i < $n; $i++) $cov += ($ti[$i] - $mt) * ($claro[$i] - $mc);
        if (abs($cov) < 1e-6) return $mt > 2.5 * 255;   // imagem lisa: muita "tinta" = invertida
        return $cov > 0;
    }

    private function espacoCores($d) {
        if (preg_match('#/ColorSpace\s*/DeviceCMYK#', $d)) return 4;
        if (preg_match('#/ColorSpace\s*/DeviceRGB#', $d)) return 3;
        if (preg_match('#/ColorSpace\s*/DeviceGray#', $d)) return 1;
        $cs = $this->valor($d, 'ColorSpace');
        if ($cs === null && preg_match('#/ColorSpace\s*\[\s*/ICCBased\s+(\d+)\s+\d+\s+R#', $d, $m)) $cs = $m[1] . ' 0 R';
        $r = $this->ref($cs);
        if ($r !== null) {
            $t = $this->dicionario($this->obj($r));
            if (preg_match('#/ICCBased\s+(\d+)\s+\d+\s+R#', $t, $m)) $t = $this->dicionario($this->obj((int)$m[1]));
            $n = (int)$this->valor($t, 'N');
            if (in_array($n, [1, 3, 4], true)) return $n;
            if (strpos($t, 'CMYK') !== false) return 4;
            if (strpos($t, 'RGB') !== false) return 3;
        }
        return null;
    }

    private function desfazerPNG($dados, $largura, $altura, $bpp) {
        $saida = ''; $anterior = str_repeat("\0", $largura); $p = 0;
        for ($y = 0; $y < $altura; $y++) {
            $tipo = ord($dados[$p]); $linha = substr($dados, $p + 1, $largura); $p += $largura + 1;
            $nova = '';
            for ($x = 0; $x < $largura; $x++) {
                $a = $x >= $bpp ? ord($nova[$x - $bpp]) : 0; $b = ord($anterior[$x]); $c = $x >= $bpp ? ord($anterior[$x - $bpp]) : 0;
                $v = ord($linha[$x]);
                switch ($tipo) {
                    case 1: $v += $a; break;
                    case 2: $v += $b; break;
                    case 3: $v += intdiv($a + $b, 2); break;
                    case 4: $pp = $a + $b - $c; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c);
                            $v += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); break;
                }
                $nova .= chr($v & 255);
            }
            $saida .= $nova; $anterior = $nova;
        }
        return $saida;
    }

}

/**
 * Salva a ilustração da página 1 do PDF em $destino (JPG, até $largura px).
 * Devolve true se conseguiu.
 */
function ilustracaoDoPdf($pdf, $destino, $largura = 1100) {
    $leitor = new PdfSimples($pdf);
    $num = $leitor->maiorImagemPagina1();
    if ($num === null) return false;
    $img = $leitor->imagemGD($num);
    if (!$img) return false;
    $w = imagesx($img); $h = imagesy($img);
    if ($w < 300) return false;
    if ($w > $largura) {
        $nh = (int)round($h * $largura / $w);
        $nova = imagecreatetruecolor($largura, $nh);
        imagecopyresampled($nova, $img, 0, 0, 0, 0, $largura, $nh, $w, $h);
        $img = $nova;
    }
    return imagejpeg($img, $destino, 82);
}
