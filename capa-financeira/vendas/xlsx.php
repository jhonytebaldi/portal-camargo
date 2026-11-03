<?php
/* =====================================================================
   capa-financeira/vendas/xlsx.php — baixa a planilha da capa da venda
   (layout do MODELO), com a data da última edição no nome do arquivo:
   COMPRADORES_x_CONSTRUTORA_dd-mm-aaaa.xlsx
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';
require_once __DIR__ . '/lib/CapaXlsx.php';

$u = require_tool('capa-financeira');
$id = (int)($_GET['id'] ?? 0);
$v = $id ? cv_carregar($id) : null;
if (!$v) { http_response_code(404); exit('Venda não encontrada.'); }

$empNome = '';
foreach (cf_config('empresas', []) as $e) if (($e['id'] ?? '') === $v['empresa']) $empNome = (string)$e['nome'];
$pessoas = array_column(cf_pessoas(true), 'nome');
$ultima = date('d/m/Y H:i', strtotime((string)$v['atualizado_em'])) . ' por ' . ($v['atualizado_nome'] ?: '?');

$tmp = tempnam(sys_get_temp_dir(), 'capa');
try {
    CapaXlsx::gerar($v, $pessoas, $empNome, $ultima, $tmp);
} catch (Throwable $e) {
    @unlink($tmp); http_response_code(500); exit('Não consegui gerar a planilha: ' . h($e->getMessage()));
}
cv_hist($id, 0, 'xlsx', null, null, null, $u);
$nome = Venda::nomeArquivo($v, $v['compradores'], (string)$v['atualizado_em']);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nome . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
