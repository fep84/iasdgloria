<?php
/**
 * api/_licoes.php
 * Funções das lições do trimestre (usadas por licoes.php e sincronizar.php).
 * Não é um endpoint (o "_" no nome indica isso).
 *
 * As lições de cada tipo ficam em <tipo>/licoes/<trimestre>.json
 * (ex.: adultos/licoes/2027-01.json) e as imagens em <tipo>/licoes/<trimestre>/.
 * Quando o arquivo do trimestre não existe, ele é montado a partir do site oficial
 * (sabbath-school.adventech.io) e as ilustrações recortadas da revista impressa
 * vão sendo baixadas aos poucos por sincronizar.php.
 */

function baixar($url, $tempo = 20) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $tempo, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_USERAGENT => 'Escala Escola Sabatina IASD Gloria']);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $codigo === 200 ? $r : false;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $tempo]]);
    return @file_get_contents($url, false, $ctx);
}

function codigoNoSite($trimestre) {
    $tipo = require TIPO_DIR . '/tipo.php';
    return $trimestre . (string)($tipo['sufixo'] ?? '');   // ex.: 2026-04 (adultos) ou 2026-04-cq (jovens)
}

function arquivoDoTrimestre($trimestre) {
    return TIPO_DIR . '/licoes/' . $trimestre . '.json';
}

/** Monta o trimestre a partir do site oficial. Devolve o array ou null. */
function montarDoSite($trimestre) {
    $codigo = codigoNoSite($trimestre);
    $bruto = baixar('https://sabbath-school.adventech.io/api/v2/pt/quarterlies/' . $codigo . '/index.json');
    $fonte = $bruto ? json_decode($bruto, true) : null;
    if (!is_array($fonte) || empty($fonte['lessons'])) return null;

    $licoes = []; $pendentes = [];
    foreach ($fonte['lessons'] as $l) {
        $fim = DateTime::createFromFormat('d/m/Y', $l['end_date'] ?? '');
        if (!$fim) continue;
        // Sábado em que a lição é estudada na classe: o próprio fim, se já for sábado (Jovens),
        // ou o sábado seguinte (Adultos termina na sexta)
        while ($fim->format('w') !== '6') $fim->modify('+1 day');
        $id = (string)($l['id'] ?? '');
        $licoes[$fim->format('d/m/Y')] = [
            'numero' => ltrim($id, '0'),
            'tema'   => (string)($l['title'] ?? ''),
            'capa'   => (string)($l['cover'] ?? ''),   // imagem oficial até chegar o recorte da revista
            'link'   => 'https://sabbath-school.adventech.io/pt/' . $codigo . '/' . $id . '/01',
            'id'     => $id,
        ];
        if (preg_match('/^\d{2}$/', $id)) $pendentes[] = $id;
    }
    return [
        'trimestre'      => $trimestre,
        'titulo'         => (string)($fonte['quarterly']['title'] ?? ''),
        'capa'           => (string)($fonte['quarterly']['cover'] ?? ''),
        'link'           => 'https://sabbath-school.adventech.io/pt/' . $codigo,
        'automatico'     => true,
        'capa_pendente'  => true,
        // Topo da página: arte da capa sem texto ("splash") e cores oficiais do trimestre
        'topo'           => (string)($fonte['quarterly']['splash'] ?? ''),
        'cor'            => (string)($fonte['quarterly']['color_primary'] ?? ''),
        'cor_escura'     => (string)($fonte['quarterly']['color_primary_dark'] ?? ''),
        'topo_pendente'  => true,
        'pendentes'      => $pendentes,
        'licoes'         => $licoes,
    ];
}

function gravarTrimestre($trimestre, $dados) {
    $arq = arquivoDoTrimestre($trimestre);
    $pasta = dirname($arq);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) return false;
    return @file_put_contents($arq, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/** Diminui uma imagem (GD) para caber em $larg x $alt e salva como JPG. */
function salvarJpg($img, $destino, $larg, $alt) {
    $w = imagesx($img); $h = imagesy($img);
    $f = min(1, $larg / $w, $alt / $h);
    if ($f < 1) {
        $nw = (int)round($w * $f); $nh = (int)round($h * $f);
        $nova = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($nova, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $img = $nova;
    }
    return imagejpeg($img, $destino, 82);
}

/** Dados do topo (arte sem texto e cores oficiais) direto do site, para trimestres montados antes desta função. */
function topoDoSite($trimestre) {
    $bruto = baixar('https://sabbath-school.adventech.io/api/v2/pt/quarterlies/' . codigoNoSite($trimestre) . '/index.json');
    $q = $bruto ? (json_decode($bruto, true)['quarterly'] ?? null) : null;
    if (!is_array($q) || empty($q['splash'])) return null;
    return ['topo' => (string)$q['splash'], 'cor' => (string)($q['color_primary'] ?? ''), 'cor_escura' => (string)($q['color_primary_dark'] ?? '')];
}

/** Cor viva e clara da arte (para o botão do Quiz): o pixel mais saturado e claro de uma miniatura. */
function corDestaque($img) {
    $p = imagecreatetruecolor(24, 28);
    imagecopyresampled($p, $img, 0, 0, 0, 0, 24, 28, imagesx($img), imagesy($img));
    $melhor = null; $nota = -1;
    for ($x = 0; $x < 24; $x++) for ($y = 0; $y < 28; $y++) {
        $c = imagecolorat($p, $x, $y);
        $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $v = $max / 255; $s = $max ? ($max - $min) / $max : 0;
        if ($v < .6 || $s < .3) continue;
        $n = $s * $v * $v;
        if ($n > $nota) { $nota = $n; $melhor = [$r, $g, $b]; }
    }
    return $melhor ? sprintf('#%02X%02X%02X', ...$melhor) : '';
}
