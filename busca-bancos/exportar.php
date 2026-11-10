<?php
/* busca-bancos/exportar.php — baixa o resultado da busca (mesmos filtros) em Excel ou CSV. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/../capa-financeira/lib/XlsxWriter.php';
$u = require_tool('busca-bancos');
$pdo = db();
$f = bb_filtros();
$m = BbBusca::montar($f);
$linhas = BbBusca::linhas($pdo, $m, 20000);
$cab = ['Data', 'Empresa', 'Banco', 'Conta', 'Pessoa / empresa', 'CPF/CNPJ', 'Descrição do banco', 'Nome informado pelo banco', 'Tipo', 'Nº documento', 'Valor', 'Entrada/Saída', 'Conferido', 'Anotação', 'Transferência interna', 'Anulada', 'Origem'];
$linha = fn(array $l) => [$l['data'], bb_empresa($l['empresa']), BbOfx::BANCOS[$l['banco']] ?? $l['banco'], $l['apelido'], $l['contraparte'], BbTexto::docFmt($l['doc']), $l['memo'], $l['nome'], $l['categoria'], $l['checknum'],
    (float)$l['valor'], (float)$l['valor'] >= 0 ? 'Entrada' : 'Saída', $l['conferido'] ? 'sim' : '', $l['nota'], $l['interna'] ? 'sim' : '', $l['anulada'] ? 'sim' : '', $l['origem']];
$nome = 'busca-bancos-' . date('d-m-Y-Hi');
if (($_GET['fmt'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $cab, ';');
    foreach ($linhas as $l) { $r = $linha($l); $r[0] = BbTexto::dataBr($r[0]); $r[10] = number_format($r[10], 2, ',', ''); fputcsv($o, $r, ';'); }
    exit;
}
$x = new XlsxWriter(); $s = $x->planilha('Lançamentos');
foreach ($cab as $i => $t) { $col = XlsxWriter::colNome($i + 1); $x->set($s, $col . '1', $t, ['bold' => true, 'fill' => '#303682', 'color' => '#FFFFFF']); $x->largura($s, $col, [12, 20, 14, 26, 38, 20, 60, 30, 10, 14, 15, 10, 10, 30, 10, 9, 9][$i]); }
$n = 1;
foreach ($linhas as $l) {
    $n++; foreach ($linha($l) as $i => $v) {
        $ref = XlsxWriter::colNome($i + 1) . $n;
        if ($i === 0) $x->set($s, $ref, ['d' => $v], ['fmt' => 'data']);
        elseif ($i === 10) $x->set($s, $ref, $v, ['fmt' => 'num', 'color' => $v < 0 ? '#B4512F' : '#1A7F4B']);
        else $x->set($s, $ref, (string)$v);
    }
}
$x->congelar($s, 'A2');
$tmp = tempnam(sys_get_temp_dir(), 'bb'); $x->salvar($tmp);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $nome . '.xlsx"'); header('Content-Length: ' . filesize($tmp));
readfile($tmp); @unlink($tmp);
