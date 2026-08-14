<?php
/* Exporta os endereços sem coordenada em CSV, para geocodificar por fora.
   Acesse: exportar-enderecos.php   (baixa o arquivo)
   Depois de preencher Latitude/Longitude, envie de volta em
   importar-coordenadas.php */
require_once __DIR__ . '/config.php';
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Faça login primeiro.'); }

$imoveis = file_exists(ARQ_MERGE)
    ? (json_decode(file_get_contents(ARQ_MERGE), true)['imoveis'] ?? []) : [];
$geo = file_exists(DATA_DIR . '/geocode.json')
    ? (json_decode(@file_get_contents(DATA_DIR . '/geocode.json'), true) ?: []) : [];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=enderecos-sem-coordenada.csv');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));   // BOM, para o Excel abrir certo
fputcsv($out, ['Codigo', 'Endereco', 'Bairro', 'Cidade', 'Endereco completo', 'Latitude', 'Longitude'], ';');

$n = 0;
foreach ($imoveis as $x) {
    if (!empty($x['lat'])) continue;
    if (!empty($geo[(string)$x['c']]['la'])) continue;
    $completo = trim(($x['e'] ?? '') . ', ' . ($x['b'] ?? '') . ', ' . ($x['ci'] ?? 'Joinville') . ', SC');
    fputcsv($out, [$x['c'], $x['e'] ?? '', $x['b'] ?? '', $x['ci'] ?? '', $completo, '', ''], ';');
    $n++;
}
fclose($out);
