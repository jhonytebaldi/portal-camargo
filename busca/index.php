<?php
/* =====================================================================
   index.php — entrega a aplicação da Busca, protegida pelo porteiro
   adaptável (_auth.php): login do portal quando é módulo do portal, ou
   login próprio quando roda sozinha.

   O conteúdo continua em lib/app.html, enviado com readfile() (não passa
   pelo interpretador PHP — evita erro 500 com trechos XML da lib).
   ===================================================================== */
require_once __DIR__ . '/_auth.php';
busca_exige_acesso();

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
