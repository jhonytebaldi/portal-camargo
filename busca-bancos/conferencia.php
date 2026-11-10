<?php
/* busca-bancos/conferencia.php — sobe a planilha antiga e confere linha a linha contra os extratos. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/Conferencia.php';
$u = require_tool('busca-bancos');
$pdo = db();
$erro = '';
$tmpDir = sys_get_temp_dir();
$tmpDe = fn(string $k) => $tmpDir . '/bbconf-' . preg_replace('/[^a-f0-9]/', '', $k) . '.json';
$id = (int)($_GET['id'] ?? 0);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'subir') {
            $fl = $_FILES['planilha'] ?? null;
            if (!$fl || $fl['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Escolha a planilha (.xlsx ou .csv).');
            @ini_set('memory_limit', '512M');
            $m = BbConferencia::lerPlanilha($fl['tmp_name'], (string)$fl['name']);
            $k = bin2hex(random_bytes(12));
            file_put_contents($tmpDe($k), json_encode(['nome' => (string)$fl['name'], 'm' => $m, 'u' => (int)$u['id']], JSON_UNESCAPED_UNICODE));
            header('Location: /busca-bancos/conferencia.php?k=' . $k); exit;
        }
        if ($acao === 'conferir') {
            $k = (string)($_POST['k'] ?? ''); $arq = is_file($tmpDe($k)) ? json_decode((string)file_get_contents($tmpDe($k)), true) : null;
            if (!$arq || (int)$arq['u'] !== (int)$u['id']) throw new RuntimeException('A planilha enviada expirou. Envie de novo.');
            @set_time_limit(300); @ini_set('memory_limit', '512M');
            $novo = BbConferencia::conferir($pdo, $arq['m'], ['cab' => (int)($_POST['cab'] ?? 0), 'data' => $_POST['data'] ?? '', 'valor' => $_POST['valor'] ?? '', 'entrada' => $_POST['entrada'] ?? '', 'saida' => $_POST['saida'] ?? '', 'receber' => $_POST['receber'] ?? '', 'pagar' => $_POST['pagar'] ?? '', 'grupo' => $_POST['grupo'] ?? '', 'banco' => $_POST['banco'] ?? '',
                'escopo' => !empty($_POST['escopo']) ? 'vendas' : '', 'ano' => (int)($_POST['ano'] ?? date('Y')),
                'texto' => (array)($_POST['texto'] ?? []), 'sinal' => (string)($_POST['sinal'] ?? 'auto'), 'tolv' => (float)str_replace(',', '.', (string)($_POST['tolv'] ?? '0')), 'told' => (int)($_POST['told'] ?? 3),
                'contas' => (array)($_POST['conta'] ?? []), 'empresas' => (array)($_POST['emp'] ?? [])], $arq['nome'], (int)$u['id']);
            @unlink($tmpDe($k));
            header('Location: /busca-bancos/conferencia.php?id=' . $novo); exit;
        }
        if ($acao === 'escolher') {   // linha ambígua: "é este"; ou "nenhum destes"
            $lin = (int)($_POST['linha_id'] ?? 0); $lanc = (int)($_POST['lanc_id'] ?? 0);
            if ($lanc) $pdo->prepare("UPDATE bb_conf_linhas SET status = IF(previsto = 1, 'pend_achou', 'ok'), lanc_id = ?, motivo = 'escolhido manualmente' WHERE id = ? AND conf_id = ?")->execute([$lanc, $lin, $id]);
            else $pdo->prepare("UPDATE bb_conf_linhas SET status = IF(previsto = 1, 'pendente', 'nao'), lanc_id = NULL, candidatos = '[]', motivo = 'nenhum dos possíveis era o certo' WHERE id = ? AND conf_id = ?")->execute([$lin, $id]);
            header('Location: /busca-bancos/conferencia.php?id=' . $id . '&st=' . urlencode((string)($_POST['st'] ?? '')) . '&g=' . urlencode((string)($_POST['g'] ?? '')) . '&p=' . (int)($_POST['p'] ?? 1) . '#l' . $lin); exit;
        }
        if ($acao === 'marcar') {
            $n = $pdo->prepare("UPDATE bb_lanc l JOIN bb_conf_linhas c ON c.lanc_id = l.id SET l.conferido = 1, l.conferido_por = ?, l.conferido_em = ? WHERE c.conf_id = ? AND c.status = 'ok' AND l.conferido = 0");
            $n->execute([(int)$u['id'], date('Y-m-d H:i:s'), $id]);
            header('Location: /busca-bancos/conferencia.php?id=' . $id . '&marcados=' . $n->rowCount()); exit;
        }
        if ($acao === 'excluir') { $pdo->prepare('DELETE FROM bb_conf_linhas WHERE conf_id = ?')->execute([$id]); $pdo->prepare('DELETE FROM bb_conf WHERE id = ?')->execute([$id]); header('Location: /busca-bancos/conferencia.php'); exit; }
    }
} catch (RuntimeException $e) { $erro = $e->getMessage(); }

/* ---------- baixar o resultado em Excel ---------- */
if ($id && ($_GET['baixar'] ?? '') !== '') {
    require_once __DIR__ . '/../capa-financeira/lib/XlsxWriter.php';
    $st = $pdo->prepare('SELECT * FROM bb_conf WHERE id = ?'); $st->execute([$id]); $conf = $st->fetch(); if (!$conf) exit('não encontrada');
    $par = json_decode($conf['parametros'], true) ?: [];
    $st = $pdo->prepare('SELECT c.*, l.data AS l_data, l.valor AS l_valor, l.memo, l.contraparte, l.doc, ct.apelido FROM bb_conf_linhas c LEFT JOIN bb_lanc l ON l.id = c.lanc_id LEFT JOIN bb_contas ct ON ct.id = l.conta_id WHERE c.conf_id = ? ORDER BY c.linha'); $st->execute([$id]);
    $x = new XlsxWriter(); $s = $x->planilha('Conferência'); $cab = array_merge(['Linha'], array_map(fn($v) => $v !== '' ? $v : '—', $par['cabecalho'] ?? []), ['RESULTADO', 'Detalhe', 'Data no banco', 'Valor no banco', 'Conta', 'Quem (banco)', 'Descrição do banco']);
    foreach ($cab as $i => $t) $x->set($s, XlsxWriter::colNome($i + 1) . '1', $t, ['bold' => true, 'fill' => '#303682', 'color' => '#FFFFFF']);
    $cores = ['ok' => '#E6F4EC', 'ambigua' => '#FFF6DF', 'nao' => '#FDECEA', 'sem_dados' => '#EFF0F5', 'pend_achou' => '#FFE08A', 'pendente' => '#FFFFFF']; $n = 1; $nOrig = count($par['cabecalho'] ?? []);
    foreach ($st as $r) { $n++; $orig = json_decode($r['original'], true) ?: [];
        $x->set($s, 'A' . $n, (int)$r['linha']);
        for ($i = 0; $i < $nOrig; $i++) $x->set($s, XlsxWriter::colNome($i + 2) . $n, (string)($orig[$i] ?? ''));
        $b = $nOrig + 2; $fill = ['fill' => $cores[$r['status']] ?? '#FFFFFF'];
        $x->set($s, XlsxWriter::colNome($b) . $n, BbConferencia::STATUS[$r['status']] ?? $r['status'], $fill + ['bold' => true]); $x->set($s, XlsxWriter::colNome($b + 1) . $n, $r['motivo'], $fill);
        if ($r['lanc_id'] && $r['l_data']) { $x->set($s, XlsxWriter::colNome($b + 2) . $n, ['d' => $r['l_data']], ['fmt' => 'data']); $x->set($s, XlsxWriter::colNome($b + 3) . $n, (float)$r['l_valor'], ['fmt' => 'num']);
            $x->set($s, XlsxWriter::colNome($b + 4) . $n, (string)$r['apelido']); $x->set($s, XlsxWriter::colNome($b + 5) . $n, trim($r['contraparte'] . ' ' . BbTexto::docFmt((string)$r['doc']))); $x->set($s, XlsxWriter::colNome($b + 6) . $n, (string)$r['memo']); }
    }
    $x->congelar($s, 'A2'); $x->largura($s, XlsxWriter::colNome($nOrig + 2), 20); $x->largura($s, XlsxWriter::colNome($nOrig + 3), 46); $x->largura($s, XlsxWriter::colNome($nOrig + 8), 50);
    $tmp = tempnam($tmpDir, 'bb'); $x->salvar($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="conferencia-' . $id . '.xlsx"'); readfile($tmp); @unlink($tmp); exit;
}

$contas = BbCobertura::contas($pdo);
bb_cabecalho('conferencia', $u);
if ($erro) echo '<div class="bb-alerta erro">' . h($erro) . '</div>';

/* ---------- passo 2: dizer quais são as colunas ---------- */
$k = (string)($_GET['k'] ?? ''); $arq = $k !== '' && is_file($tmpDe($k)) ? json_decode((string)file_get_contents($tmpDe($k)), true) : null;
if ($arq && (int)$arq['u'] === (int)$u['id']):
    $m = $arq['m']; $g = BbConferencia::adivinhar($m); $cab = $m[$g['cab']]; $nc = max(array_map('count', array_slice($m, 0, 30)));
    $op = function (?int $sel, bool $vazio = true) use ($cab, $nc) { $o = $vazio ? '<option value="">— não tem —</option>' : ''; for ($c = 0; $c < $nc; $c++) $o .= '<option value="' . $c . '"' . ($sel === $c ? ' selected' : '') . '>' . XlsxWriterNome($c) . ' · ' . h(mb_substr($cab[$c] ?? '', 0, 30) ?: '(sem título)') . '</option>'; return $o; };
?>
<section class="bb-card">
  <h2>Confira se entendi a planilha "<?= h($arq['nome']) ?>"</h2>
  <p><?= count($m) ?> linhas lidas (só a primeira aba). Ajuste as colunas se o palpite estiver errado.</p>
  <form method="post" action="/busca-bancos/conferencia.php"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="conferir"><input type="hidden" name="k" value="<?= h($k) ?>">
  <div class="bb-linha-form">
    <label>Linha dos títulos <input class="bb-campo" type="number" name="cab_h" value="<?= $g['cab'] + 1 ?>" min="1" max="50" style="width:5em" oninput="this.form.cab.value=this.value-1"><input type="hidden" name="cab" value="<?= $g['cab'] ?>"></label>
    <label>Coluna da data <select class="bb-campo" name="data"><?= $op($g['data']) ?></select></label>
    <label>Coluna do valor <select class="bb-campo" name="valor"><?= $op($g['valor']) ?></select></label>
    <label>…ou coluna de entradas <select class="bb-campo" name="entrada"><?= $op($g['entrada']) ?></select></label>
    <label>e coluna de saídas <select class="bb-campo" name="saida"><?= $op($g['saida']) ?></select></label>
  </div>
  <div class="bb-linha-form" style="margin-top:10px">
    <label>Coluna "a receber" (previsto) <select class="bb-campo" name="receber"><?= $op($g['receber']) ?></select></label>
    <label>Coluna "a pagar" (previsto) <select class="bb-campo" name="pagar"><?= $op($g['pagar']) ?></select></label>
    <label>Coluna da venda / cliente (agrupa) <select class="bb-campo" name="grupo"><?= $op($g['grupo']) ?></select></label>
    <label>Coluna do banco (só informativa) <select class="bb-campo" name="banco"><?= $op($g['banco']) ?></select></label>
  </div>
  <?php if ($g['grupo'] !== null): ?><div class="bb-nota" style="margin-top:12px"><label class="bb-chk"><input type="checkbox" name="escopo" value="1" checked> Conferir só as vendas que têm lançamentos de <input class="bb-campo" type="number" name="ano" value="<?= date('Y') ?>" min="2015" max="2100" style="width:6em;padding:3px 6px"> em diante ou que têm algo pendente (a receber / a pagar sem baixa)</label>
    O banco anotado na planilha não é usado para procurar (pode estar errado): cada linha é procurada em todas as contas marcadas abaixo. Linhas pendentes com data já vencida também são procuradas — se aparecerem no banco, você fica sabendo.</div><?php endif; ?>
  <div class="bb-filtros"><fieldset><legend>Colunas com nome / descrição (para desempatar)</legend>
    <?php for ($c = 0; $c < $nc; $c++): if (($cab[$c] ?? '') === '') continue; ?><label class="bb-pill"><input type="checkbox" name="texto[]" value="<?= $c ?>"<?= in_array($c, $g['texto'], true) ? ' checked' : '' ?>><span><?= h(mb_substr($cab[$c], 0, 28)) ?></span></label><?php endfor; ?></fieldset></div>
  <div class="bb-linha-form" style="margin-top:12px">
    <label>A coluna de valor traz <select class="bb-campo" name="sinal"><option value="auto">entradas e saídas (negativo = saída)</option><option value="entradas">só entradas</option><option value="saidas">só saídas</option><option value="qualquer">não sei — aceitar os dois</option></select></label>
    <label>Tolerância na data (± dias) <input class="bb-campo" type="number" name="told" value="3" min="0" max="90" style="width:5em"></label>
    <label>Tolerância no valor (± R$) <input class="bb-campo" type="text" name="tolv" value="0" size="6"></label>
  </div>
  <div class="bb-filtros"><fieldset><legend>Procurar em quais contas <small>(nenhuma marcada = todas)</small></legend>
    <?php foreach ($contas as $cid => $c): ?><label class="bb-pill"><input type="checkbox" name="conta[]" value="<?= $cid ?>"><span><?= bb_banco($c['banco']) ?> <?= h($c['apelido']) ?></span></label><?php endforeach; ?></fieldset></div>
  <h3 style="font-size:13px;margin:16px 0 6px">Primeiras linhas</h3>
  <div class="bb-tab-wrap"><table class="bb-tab"><thead><tr><th>#</th><?php for ($c = 0; $c < $nc; $c++): ?><th><?= XlsxWriterNome($c) ?></th><?php endfor; ?></tr></thead><tbody>
    <?php foreach (array_slice($m, 0, min(count($m), $g['cab'] + 7), true) as $i => $lin): ?><tr<?= $i === $g['cab'] ? ' style="font-weight:700;background:#eceef8"' : '' ?>><td><?= $i + 1 ?></td><?php for ($c = 0; $c < $nc; $c++): ?><td><?= h(mb_substr($lin[$c] ?? '', 0, 40)) ?></td><?php endfor; ?></tr><?php endforeach; ?></tbody></table></div>
  <div class="bb-det-acoes"><button class="bb-btn" type="submit">Conferir contra os extratos</button> <a class="bb-btn sec" href="/busca-bancos/conferencia.php">Cancelar</a></div>
  </form>
</section>
<?php bb_rodape(); return; endif;

/* ---------- resultado de uma conferência ---------- */
if ($id):
    $st = $pdo->prepare('SELECT c.*, us.nome AS por FROM bb_conf c LEFT JOIN users us ON us.id = c.criado_por WHERE c.id = ?'); $st->execute([$id]); $conf = $st->fetch();
    if (!$conf) { echo '<div class="bb-alerta erro">Conferência não encontrada.</div>'; bb_rodape(); return; }
    $par = json_decode($conf['parametros'], true) ?: [];
    $gSel = trim((string)($_GET['g'] ?? '')); $wG = $gSel !== '' ? ' AND grupo = ?' : ''; $aG = $gSel !== '' ? [$gSel] : [];
    $cont = []; $st = $pdo->prepare('SELECT status, COUNT(*) n, SUM(ABS(valor)) v FROM bb_conf_linhas WHERE conf_id = ?' . $wG . ' GROUP BY status'); $st->execute(array_merge([$id], $aG)); foreach ($st as $r) $cont[$r['status']] = $r;
    $total = array_sum(array_column($cont, 'n')); $stSel = array_key_exists($_GET['st'] ?? '', BbConferencia::STATUS) ? (string)$_GET['st'] : '';
    $porPag = 300; $pg = max(1, (int)($_GET['p'] ?? 1)); $nSel = $stSel ? (int)($cont[$stSel]['n'] ?? 0) : $total; $pags = max(1, (int)ceil($nSel / $porPag));
    $sql = 'SELECT c.*, l.data AS l_data, l.valor AS l_valor, l.memo, l.contraparte, ct.apelido, ct.banco FROM bb_conf_linhas c LEFT JOIN bb_lanc l ON l.id = c.lanc_id LEFT JOIN bb_contas ct ON ct.id = l.conta_id WHERE c.conf_id = ?' . ($stSel ? ' AND c.status = ?' : '') . ($gSel !== '' ? ' AND c.grupo = ?' : '') . ' ORDER BY c.grupo, c.data, c.linha LIMIT ' . $porPag . ' OFFSET ' . (($pg - 1) * $porPag);
    $st = $pdo->prepare($sql); $st->execute(array_merge([$id], $stSel ? [$stSel] : [], $aG)); $linhas = $st->fetchAll();
    $idsC = []; foreach ($linhas as $l) if (in_array($l['status'], ['ambigua', 'pendente'], true)) foreach (json_decode((string)$l['candidatos'], true) ?: [] as $x) $idsC[(int)$x] = 1;
    $cands = []; if ($idsC) foreach ($pdo->query('SELECT l.id, l.data, l.valor, l.memo, l.contraparte, ct.apelido, ct.banco FROM bb_lanc l JOIN bb_contas ct ON ct.id = l.conta_id WHERE l.id IN (' . implode(',', array_keys($idsC)) . ')') as $r) $cands[(int)$r['id']] = $r;
    $classe = ['ok' => 'ok', 'ambigua' => 'amb', 'nao' => 'nao', 'sem_dados' => '', 'pend_achou' => 'amb', 'pendente' => ''];
    // resumo por venda: onde estão os problemas
    $st = $pdo->prepare("SELECT grupo, COUNT(*) n, SUM(status = 'ok') ok, SUM(status = 'nao') nao, SUM(status = 'ambigua') amb, SUM(status = 'sem_dados') sem, SUM(status = 'pend_achou') pa, SUM(status = 'pendente') pe, SUM(CASE WHEN status = 'nao' THEN ABS(valor) ELSE 0 END) v_nao
        FROM bb_conf_linhas WHERE conf_id = ? AND grupo <> '' GROUP BY grupo ORDER BY (SUM(status = 'nao') + SUM(status = 'pend_achou') + SUM(status = 'ambigua')) DESC, grupo LIMIT 400"); $st->execute([$id]); $vendas = $st->fetchAll();
    $lk = fn(array $t = []) => '?' . http_build_query(array_filter(array_merge(['id' => $id, 'st' => $stSel, 'g' => $gSel], $t), fn($v) => $v !== '' && $v !== null));
?>
<section class="bb-card">
  <h2><?= h($conf['nome']) ?></h2>
  <?php if ($gSel !== ''): ?><div class="bb-alerta info">Mostrando só a venda <b><?= h($gSel) ?></b>. <a href="<?= h($lk(['g' => ''])) ?>">Ver todas as vendas</a></div><?php endif; ?>
  <p><?= ($par['escopo'] ?? '') === 'vendas' ? 'Só vendas com lançamentos de ' . (int)$par['ano'] . ' em diante ou com pendências. ' : '' ?>Conferida em <?= h(date('d/m/Y H:i', strtotime($conf['criado_em']))) ?><?= $conf['por'] ? ' por ' . h($conf['por']) : '' ?> · tolerância: ± <?= (int)($par['told'] ?? 0) ?> dia(s), ± <?= BbTexto::brl((float)($par['tolv'] ?? 0)) ?> · contas: <?= h(implode(', ', $par['contas'] ?? [])) ?></p>
  <?php if (isset($_GET['marcados'])): ?><div class="bb-alerta ok"><?= (int)$_GET['marcados'] ?> lançamento(s) marcados como conferidos na busca.</div><?php endif; ?>
  <div class="bb-totais">
    <a class="bb-btn sec" href="<?= h($lk(['st' => ''])) ?>" style="align-self:center">Todas (<?= $total ?>)</a>
    <?php foreach (BbConferencia::STATUS as $k2 => $t): $c = $cont[$k2] ?? ['n' => 0, 'v' => 0]; ?>
      <div><small><?= h($t) ?></small><b><a href="<?= h($lk(['st' => $k2])) ?>" style="text-decoration:none"><?= (int)$c['n'] ?></a></b> <span class="bb-memo"><?= BbTexto::brl((float)$c['v']) ?></span></div>
    <?php endforeach; ?>
    <span class="bb-totais-acoes"><a class="bb-btn" href="?id=<?= $id ?>&baixar=1">Baixar resultado em Excel</a></span>
  </div>
  <div class="bb-det-acoes">
    <form method="post" action="?id=<?= $id ?>"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="marcar"><button class="bb-btn sec" type="submit">Marcar os encontrados como conferidos na busca</button></form>
    <form method="post" action="?id=<?= $id ?>" onsubmit="return confirm('Excluir esta conferência? Os extratos não são afetados.')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="excluir"><button class="bb-btn perigo" type="submit">Excluir conferência</button></form>
  </div>
</section>
<?php if ($vendas && $gSel === ''): ?><details class="bb-card"<?= !$stSel && $pg === 1 ? ' open' : '' ?>><summary style="cursor:pointer;font-weight:700;color:#303682">Resumo por venda (<?= count($vendas) ?>) — as com mais problemas primeiro</summary>
  <div class="bb-tab-wrap" style="margin-top:10px;max-height:420px;overflow:auto"><table class="bb-tab"><thead><tr><th>Venda / cliente</th><th class="num">Linhas</th><th class="num">Encontradas</th><th class="num">Não encontradas</th><th class="num">Valor não encontrado</th><th class="num">Várias possíveis</th><th class="num">Pendente que está no banco</th><th class="num">Sem extrato</th><th class="num">Pendentes</th></tr></thead><tbody>
  <?php foreach ($vendas as $v): ?><tr><td><a href="<?= h($lk(['g' => $v['grupo'], 'st' => ''])) ?>"><?= h($v['grupo']) ?></a></td><td class="num"><?= (int)$v['n'] ?></td><td class="num ent"><?= (int)$v['ok'] ?></td><td class="num sai"><?= (int)$v['nao'] ?: '' ?></td><td class="num sai"><?= (float)$v['v_nao'] ? BbTexto::brl((float)$v['v_nao']) : '' ?></td><td class="num"><?= (int)$v['amb'] ?: '' ?></td><td class="num"><?= (int)$v['pa'] ?: '' ?></td><td class="num"><?= (int)$v['sem'] ?: '' ?></td><td class="num"><?= (int)$v['pe'] ?: '' ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
<div class="bb-tab-wrap"><table class="bb-tab">
  <thead><tr><th>Linha</th><th>Data</th><th class="num">Valor</th><th>Na planilha</th><th>Resultado</th><th>No extrato do banco</th></tr></thead><tbody>
  <?php foreach ($linhas as $l): ?>
    <tr id="l<?= (int)$l['id'] ?>"><td class="mono"><?= (int)$l['linha'] ?></td><td class="mono"><?= BbTexto::dataBr($l['data']) ?: '—' ?></td><td class="num"><?= BbTexto::brl((float)$l['valor']) ?></td><td><?php if ($l['grupo'] !== ''): ?><a href="<?= h($lk(['g' => $l['grupo'], 'st' => ''])) ?>"><b><?= h($l['grupo']) ?></b></a><br><?php endif; ?><span class="bb-memo"><?= h(mb_substr($l['texto'], 0, 170)) ?></span><?php if ($l['banco_planilha'] !== ''): ?><br><small>banco na planilha: <?= h($l['banco_planilha']) ?></small><?php endif; ?><?= $l['previsto'] ? ' <span class="bb-tag">previsto</span>' : '' ?></td>
      <td><span class="bb-tag <?= $classe[$l['status']] ?>"><?= h(BbConferencia::STATUS[$l['status']]) ?></span><br><small><?= h($l['motivo']) ?></small></td>
      <td><?php if ($l['lanc_id'] && $l['l_data']): ?><?= bb_banco((string)$l['banco']) ?> <span class="bb-conta"><?= h($l['apelido']) ?></span> · <?= BbTexto::dataBr($l['l_data']) ?> · <b class="<?= (float)$l['l_valor'] >= 0 ? 'ent' : 'sai' ?>"><?= BbTexto::brl((float)$l['l_valor']) ?></b><br><b><?= h($l['contraparte']) ?></b> <span class="bb-memo"><?= h($l['memo']) ?></span>
        <?php elseif (in_array($l['status'], ['ambigua', 'pendente'], true) && (json_decode((string)$l['candidatos'], true) ?: [])): foreach (json_decode((string)$l['candidatos'], true) ?: [] as $cx): if (!isset($cands[$cx])) continue; $c = $cands[$cx]; ?>
          <form method="post" action="?id=<?= $id ?>" style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="escolher"><input type="hidden" name="st" value="<?= h($stSel) ?>"><input type="hidden" name="g" value="<?= h($gSel) ?>"><input type="hidden" name="p" value="<?= $pg ?>"><input type="hidden" name="linha_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="lanc_id" value="<?= (int)$cx ?>">
            <button class="bb-btn sec" type="submit" style="padding:3px 9px">É este</button><span><?= bb_banco($c['banco']) ?> <?= BbTexto::dataBr($c['data']) ?> · <b><?= BbTexto::brl((float)$c['valor']) ?></b> · <b><?= h($c['contraparte']) ?></b> <span class="bb-memo"><?= h(mb_substr($c['memo'], 0, 70)) ?> (<?= h($c['apelido']) ?>)</span></span></form>
        <?php endforeach; ?>
          <form method="post" action="?id=<?= $id ?>"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="escolher"><input type="hidden" name="st" value="<?= h($stSel) ?>"><input type="hidden" name="g" value="<?= h($gSel) ?>"><input type="hidden" name="p" value="<?= $pg ?>"><input type="hidden" name="linha_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="lanc_id" value="0"><button class="bb-btn perigo" type="submit" style="padding:3px 9px">Nenhum destes</button></form>
        <?php else: ?><a href="/busca-bancos/?q=<?= urlencode(number_format(abs((float)$l['valor']), 2, ',', '.')) ?>&tolv=5">procurar este valor na busca</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php if ($pags > 1): ?><nav class="bb-pag">Página <?= $pg ?> de <?= $pags ?> <?php if ($pg > 1): ?><a class="bb-btn sec" href="<?= h($lk(['p' => $pg - 1])) ?>">← Anterior</a><?php endif; ?><?php if ($pg < $pags): ?><a class="bb-btn sec" href="<?= h($lk(['p' => $pg + 1])) ?>">Próxima →</a><?php endif; ?></nav><?php endif; ?>
<?php bb_rodape(); return; endif;

/* ---------- início: enviar planilha + conferências anteriores ---------- */
$ant = $pdo->query("SELECT c.*, us.nome AS por, (SELECT COUNT(*) FROM bb_conf_linhas l WHERE l.conf_id = c.id) n, (SELECT COUNT(*) FROM bb_conf_linhas l WHERE l.conf_id = c.id AND l.status = 'ok') n_ok FROM bb_conf c LEFT JOIN users us ON us.id = c.criado_por ORDER BY c.id DESC LIMIT 50")->fetchAll();
?>
<section class="bb-card">
  <h2>Conferir uma planilha contra os extratos</h2>
  <p>Envie a planilha onde as entradas e saídas eram registradas. Cada linha é procurada nos extratos pelo valor, pela data (com tolerância) e pelo nome. Você recebe: encontrada, várias possíveis, não encontrada — e um aviso quando a data cai num período sem extrato.</p>
  <p><b>Para o Livro Diário do Google Planilhas:</b> abra a aba "LIVRO DIARIO" e use Arquivo → Fazer download → <b>Valores separados por vírgula (.csv)</b>. O .csv tem só essa aba e é lido muito mais rápido que o .xlsx da pasta inteira.</p>
  <?php if (!$contas): ?><div class="bb-alerta">Importe os extratos antes de conferir.</div><?php else: ?>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="subir">
    <div class="bb-solta"><b>Arraste a planilha (.xlsx ou .csv)</b> ou escolha:<input type="file" name="planilha" accept=".xlsx,.csv" required><span data-nomes></span></div>
    <div class="bb-det-acoes"><button class="bb-btn" type="submit">Enviar e escolher as colunas</button></div></form><?php endif; ?>
</section>
<?php if ($ant): ?><section class="bb-card"><h2>Conferências anteriores</h2>
  <div class="bb-tab-wrap"><table class="bb-tab"><thead><tr><th>Planilha</th><th>Quando</th><th>Por</th><th class="num">Linhas</th><th class="num">Encontradas</th></tr></thead><tbody>
  <?php foreach ($ant as $a): ?><tr><td><a href="?id=<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></a></td><td class="mono"><?= h(date('d/m/Y H:i', strtotime($a['criado_em']))) ?></td><td><?= h($a['por'] ?? '') ?></td><td class="num"><?= (int)$a['n'] ?></td><td class="num"><?= (int)$a['n_ok'] ?> (<?= $a['n'] ? round(100 * $a['n_ok'] / $a['n']) : 0 ?>%)</td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php bb_rodape();

function XlsxWriterNome(int $c): string { $s = ''; for ($n = $c + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; }
