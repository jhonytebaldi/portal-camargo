<?php
/* alterador-omie/_comum.php — includes e helpers do Alterador Omie. */
declare(strict_types=1);
require_once __DIR__ . '/../capa-financeira/_comum.php';        // cf_data_dir, cf_config, csrf, layout
require_once __DIR__ . '/../capa-financeira/lib/OmieEnvio.php'; // cadastros de clientes/fornecedores em cache
require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';      // chamadas ao Omie com as proteções anti-bloqueio
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lib/AoTitulo.php';
require_once __DIR__ . '/lib/AoRegras.php';
require_once __DIR__ . '/lib/AoCache.php';
require_once __DIR__ . '/lib/AoExec.php';

function ao_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** empresas com API configurada: id => nome */
function ao_empresas(): array
{
    $out = [];
    foreach (OmieApi::contas() as $id => $c) $out[$id] = $c['nome'];
    return $out;
}

function ao_empresa(string $emp): string
{
    if (!isset(ao_empresas()[$emp])) ao_json(['erro' => 'empresa sem API do Omie configurada: ' . $emp], 400);
    return $emp;
}

function ao_asset(string $arq): string
{
    $v = @filemtime(__DIR__ . '/assets/' . $arq) ?: 1;
    return '/alterador-omie/assets/' . $arq . '?v=' . $v;
}

const AO_STATUS = ['pendente' => 'na fila', 'enviado' => 'conferindo', 'conferido' => 'alterado e conferido', 'ja_ok' => 'já estava certo', 'bloqueado' => 'não alterável',
    'conflito' => 'mudou desde a prévia', 'nao_aplicado' => 'Omie não aplicou', 'aviso' => 'alterado (com aviso)', 'erro' => 'erro', 'pulado' => 'não enviado'];
