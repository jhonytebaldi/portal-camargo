<?php
/* =====================================================================
   index.php — entrega a aplicação da Busca, protegida pelo LOGIN DO PORTAL.

   O guarda de senha próprio da Busca foi trocado pelo controle do portal:
   exige estar logado E ter a ferramenta 'busca' liberada (require_tool).
   Quem não tem acesso nem chega aqui (403 / redireciona ao login).

   O conteúdo continua em lib/app.html, enviado com readfile() (não passa
   pelo interpretador PHP — evita erro 500 com trechos XML da lib).
   ===================================================================== */
require_once dirname(__DIR__) . '/lib/auth.php';   // login + RBAC do portal
$u = require_tool('busca');                          // 403 se não tiver a ferramenta

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$app = __DIR__ . '/lib/app.html';
if (!is_readable($app)) {
    http_response_code(500);
    echo 'Arquivo lib/app.html nao encontrado. Reenvie o pacote completo.';
    exit;
}
readfile($app);
