<?php
/* =====================================================================
   listas-3c/_comum.php: base do módulo Listas 3C.

   Nível 1: exige a ferramenta 'listas-3c'. Ela se registra sozinha na
   primeira visita de um admin (schema.php), sem admin/migrar.php.
   CSRF reaproveita admin/comum.php, como a Capa Financeira.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/blocklist.php';
require_once __DIR__ . '/../admin/comum.php';     // csrf_token() / csrf_check()
require_once __DIR__ . '/lib/Montador.php';
require_once __DIR__ . '/schema.php';

// Antes de qualquer require_tool: se um admin abrir /listas-3c/ direto
// (link recebido, favorito) antes de passar pela home, as tabelas e o
// registro da ferramenta nascem aqui, e o require_tool já o deixa entrar.
l3c_instalar_se_preciso();

// Datas da tela e do cron no fuso da Camargo (a hospedagem pode estar em UTC).
date_default_timezone_set('America/Sao_Paulo');

/** Lê uma chave de l3c_config (JSON decodificado) ou null. */
function l3c_config(string $chave): mixed
{
    $st = db()->prepare('SELECT valor FROM l3c_config WHERE chave = ?');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode((string)$v, true);
}

function l3c_config_set(string $chave, mixed $valor): void
{
    db()->prepare('INSERT INTO l3c_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$chave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

/** Os 4 modelos, já com o que foi editado na tela de modelos. */
function l3c_modelos(): array
{
    return L3cModelos::todos(l3c_config('modelos'));
}

/** Catálogo de motivos (medido + os novos que aparecerem nas listas). */
function l3c_catalogo(): array
{
    $extra = (array)(l3c_config('catalogo') ?? []);
    return array_values(array_unique(array_merge(L3cModelos::CATALOGO, $extra)));
}

/** Montador com os clientes das APIs (o 3C só é exigido na hora de subir). */
function l3c_montador(bool $com3c = false): L3cMontador
{
    return new L3cMontador(db(), L3cRobust::doConfig(), $com3c ? L3cTresC::doConfig() : null);
}

function l3c_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

const L3C_STATUS = [
    'montando' => 'Montando', 'pronta' => 'Pronta para aprovar', 'enviando' => 'Subindo no 3C',
    'enviada' => 'No 3C', 'erro' => 'Erro', 'cancelada' => 'Cancelada',
];
