<?php
/**
 * api/licoes.php
 * Lições do trimestre de um tipo (Adultos ou Jovens) — GET, público.
 *   ?tipo=adultos&classe=vivendo-o-reino&trimestre=2026-04
 *
 * 1) Se existe <tipo>/licoes/<trimestre>.json, devolve ele.
 * 2) Senão, monta a partir do site oficial (sabbath-school.adventech.io), grava
 *    esse arquivo e devolve. As ilustrações recortadas da revista e a capa do
 *    trimestre chegam depois, aos poucos, por sincronizar.php.
 *
 * Cada lição já vem ligada ao sábado em que é estudada na classe:
 *   { "trimestre": "2026-04", "titulo": "...", "capa": "...", "link": "...",
 *     "topo": "licoes/2026-04/topo.jpg", "cor_escura": "#994F0D", "cor_destaque": "#E28C3B",
 *     "licoes": { "03/10/2026": { "numero": "1", "tema": "...", "capa": "...", "link": "..." } } }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_licoes.php';

$trimestre = $_GET['trimestre'] ?? '';
if (!preg_match('/^(20\d{2})-0([1-4])$/', $trimestre)) falharAPI(400, 'Trimestre inválido. Use o formato 2026-04.');

$arq = arquivoDoTrimestre($trimestre);
if (file_exists($arq)) {
    $dados = json_decode(file_get_contents($arq), true);
    // Trimestre gravado antes do topo automático: marca para sincronizar.php buscar a arte do topo
    if (is_array($dados) && !array_key_exists('topo', $dados)) {
        $dados['topo'] = ''; $dados['topo_pendente'] = true;
        if (!gravarTrimestre($trimestre, $dados)) $dados['topo_pendente'] = false;
    }
    // Enquanto as imagens estão chegando, o navegador não deve guardar a resposta por muito tempo
    $falta = !empty($dados['pendentes']) || !empty($dados['capa_pendente']) || !empty($dados['topo_pendente']);
    header('Cache-Control: public, max-age=' . ($falta ? 60 : 3600));
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Evita consultar o site oficial a cada visita quando o trimestre ainda não saiu
$aviso = __DIR__ . '/../cache/sem_' . TIPO_PASTA . '_' . $trimestre;
if (file_exists($aviso) && time() - filemtime($aviso) < 6 * 3600) falharAPI(404, 'As lições deste trimestre ainda não foram publicadas.');

$dados = montarDoSite($trimestre);
if (!$dados) {
    if (!is_dir(dirname($aviso))) @mkdir(dirname($aviso), 0755, true);
    @touch($aviso);
    falharAPI(502, 'Não foi possível buscar as lições deste trimestre agora.');
}
if (!gravarTrimestre($trimestre, $dados)) {
    // Sem permissão de gravar a pasta das lições: funciona, mas só com as imagens oficiais
    $dados['pendentes'] = []; $dados['capa_pendente'] = false; $dados['topo_pendente'] = false;
}
header('Cache-Control: public, max-age=60');
echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
