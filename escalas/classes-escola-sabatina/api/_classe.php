<?php
/**
 * api/_classe.php
 * Descobre de qual tipo de lição e de qual classe é o pedido
 * (?tipo=adultos&classe=vivendo-o-reino) e define:
 *   TIPO_DIR   = pasta do tipo (lições e capa do trimestre; tem tipo.php)
 *   CLASSE_DIR = pasta da classe (escalas.json, responsaveis.json, usuarios.json,
 *                fotos/, backups/, log.ndjson e classe.php)
 * Não é um endpoint (o "_" no nome indica isso).
 */
if (!defined('CLASSE_DIR')) {
    $nomeValido = '/^[a-z0-9][a-z0-9-]{0,60}$/';
    $pastaTipo = (string)($_GET['tipo'] ?? '');
    $pastaClasse = (string)($_GET['classe'] ?? '');
    $dirTipo = __DIR__ . '/../' . $pastaTipo;
    $dirClasse = $dirTipo . '/' . $pastaClasse;
    if (!preg_match($nomeValido, $pastaTipo) || !preg_match($nomeValido, $pastaClasse)
        || !is_file($dirTipo . '/tipo.php') || !is_file($dirClasse . '/classe.php')) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erro' => 'Classe não encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    define('TIPO_DIR', realpath($dirTipo));
    define('TIPO_PASTA', $pastaTipo);
    define('CLASSE_DIR', realpath($dirClasse));
    // Nome antigo do cadastro de responsáveis: renomeia sozinho na primeira vez
    if (!file_exists(CLASSE_DIR . '/responsaveis.json') && file_exists(CLASSE_DIR . '/professores.json')) {
        @rename(CLASSE_DIR . '/professores.json', CLASSE_DIR . '/responsaveis.json');
    }
}
