<?php
/* =====================================================================
   capa-financeira/bin/paridade.php — teste de paridade do parser PHP
   contra os JSONs gerados pela implementação de referência (Python).

   Uso:  php capa-financeira/bin/paridade.php <pasta com .xlsx e .json> [AAAA-MM-DD]
   Para cada NOME.xlsx procura NOME.json na mesma pasta e compara campo a
   campo. A data (2º argumento) fixa o "hoje" usado nos alertas de ano,
   para o resultado não mudar com o passar do tempo.
   Os casos de teste contêm dados de clientes: ficam FORA do repositório.
   ===================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Parser.php';

$dir = $argv[1] ?? '';
if (!is_dir($dir)) { fwrite(STDERR, "pasta inválida\n"); exit(2); }
$hoje = $argv[2] ?? date('Y-m-d');
$parser = new CapaParser(null, $hoje);

$camposLinha = ['linha_xlsx','tipo','data','data_raw','cf','valor','col_g','prefixo','favorecido_hist','funcao','natureza',
    'clientes_hist','construtora_hist','unidade','status_imovel','data_venda_hist','nota_fiscal','condicao',
    'parcela','total_parcelas','rotulo_fluxo','chave_estavel','chave_exata'];

$falhas = 0; $arquivos = 0; $linhasOk = 0;
foreach (glob(rtrim($dir, '/') . '/*.xlsx') as $xlsx) {
    $json = preg_replace('/\.xlsx$/i', '.json', $xlsx);
    if (!is_file($json)) { echo "SEM JSON: " . basename($xlsx) . "\n"; continue; }
    $arquivos++;
    $esp = json_decode((string)file_get_contents($json), true);
    try { $got = $parser->parse($xlsx); }
    catch (Throwable $e) { echo "ERRO " . basename($xlsx) . ": " . $e->getMessage() . "\n"; $falhas++; continue; }
    $diffs = [];
    $cmp = function ($a, $b): bool {
        if (is_float($a) || is_float($b) || is_int($a) || is_int($b)) {
            if ($a === null || $b === null) return $a === $b;
            return abs((float)$a - (float)$b) < 0.000001;
        }
        return $a === $b;
    };
    foreach ($esp['capa'] as $k => $v) if (!$cmp($v, $got['capa'][$k] ?? null)) $diffs[] = "capa.$k: esperado " . json_encode($v, JSON_UNESCAPED_UNICODE) . " obtido " . json_encode($got['capa'][$k] ?? null, JSON_UNESCAPED_UNICODE);
    $ef = $esp['capa_flags']; $gf = $got['capa_flags']; sort($ef); sort($gf);
    if ($ef !== $gf) $diffs[] = "capa_flags: " . json_encode($ef, JSON_UNESCAPED_UNICODE) . " × " . json_encode($gf, JSON_UNESCAPED_UNICODE);
    foreach (['total_pagar','total_receber','cabecalho_linha'] as $k) if (!$cmp($esp[$k] ?? null, $got[$k] ?? null)) $diffs[] = "$k: {$esp[$k]} × " . ($got[$k] ?? 'null');
    if (json_encode($esp['fluxo'] ?? []) !== json_encode($got['fluxo'] ?? [])) $diffs[] = "fluxo difere: " . json_encode($esp['fluxo'], JSON_UNESCAPED_UNICODE) . " × " . json_encode($got['fluxo'], JSON_UNESCAPED_UNICODE);
    if (json_encode($esp['bonus_bloco'] ?? []) !== json_encode($got['bonus_bloco'] ?? [])) $diffs[] = "bonus_bloco difere";
    if (count($esp['linhas']) !== count($got['linhas'])) $diffs[] = "nº de linhas: " . count($esp['linhas']) . " × " . count($got['linhas']);
    foreach ($esp['linhas'] as $i => $le) {
        $lg = $got['linhas'][$i] ?? null;
        if (!$lg) break;
        foreach ($camposLinha as $k) {
            if (!array_key_exists($k, $le) && !array_key_exists($k, $lg)) continue;
            if (!$cmp($le[$k] ?? null, $lg[$k] ?? null)) $diffs[] = "L{$le['linha_xlsx']}.$k: " . json_encode($le[$k] ?? null, JSON_UNESCAPED_UNICODE) . " × " . json_encode($lg[$k] ?? null, JSON_UNESCAPED_UNICODE);
        }
        $fe = $le['flags']; $fg = $lg['flags']; sort($fe); sort($fg);
        if ($fe !== $fg) $diffs[] = "L{$le['linha_xlsx']}.flags: " . json_encode($fe, JSON_UNESCAPED_UNICODE) . " × " . json_encode($fg, JSON_UNESCAPED_UNICODE);
        if (!$diffs) $linhasOk++;
    }
    if ($diffs) { $falhas++; echo "FALHOU " . basename($xlsx) . "\n"; foreach (array_slice($diffs, 0, 15) as $d) echo "   - $d\n"; if (count($diffs) > 15) echo "   ... +" . (count($diffs) - 15) . "\n"; }
    else echo "OK     " . basename($xlsx) . " (" . count($got['linhas']) . " linhas)\n";
}
echo "\n$arquivos arquivos, $falhas com diferenças.\n";
exit($falhas ? 1 : 0);
