<?php
/* busca-bancos/ofx-mes.php — downloads da grade de Cobertura.
   ?conta=ID&mes=YYYY-MM                → JSON com o que dá para baixar daquela conta naquele mês (a melhor opção primeiro)
   ?conta=ID&mes=YYYY-MM&tipo=recriado  → .ofx recriado dos lançamentos importados
   ?conta=ID&mes=YYYY-MM&tipo=recorte&arquivo=ID → só as linhas do mês, tiradas do original guardado
   ?zip=conta&conta=ID                  → .zip com todos os meses da conta (ANO/MÊS)
   ?zip=mes&mes=YYYY-MM                 → .zip com todas as contas naquele mês */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/OfxExport.php';
$u = require_tool('busca-bancos');
$pdo = db();
$cid = (int)($_GET['conta'] ?? 0); $mes = (string)($_GET['mes'] ?? ''); $tipo = (string)($_GET['tipo'] ?? ''); $zip = (string)($_GET['zip'] ?? '');
try {
    if ($zip === 'conta' || $zip === 'mes') {
        @set_time_limit(300); @ini_set('memory_limit', '512M');
        if ($zip === 'mes' && !preg_match('/^\d{4}-\d{2}$/', $mes)) throw new RuntimeException('mês inválido');
        $c = $zip === 'conta' ? (BbCobertura::contas($pdo)[$cid] ?? null) : null;
        if ($zip === 'conta' && !$c) throw new RuntimeException('conta inválida');
        $tmp = tempnam(sys_get_temp_dir(), 'bbofx');
        BbOfxExport::zip($pdo, $tmp, null, $zip === 'conta' ? $cid : null, $zip === 'mes' ? $mes : null);
        $nome = $zip === 'conta' ? 'extratos-' . strtolower(str_replace(' ', '-', BbOfxExport::nomeConta($c))) : 'extratos-' . $mes;
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . $nome . '.zip"'); header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp); exit;
    }
    if ($tipo === 'recriado' || $tipo === 'recorte') {
        $f = BbOfxExport::arquivoMes($pdo, $cid, $mes, $tipo === 'recorte' ? (int)($_GET['arquivo'] ?? 0) : null);
        header('Content-Type: application/x-ofx'); header('Content-Disposition: attachment; filename="' . $f['nome'] . '"'); header('Content-Length: ' . strlen($f['conteudo']));
        echo $f['conteudo']; exit;
    }
    bb_json(BbOfxExport::opcoesMes($pdo, $cid, $mes));
} catch (RuntimeException $e) {
    if ($tipo === '' && $zip === '') bb_json(['erro' => $e->getMessage()], 400);
    http_response_code(400); exit($e->getMessage());
}
