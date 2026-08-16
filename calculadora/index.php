<?php
/* =====================================================================
   calculadora/index.php — Calculadora de Ganho e Funil.

   Ferramenta HTML autocontida (sem rede, sem dependências). Ela expõe VGV,
   ticket médio e o funil individual de cada corretor COM NOME — por isso só
   pode ser servida a quem está logado e tem a ferramenta 'calculadora'.

   O HTML fica em lib/app.html, com acesso direto BLOQUEADO (lib/.htaccess),
   e é entregue por readfile() somente após o login — assim o dado sensível
   nunca sai sem autenticação. O arquivo em si não é alterado (o selo de data
   e as regras de negócio embutidas ficam como vieram).
   ===================================================================== */
require_once dirname(__DIR__) . '/lib/auth.php';
require_tool('calculadora');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$app = __DIR__ . '/lib/app.html';
if (!is_readable($app)) {
    http_response_code(500);
    echo 'Arquivo lib/app.html não encontrado.';
    exit;
}
readfile($app);
