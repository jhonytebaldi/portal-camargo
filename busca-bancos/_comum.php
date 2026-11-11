<?php
/* =====================================================================
   busca-bancos/_comum.php — base do módulo Busca Bancos.
   Acesso: quem tem a ferramenta 'busca-bancos' liberada no admin do portal.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../admin/comum.php';     // csrf_token() / csrf_check()
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lib/Busca.php';

bb_instalar_se_preciso();
date_default_timezone_set('America/Sao_Paulo');

function bb_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** selo do banco: a logo enviada em "Contas" (se houver) ou as iniciais */
function bb_banco(string $slug, bool $comNome = false): string
{
    static $logos = null;
    if ($logos === null) { $logos = []; try { foreach (db()->query("SELECT chave, LENGTH(valor) n, JSON_UNQUOTE(JSON_EXTRACT(valor, '$.fundo')) f FROM bb_config WHERE chave LIKE 'logo:%'") as $r) $logos[substr($r['chave'], 5)] = [(int)$r['n'], preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['f']) ? (string)$r['f'] : '']; } catch (Throwable $e) {} }
    $nome = BbOfx::BANCOS[$slug] ?? $slug;
    $ini = ['sicoob' => 'Sb', 'sicredi' => 'Sd', 'caixa' => 'Cx', 'asaas' => 'As', 'inter' => 'In', 'omie-cash' => 'Om'][$slug] ?? '··';
    $selo = isset($logos[$slug])
        ? '<img class="bb-logo' . ($logos[$slug][1] ? ' fundo" style="background:' . $logos[$slug][1] : '') . '" src="/busca-bancos/logo.php?b=' . h($slug) . '&v=' . $logos[$slug][0] . '" alt="' . h($nome) . '">'
        : '<span class="bb-mono bb-mono-' . h($slug) . '" aria-hidden="true">' . $ini . '</span>';
    return '<span class="bb-banco" title="' . h($nome) . '">' . $selo . ($comNome ? '<span>' . h($nome) . '</span>' : '') . '</span>';
}

function bb_empresa(string $e): string { return BbImportador::EMPRESAS[$e] ?? ($e === '' ? 'empresa não informada' : $e); }

function bb_cabecalho(string $ativo, array $u): void
{
    $v = @filemtime(__DIR__ . '/assets/bb.css') ?: 1;
    $abas = ['busca' => ['Buscar', '/busca-bancos/'], 'cobertura' => ['Cobertura', '/busca-bancos/cobertura.php'], 'importar' => ['Importar extratos', '/busca-bancos/importar.php'],
             'conferencia' => ['Conferir planilha', '/busca-bancos/conferencia.php'], 'omie' => ['Cruzar com o Omie', '/busca-bancos/omie.php'], 'contas' => ['Contas', '/busca-bancos/contas.php']];
    portal_header('Busca Bancos', $u);
    echo '<link rel="stylesheet" href="/busca-bancos/assets/bb.css?v=' . $v . '"><meta name="csrf" content="' . h(csrf_token()) . '">';
    echo '<div class="bb"><header class="bb-topo"><a class="bb-marca" href="/busca-bancos/"><img src="/busca-bancos/assets/logo-camargo.jpg" alt="Imobiliária Camargo" height="38"><span>Busca Bancos</span></a><nav class="bb-abas">';
    foreach ($abas as $k => [$t, $href]) echo '<a href="' . $href . '"' . ($k === $ativo ? ' class="on" aria-current="page"' : '') . '>' . h($t) . '</a>';
    echo '</nav></header>';
    $pend = (int)db()->query("SELECT COUNT(*) FROM bb_contas WHERE empresa = ''")->fetchColumn();
    if ($pend && $ativo !== 'contas') echo '<div class="bb-alerta">' . $pend . ' conta(s) ainda sem empresa definida. <a href="/busca-bancos/contas.php">Informar em Contas</a></div>';
}

function bb_rodape(): void
{
    $v = @filemtime(__DIR__ . '/assets/bb.js') ?: 1;
    echo '</div><script src="/busca-bancos/assets/bb.js?v=' . $v . '"></script>';
    portal_footer();
}

/** lê os filtros da busca a partir do GET (usado pela tela e pela exportação) */
function bb_filtros(): array
{
    $g = $_GET;
    $data = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g[$k] ?? '')) ? (string)$g[$k] : '';
    $num = fn($k) => ($g[$k] ?? '') === '' ? '' : (string)(float)str_replace(',', '.', str_replace('.', '', (string)$g[$k]));
    $f = ['q' => trim((string)($g['q'] ?? '')), 'de' => $data('de'), 'ate' => $data('ate'),
        'empresas' => array_values(array_intersect((array)($g['emp'] ?? []), array_keys(BbImportador::EMPRESAS))),
        'contas' => array_values(array_filter(array_map('intval', (array)($g['conta'] ?? [])))),
        'tipo' => in_array($g['tipo'] ?? '', ['E', 'S'], true) ? $g['tipo'] : '',
        'vmin' => $num('vmin'), 'vmax' => $num('vmax'), 'tolv' => $num('tolv') === '' ? 0 : (float)$num('tolv'), 'told' => max(0, min(60, (int)($g['told'] ?? 0))),
        'ocultar_internas' => !empty($g['oi']), 'ocultar_anuladas' => !empty($g['oa']),   // padrão: mostra tudo
        'pendentes' => !empty($g['pendentes']), 'parcial' => !empty($g['parcial']),
        'origem' => (string)($g['origem'] ?? ''), 'agrupar' => !empty($g['agrupar']), 'per' => (string)($g['per'] ?? '')];
    // atalhos de período
    $hoje = date('Y-m-d');
    $at = ['mes' => [date('Y-m-01'), $hoje], 'mesant' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
           '90' => [date('Y-m-d', strtotime('-90 days')), $hoje], 'ano' => [date('Y-01-01'), $hoje], 'anoant' => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'], 'tudo' => ['', '']];
    if (isset($at[$f['per']])) [$f['de'], $f['ate']] = $at[$f['per']];
    elseif ($f['de'] !== '' || $f['ate'] !== '') $f['per'] = 'custom';
    else $f['per'] = 'tudo';
    return $f;
}
