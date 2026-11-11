<?php
/* busca-bancos/acao.php — ações rápidas da tela de busca (JSON). */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('busca-bancos');
$pdo = db();
$a = (string)($_REQUEST['a'] ?? '');
$id = (int)($_REQUEST['id'] ?? 0);
$sel = 'SELECT l.*, c.banco, c.apelido, c.empresa, c.agencia, c.conta FROM bb_lanc l JOIN bb_contas c ON c.id = l.conta_id';

if ($a === 'detalhe') {
    $st = $pdo->prepare("$sel WHERE l.id = ?"); $st->execute([$id]);
    $l = $st->fetch(); if (!$l) bb_json(['erro' => 'lançamento não encontrado'], 404);
    $st = $pdo->prepare('SELECT a.*, us.nome AS enviado_nome FROM bb_arquivos a LEFT JOIN users us ON us.id = a.enviado_por WHERE a.id = ?'); $st->execute([$l['arquivo_id']]);
    $arq = $st->fetch() ?: [];
    $par = null;
    if ($l['par_id']) { $st = $pdo->prepare("$sel WHERE l.id = ?"); $st->execute([$l['par_id']]); $par = $st->fetch() ?: null; }
    $st = $pdo->prepare("$sel WHERE l.conta_id = ? AND l.data = ? AND l.id <> ? ORDER BY l.id LIMIT 12"); $st->execute([$l['conta_id'], $l['data'], $id]);
    $viz = $st->fetchAll();
    $nPessoa = 0; $buscaPessoa = $l['doc'] !== '' ? $l['doc'] : $l['contraparte'];
    if ($l['doc'] !== '') { $st = $pdo->prepare('SELECT COUNT(*) FROM bb_lanc WHERE doc = ?'); $st->execute([$l['doc']]); $nPessoa = (int)$st->fetchColumn(); }
    elseif ($l['contraparte'] !== '') { $st = $pdo->prepare('SELECT COUNT(*) FROM bb_lanc WHERE contraparte = ?'); $st->execute([$l['contraparte']]); $nPessoa = (int)$st->fetchColumn(); }
    $conf = null;
    if ($l['conferido'] && $l['conferido_por']) { $st = $pdo->prepare('SELECT nome FROM users WHERE id = ?'); $st->execute([$l['conferido_por']]); $conf = $st->fetchColumn() ?: null; }
    $mini = fn(array $r) => ['id' => (int)$r['id'], 'data' => BbTexto::dataBr($r['data']), 'valor' => (float)$r['valor'], 'valor_fmt' => BbTexto::brl((float)$r['valor']),
        'texto' => trim($r['contraparte'] . ' — ' . $r['memo'], ' —'), 'conta' => $r['apelido'], 'banco' => BbOfx::BANCOS[$r['banco']] ?? $r['banco']];
    bb_json(['id' => (int)$l['id'], 'campos' => array_values(array_filter([
            ['Data', BbTexto::dataBr($l['data'])], ['Valor', BbTexto::brl((float)$l['valor']) . ((float)$l['valor'] >= 0 ? ' (entrada)' : ' (saída)')],
            ['Descrição do banco', $l['memo']], ['Nome informado pelo banco', $l['nome']], ['Pessoa / empresa identificada', $l['contraparte']],
            ['CPF/CNPJ', BbTexto::docFmt($l['doc'])], ['Chave Pix', 'os bancos não informam a chave no extrato'],
            ['Tipo', (['pix' => 'Pix', 'ted' => 'TED/transferência', 'boleto' => 'Boleto/cobrança', 'tarifa' => 'Tarifa', 'cartao' => 'Cartão/fatura', 'imposto' => 'Imposto', 'outro' => 'Outro'][$l['categoria']] ?? $l['categoria']) . ($l['tipo_ofx'] !== '' ? ' (' . $l['tipo_ofx'] . ' no arquivo)' : '')],
            ['Nº do documento / cobrança', $l['checknum']], ['Referência', $l['refnum']], ['Código da transação no banco', $l['fitid']],
            ['Empresa', bb_empresa($l['empresa'])], ['Banco', BbOfx::BANCOS[$l['banco']] ?? $l['banco']], ['Conta', trim(($l['agencia'] !== '' ? 'ag. ' . $l['agencia'] . ' · ' : '') . 'conta ' . $l['conta']) . ' (' . $l['apelido'] . ')'],
        ], fn($c) => $c[1] !== '')),
        'origem' => ['tipo' => ['upload' => 'Enviado na ferramenta', 'drive' => 'Pasta do Drive', 'pdf' => 'Lido do PDF da Caixa', 'omie' => 'API do Omie (Omie Cash)'][$arq['origem'] ?? ''] ?? '', 'arquivo' => $arq['nome'] ?? '',
            'caminho' => $arq['drive_caminho'] ?? '', 'link' => !empty($arq['drive_id']) ? 'https://drive.google.com/file/d/' . rawurlencode($arq['drive_id']) . '/view' : '',
            'por' => $arq['enviado_nome'] ?? ($arq && ($arq['origem'] ?? '') === 'drive' ? 'sincronização automática' : ''), 'em' => isset($arq['enviado_em']) ? date('d/m/Y H:i', strtotime($arq['enviado_em'])) : '',
            'periodo' => $arq ? BbTexto::dataBr($arq['periodo_de']) . ' a ' . BbTexto::dataBr($arq['periodo_ate']) : ''],
        'par' => $par ? $mini($par) + ['tipo' => $l['interna'] ? 'interna' : 'anulada'] : null, 'vizinhos' => array_map($mini, $viz),
        'pessoa' => ['n' => $nPessoa, 'busca' => $buscaPessoa], 'conferido' => (bool)$l['conferido'], 'conferido_por' => $conf,
        'conferido_em' => $l['conferido_em'] ? date('d/m/Y H:i', strtotime($l['conferido_em'])) : null, 'nota' => $l['nota']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bb_json(['erro' => 'método inválido'], 405);
csrf_check();
if ($a === 'conferir') {
    $v = !empty($_POST['v']) ? 1 : 0;
    $pdo->prepare('UPDATE bb_lanc SET conferido = ?, conferido_por = ?, conferido_em = ? WHERE id = ?')->execute([$v, $v ? $u['id'] : null, $v ? date('Y-m-d H:i:s') : null, $id]);
    bb_json(['ok' => true, 'conferido' => (bool)$v, 'por' => $u['nome']]);
}
if ($a === 'nota') {
    $nota = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 500);
    $pdo->prepare('UPDATE bb_lanc SET nota = ?, nota_busca = ? WHERE id = ?')->execute([$nota, BbTexto::norm($nota), $id]);
    bb_json(['ok' => true]);
}
if ($a === 'desparear') {   // "não é transferência interna" / "não se anulam"
    $st = $pdo->prepare('SELECT par_id FROM bb_lanc WHERE id = ?'); $st->execute([$id]); $par = (int)$st->fetchColumn();
    $pdo->prepare('UPDATE bb_lanc SET interna = 0, anulada = 0, par_id = NULL, sem_par = 1 WHERE id IN (?, ?)')->execute([$id, $par ?: $id]);
    bb_json(['ok' => true]);
}
bb_json(['erro' => 'ação desconhecida'], 400);
