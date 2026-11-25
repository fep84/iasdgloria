<?php
/**
 * api/config.php
 * Configurações da classe pedida (?classe=...). Comum a todas as classes.
 * Incluído por outros arquivos da API — não é um endpoint.
 *
 * O que muda de uma classe para outra fica em <pasta-da-classe>/classe.php:
 * nome da classe, chave da sessão e Client ID do Google.
 *
 * Permissões (<pasta-da-classe>/usuarios.json, editável pelo Painel Administrativo):
 *   ["*"]            = administrador (usuários, backups, histórico e tudo mais)
 *   ["<nome da classe>"] = editor da classe (aulas, responsáveis e links)
 *
 * LOGIN: com 'google_client_id' vazio vale o login de teste (só o e-mail,
 * sem senha). Preenchido, só o login com Google funciona.
 */
require_once __DIR__ . '/_classe.php';

$daClasse = require CLASSE_DIR . '/classe.php';

if (strlen((string)($daClasse['sessao_secreta'] ?? '')) < 32) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Configure a sessao_secreta em classe.php (32 caracteres ou mais).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Funções da escala de cada sábado (classe.php pode ter a sua lista em 'servicos').
// 'professor' é sempre o responsável pela lição.
$servicos = [];
foreach (($daClasse['servicos'] ?? [['professor', 'Lição'], ['pastoreio', 'Pastoreio']]) as $s) {
    $campo = (string)($s[0] ?? ''); $nome = trim((string)($s[1] ?? ''));
    if (preg_match('/^[a-z][a-z0-9_]{0,30}$/', $campo) && $nome !== '' && !in_array($campo, ['licao', 'tema', 'links'], true)) $servicos[] = [$campo, $nome];
}
if (!$servicos) $servicos = [['professor', 'Lição'], ['pastoreio', 'Pastoreio']];

$permissoes = [];
$permissoesArquivo = CLASSE_DIR . '/usuarios.json';
if (file_exists($permissoesArquivo)) {
    $decodificado = json_decode(file_get_contents($permissoesArquivo), true);
    if (is_array($decodificado)) $permissoes = $decodificado;
}

return [
    'classe'           => (string)($daClasse['classe'] ?? ''),
    'sessao_secreta'   => (string)($daClasse['sessao_secreta'] ?? ''),
    'sessao_duracao'   => 8 * 60 * 60,   // 8 horas
    'google_client_id' => (string)($daClasse['google_client_id'] ?? ''),
    'quiz'             => (string)($daClasse['quiz'] ?? ''),
    'servicos'         => $servicos,
    'permissoes'       => $permissoes,
];
