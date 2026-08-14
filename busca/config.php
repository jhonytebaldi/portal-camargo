<?php
/* =====================================================================
   Configuração — este arquivo NUNCA é servido ao navegador.
   Contém a chave da API e o hash da senha.
   ===================================================================== */

/* As credenciais ficam em credenciais.php, que NÃO faz parte do projeto e
   nunca é substituído ao atualizar. Se este arquivo for sobrescrito por uma
   versão nova, suas chaves continuam intactas. */
$_cred = __DIR__ . '/credenciais.php';
if (is_readable($_cred)) {
    require_once $_cred;
} else {
    // Compatível com a instalação antiga, que guardava tudo aqui.
    if (!defined('ROBUST_NICKNAME')) define('ROBUST_NICKNAME', 'COLE_O_NICKNAME_AQUI');
    if (!defined('ROBUST_API_KEY'))  define('ROBUST_API_KEY',  'COLE_A_CHAVE_AQUI');
    if (!defined('SENHA_HASH'))      define('SENHA_HASH', '$2y$10$COLE_O_HASH_GERADO_AQUI');
}
if (!defined('GITHUB_REPO'))   define('GITHUB_REPO', '');
if (!defined('GITHUB_TOKEN'))  define('GITHUB_TOKEN', '');
if (!defined('GITHUB_BRANCH')) define('GITHUB_BRANCH', 'main');
if (!defined('WEBHOOK_SEGREDO')) define('WEBHOOK_SEGREDO', '');

// --- Caminhos ---
// IMPORTANTE: a pasta de dados fica FORA da área pública do site.
// Em servidores nginx o .htaccess não funciona, então proteger por
// arquivo de configuração não basta — o único jeito seguro é os dados
// não estarem em pasta acessível pela web.
//
// __DIR__ é a pasta desta ferramenta (ex.: .../public_html/busca).
// dirname(__DIR__) sobe um nível (ex.: .../public_html).
// dirname(__DIR__, 2) sobe dois (ex.: a pasta da conta, fora da web).
//
// Se der erro de permissão, troque por: __DIR__ . '/dados'
define('DATA_DIR', dirname(__DIR__, 2) . '/dados-imoveis');

define('ARQ_API',      DATA_DIR . '/api.json');       // vem do cron, 1x/dia
define('ARQ_XLS',      DATA_DIR . '/xls.json');       // vem do upload manual
define('ARQ_MERGE',    DATA_DIR . '/imoveis.json');   // o que a ferramenta lê
define('ARQ_LOG',      DATA_DIR . '/sync.log');
define('ARQ_TENTATIVAS', DATA_DIR . '/tentativas.json');

// --- Segurança do login ---
if (!defined('MAX_TENTATIVAS')) define('MAX_TENTATIVAS', 6);          // bloqueia após 6 erros
if (!defined('BLOQUEIO_MINUTOS')) define('BLOQUEIO_MINUTOS', 15);       // por 15 minutos

// Mostrar telefone do proprietário? (definido como não pelo usuário)
define('MOSTRAR_TELEFONE', false);

if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0750, true); }

/** Registra evento no log, mantendo o arquivo pequeno. */
function log_sync(string $msg): void {
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(ARQ_LOG, $linha, FILE_APPEND | LOCK_EX);
    if (@filesize(ARQ_LOG) > 512000) {
        $t = @file(ARQ_LOG);
        if ($t) @file_put_contents(ARQ_LOG, implode('', array_slice($t, -1000)));
    }
}
