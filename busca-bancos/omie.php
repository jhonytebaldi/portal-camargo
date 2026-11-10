<?php
/* busca-bancos/omie.php — o que está no banco e não está no Omie, e o contrário. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/Conferencia.php';
require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';
$u = require_tool('busca-bancos');
$pdo = db();
$contas = array_filter(BbCobertura::contas($pdo), fn($c) => !empty($c['omie_cc']) && $c['empresa'] !== '');
$apis = OmieApi::contas();
$cid = (int)($_GET['conta'] ?? 0); $dt = fn($k, $pad) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : $pad;
$de = $dt('de', date('Y-m-01', strtotime('first day of last month'))); $ate = $dt('ate', date('Y-m-t', strtotime('first day of last month')));
$told = max(0, min(15, (int)($_GET['told'] ?? 2))); $previstos = !empty($_GET['previstos']);
$res = null; $erro = ''; $avisos = [];

if ($cid && isset($contas[$cid])) {
    $c = $contas[$cid];
    try {
        if (!isset($apis[$c['empresa']])) throw new RuntimeException('A API do Omie da ' . bb_empresa($c['empresa']) . ' não está configurada no portal.');
        if ((strtotime($ate) - strtotime($de)) / 86400 > 93) throw new RuntimeException('Escolha um período de até 3 meses (limite do Omie).');
        $ex = BbOmie::extrato($c['empresa'], (string)$c['omie_cc'], $de, $ate);
        $omie = $previstos ? $ex['mov'] : array_values(array_filter($ex['mov'], fn($m) => !preg_match('/previs|a vencer|atrasad|vence hoje/i', $m['situacao'])));
        $st = $pdo->prepare('SELECT l.id, l.data, l.valor, l.memo, l.contraparte, l.doc FROM bb_lanc l WHERE l.conta_id = ? AND l.data BETWEEN ? AND ? AND l.anulada = 0 ORDER BY l.data, l.id'); $st->execute([$cid, $de, $ate]);
        $res = BbOmie::cruzar($st->fetchAll(), $omie, $told) + ['conta_omie' => $ex['conta'], 'n_omie' => count($omie)];
        $avisos = BbCobertura::avisos($pdo, [$cid => $c], $de, $ate);
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$soma = fn(array $ls) => array_sum(array_map(fn($x) => (float)$x['valor'], $ls));

if ($res && ($_GET['baixar'] ?? '') !== '') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="banco-x-omie-' . $de . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Situação', 'Data', 'Valor', 'Descrição', 'CPF/CNPJ', 'Detalhe'], ';');
    foreach ($res['so_banco'] as $b) fputcsv($o, ['Só no banco (falta lançar no Omie)', BbTexto::dataBr($b['data']), number_format((float)$b['valor'], 2, ',', ''), trim($b['contraparte'] . ' ' . $b['memo']), BbTexto::docFmt($b['doc']), ''], ';');
    foreach ($res['so_omie'] as $m) fputcsv($o, ['Só no Omie (não está no banco)', BbTexto::dataBr($m['data']), number_format($m['valor'], 2, ',', ''), $m['texto'], BbTexto::docFmt($m['doc']), $m['situacao'] . ' · ' . $m['categoria']], ';');
    foreach ($res['pares'] as [$b, $m]) fputcsv($o, ['Nos dois', BbTexto::dataBr($b['data']), number_format((float)$b['valor'], 2, ',', ''), trim($b['contraparte'] . ' ' . $b['memo']), BbTexto::docFmt($b['doc']), 'Omie: ' . BbTexto::dataBr($m['data']) . ' ' . $m['texto']], ';');
    exit;
}
bb_cabecalho('omie', $u);
?>
<section class="bb-card">
  <h2>Cruzar o extrato do banco com o Omie</h2>
  <p>Compara, por valor e data, o que entrou e saiu na conta com o extrato dessa mesma conta no Omie. Serve para achar o que está no banco e ainda não foi lançado no Omie, e o que está no Omie sem ter acontecido no banco.</p>
  <?php if (!$apis): ?><div class="bb-alerta">A API do Omie não está configurada no portal (OMIE_CONTAS). Sem ela não dá para cruzar.</div>
  <?php elseif (!$contas): ?><div class="bb-alerta">Nenhuma conta está ligada ao Omie ainda. Em <a href="/busca-bancos/contas.php?omie=1">Contas</a>, escolha a conta corrente do Omie de cada conta bancária.</div>
  <?php else: ?>
  <form method="get" class="bb-linha-form">
    <label>Conta <select class="bb-campo" name="conta" required><option value="">— escolher —</option><?php foreach ($contas as $id => $c): ?><option value="<?= $id ?>"<?= $cid === $id ? ' selected' : '' ?>><?= h($c['apelido'] . ' · ' . bb_empresa($c['empresa'])) ?></option><?php endforeach; ?></select></label>
    <label>De <input class="bb-campo" type="date" name="de" value="<?= h($de) ?>"></label><label>Até <input class="bb-campo" type="date" name="ate" value="<?= h($ate) ?>"></label>
    <label>Tolerância na data (± dias) <input class="bb-campo" type="number" name="told" value="<?= $told ?>" min="0" max="15" style="width:5em"></label>
    <label class="bb-chk" style="flex-direction:row"><input type="checkbox" name="previstos" value="1"<?= $previstos ? ' checked' : '' ?>> Incluir lançamentos previstos do Omie</label>
    <button class="bb-btn" type="submit">Cruzar</button>
  </form><?php endif; ?>
</section>
<?php if ($erro): ?><div class="bb-alerta erro"><?= h($erro) ?></div><?php endif; ?>
<?php foreach ($avisos as $av): ?><div class="bb-alerta">Atenção: faltam extratos desta conta no período (<?= $av['tudo'] ? 'período inteiro' : h(implode('; ', array_map(fn($x) => BbTexto::dataBr($x[0]) . ' a ' . BbTexto::dataBr($x[1]), $av['faltas']))) ?>). O que está "só no Omie" nesses dias pode simplesmente não ter sido importado.</div><?php endforeach; ?>
<?php if ($res): ?>
  <div class="bb-totais">
    <div><small>Nos dois</small><b><?= count($res['pares']) ?></b></div>
    <div class="sai"><small>Só no banco (falta no Omie)</small><b><?= count($res['so_banco']) ?></b> <span class="bb-memo"><?= BbTexto::brl($soma($res['so_banco'])) ?></span></div>
    <div class="sai"><small>Só no Omie (não está no banco)</small><b><?= count($res['so_omie']) ?></b> <span class="bb-memo"><?= BbTexto::brl($soma($res['so_omie'])) ?></span></div>
    <span class="bb-totais-acoes"><a class="bb-btn sec" href="?<?= h(http_build_query($_GET + ['baixar' => 1])) ?>">Baixar CSV</a></span>
  </div>
  <p class="bb-nota">Conta no Omie: <b><?= h($res['conta_omie']) ?></b> · <?= $res['n_omie'] ?> movimento(s) no período.</p>
  <section class="bb-card"><h2>Só no banco — falta lançar no Omie (<?= count($res['so_banco']) ?>)</h2>
    <?php if (!$res['so_banco']): ?><p>Nada: tudo que está no extrato do banco tem par no Omie.</p><?php else: ?><div class="bb-tab-wrap"><table class="bb-tab"><thead><tr><th>Data</th><th>Quem / descrição</th><th>CPF/CNPJ</th><th class="num">Valor</th></tr></thead><tbody>
    <?php foreach ($res['so_banco'] as $b): ?><tr><td class="mono"><?= BbTexto::dataBr($b['data']) ?></td><td><b><?= h($b['contraparte']) ?></b> <span class="bb-memo"><?= h($b['memo']) ?></span></td><td class="mono"><?= h(BbTexto::docFmt($b['doc'])) ?></td><td class="num <?= (float)$b['valor'] >= 0 ? 'ent' : 'sai' ?>"><?= BbTexto::brl((float)$b['valor']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <section class="bb-card"><h2>Só no Omie — não achei no banco (<?= count($res['so_omie']) ?>)</h2>
    <?php if (!$res['so_omie']): ?><p>Nada: tudo que está no Omie aparece no extrato do banco.</p><?php else: ?><div class="bb-tab-wrap"><table class="bb-tab"><thead><tr><th>Data</th><th>Cliente / fornecedor</th><th>Categoria</th><th>Situação</th><th class="num">Valor</th></tr></thead><tbody>
    <?php foreach ($res['so_omie'] as $m): ?><tr><td class="mono"><?= BbTexto::dataBr($m['data']) ?></td><td><?= h($m['texto']) ?> <span class="bb-memo"><?= h(BbTexto::docFmt($m['doc'])) ?></span></td><td><?= h($m['categoria']) ?></td><td><?= h($m['situacao']) ?></td><td class="num <?= $m['valor'] >= 0 ? 'ent' : 'sai' ?>"><?= BbTexto::brl($m['valor']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?>
<?php bb_rodape();
