<?php
/**
 * api/sincronizar.php
 * Completa um trimestre montado automaticamente: baixa a capa do trimestre e
 * recorta, da revista impressa (PDF), a ilustração de cada lição.
 * Faz pouco por vez (a capa e até 2 lições por chamada) para não deixar o servidor lento.
 * A página pública chama isto sozinha enquanto faltar alguma imagem.
 *   GET ?tipo=adultos&classe=vivendo-o-reino&trimestre=2027-01
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_licoes.php';
require_once __DIR__ . '/_pdf.php';
@set_time_limit(120);

$trimestre = $_GET['trimestre'] ?? '';
if (!preg_match('/^(20\d{2})-0([1-4])$/', $trimestre)) falharAPI(400, 'Trimestre inválido.');
$arq = arquivoDoTrimestre($trimestre);
if (!file_exists($arq)) falharAPI(404, 'Trimestre ainda não montado.');

// Só uma sincronização por vez para cada tipo
$trava = fopen(dirname($arq) . '/.sincronizando_' . $trimestre, 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) responder(200, ['ok' => true, 'ocupado' => true]);

$dados = json_decode(file_get_contents($arq), true);
if (!is_array($dados)) falharAPI(500, 'Arquivo do trimestre corrompido.');
$pastaImg = dirname($arq) . '/' . $trimestre;
if (!is_dir($pastaImg)) @mkdir($pastaImg, 0755, true);
$feito = [];

// 1) Capa do trimestre (fundo da página)
if (!empty($dados['capa_pendente'])) {
    $img = ($b = baixar($dados['capa'])) ? @imagecreatefromstring($b) : null;
    if ($img && salvarJpg($img, $pastaImg . '/capa.jpg', 640, 960)) {
        $dados['capa'] = 'licoes/' . $trimestre . '/capa.jpg';
        $feito[] = 'capa';
    }
    $dados['capa_pendente'] = false;   // se falhar, fica a capa oficial pelo endereço do site
}

// 2) Até 2 ilustrações por chamada
$codigo = codigoNoSite($trimestre);
$tentativas = $dados['tentativas'] ?? [];
foreach (array_slice($dados['pendentes'] ?? [], 0, 2) as $id) {
    $data = null;
    foreach ($dados['licoes'] as $d => $l) if (($l['id'] ?? '') === $id) $data = $d;
    $ok = false;
    if ($data) {
        $info = json_decode((string)baixar("https://sabbath-school.adventech.io/api/v2/pt/quarterlies/$codigo/lessons/$id/index.json"), true);
        $tema = trim($dados['licoes'][$data]['tema']);
        $pdfs = $info['pdfs'] ?? [];
        $escolhido = null;
        foreach ($pdfs as $p) if (trim($p['title'] ?? '') === $tema) $escolhido = $p;
        if (!$escolhido) foreach ($pdfs as $p) if (strcasecmp(trim($p['title'] ?? ''), 'Intro') !== 0) { $escolhido = $p; break; }
        if ($escolhido && ($pdf = baixar($escolhido['src'], 60)) && ilustracaoDoPdf($pdf, "$pastaImg/$id.jpg")) {
            $dados['licoes'][$data]['capa'] = "licoes/$trimestre/$id.jpg";
            $ok = true; $feito[] = $id;
        }
    }
    $tentativas[$id] = ($tentativas[$id] ?? 0) + 1;
    // Conseguiu, ou já tentou 3 vezes: tira da fila (fica a imagem oficial)
    if ($ok || $tentativas[$id] >= 3) $dados['pendentes'] = array_values(array_diff($dados['pendentes'], [$id]));
}
$dados['tentativas'] = $tentativas;

gravarTrimestre($trimestre, $dados);
flock($trava, LOCK_UN);
responder(200, ['ok' => true, 'feito' => $feito, 'faltam' => count($dados['pendentes'])]);
