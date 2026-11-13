<?php
/* busca-bancos/cobertura.php — de quando até quando temos extrato de cada conta, mês a mês. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('busca-bancos');
$pdo = db();
$contas = BbCobertura::contas($pdo);
$g = BbCobertura::grade($pdo, $contas);
$int = BbCobertura::intervalos($pdo);
$saldos = BbCobertura::saldos($pdo);
$cap = BbCobertura::capacidades($pdo);
$mesNome = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];
$rot = ['cheio' => 'mês completo', 'parcial' => 'faltam dias', 'falta' => 'mês faltando entre dois extratos', 'fora' => 'sem extrato'];
bb_cabecalho('cobertura', $u);
if (!$contas) { echo '<div class="bb-vazio"><h2>Nenhum extrato importado ainda</h2><a class="bb-btn" href="/busca-bancos/importar.php">Importar extratos</a></div>'; bb_rodape(); return; }
?>
<section class="bb-card">
  <h2>O que temos de cada conta, mês a mês</h2>
  <p style="float:right;margin:-34px 0 0;display:flex;gap:6px">
    <a class="bb-btn sec" href="/busca-bancos/exportar-ofx.php?so=originais" title="Só os arquivos .ofx enviados, sem nenhuma mudança, em pastas ANO / MÊS com o nome da empresa">⬇ Baixar originais (.zip)</a>
    <a class="bb-btn sec" href="/busca-bancos/exportar-ofx.php" title="Um .ofx por conta e por mês: o original quando houver; senão recortado do original ou recriado dos lançamentos importados">⬇ Baixar completo (.zip)</a></p>
  <p>O número é a quantidade de lançamentos no mês. Passe o mouse num mês amarelo para ver os dias que faltam.</p>
  <div style="overflow-x:auto"><table class="bb-grade">
    <thead><tr><th class="c">Conta</th><?php foreach ($g['meses'] as $m): ?><th><?= $mesNome[substr($m, 5)] ?><br><?= substr($m, 2, 2) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($contas as $cid => $c): ?>
      <tr><th class="c"><?= bb_banco($c['banco']) ?> <?= h($c['apelido']) ?> <small style="font-weight:400;color:#5d6278">· <?= h(bb_empresa($c['empresa'])) ?></small></th>
      <?php foreach ($g['meses'] as $m): $x = $g['linhas'][$cid][$m]; ?>
        <td class="<?= $x['st'] ?>" title="<?= h($mesNome[substr($m, 5)] . '/' . substr($m, 0, 4) . ': ' . $rot[$x['st']] . ($x['st'] === 'parcial' ? ' (' . $x['dias'] . ')' : '') . ' · ' . $x['n'] . ' lançamento(s)') ?>"><?= $x['st'] === 'fora' || $x['st'] === 'falta' ? '—' : $x['n'] ?><span class="sr" style="position:absolute;left:-9999px"> <?= $rot[$x['st']] ?></span></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  <div class="bb-legenda"><span><i style="background:#bfe3cd"></i>mês completo</span><span><i style="background:#ffe08a"></i>faltam dias</span><span><i style="background:#f3b1a7"></i>mês faltando entre dois extratos</span><span><i style="background:#eff0f5"></i>sem extrato (antes do primeiro ou depois do último)</span></div>
</section>

<section class="bb-card">
  <h2>Períodos exatos e conferência pelo saldo</h2>
  <p>O extrato traz o saldo final. Quando dois extratos da mesma conta encostam um no outro, conferimos: saldo anterior + lançamentos = saldo informado. Se não fechar, pode haver lançamento faltando ou sobrando.</p>
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Conta</th><th>Empresa</th><th>Temos de… até…</th><th>Buracos</th><th>Conferência pelo saldo</th></tr></thead><tbody>
    <?php foreach ($contas as $cid => $c): $i = $int[$cid] ?? []; $ss = $saldos[$cid] ?? []; $ruins = array_filter($ss, fn($s) => abs($s['dif']) >= 0.01); ?>
      <tr><td><?= bb_banco($c['banco']) ?> <b><?= h($c['apelido']) ?></b></td><td><?= h(bb_empresa($c['empresa'])) ?></td>
        <td class="mono"><?= $i ? implode('<br>', array_map(fn($x) => BbTexto::dataBr($x[0]) . ' → ' . BbTexto::dataBr($x[1]), $i)) : '—' ?></td>
        <td><?php if (count($i) > 1): $bur = BbCobertura::buracos($i, $i[0][0], end($i)[1]); ?><span class="bb-tag amb"><?= count($bur) ?> buraco(s)</span><br><small><?= h(implode('; ', array_map(fn($x) => BbTexto::dataBr($x[0]) . ' a ' . BbTexto::dataBr($x[1]), $bur))) ?></small><?php elseif ($i): ?><span class="bb-tag ok">contínuo</span><?php endif; ?>
            <?php if ($i && end($i)[1] < date('Y-m-d', strtotime('-35 days'))): ?><br><small>último extrato há <?= (int)((time() - strtotime(end($i)[1])) / 86400) ?> dias</small><?php endif; ?></td>
        <td><?php if (!$ss): ?><small>sem dois extratos encostados para comparar</small>
            <?php elseif (!$ruins): ?><span class="bb-tag ok">fecha em <?= count($ss) ?> comparação(ões)</span>
            <?php else: foreach ($ruins as $s): ?><span class="bb-tag nao">não fecha</span> <small><?= BbTexto::dataBr($s['de']) ?> a <?= BbTexto::dataBr($s['ate']) ?>: esperado <?= BbTexto::brl($s['esperado']) ?>, extrato diz <?= BbTexto::brl($s['informado']) ?> (diferença <?= BbTexto::brl($s['dif']) ?>)</small><br><?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>

<section class="bb-card">
  <h2>O que cada conta informa no extrato</h2>
  <p>Calculado sobre os lançamentos já importados (sem contar tarifas). Nenhum banco informa a chave Pix no extrato.</p>
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Conta</th><th class="num">Lançamentos</th><th class="num">Com nome</th><th class="num">Com CPF/CNPJ</th><th class="num">Com nº de documento</th><th>Como buscar</th></tr></thead><tbody>
    <?php foreach ($contas as $cid => $c): $k = $cap[$cid] ?? null; ?>
      <tr><td><?= bb_banco($c['banco']) ?> <b><?= h($c['apelido']) ?></b></td>
      <?php if (!$k): ?><td colspan="5"><small>sem lançamentos</small></td>
      <?php else: ?><td class="num"><?= $k['n'] ?></td><td class="num"><?= $k['nome'] ?>%</td><td class="num"><?= $k['doc'] ?>%</td><td class="num"><?= $k['ref'] ?>%</td>
        <td><small><?= $k['doc'] >= 50 ? 'CPF/CNPJ, nome ou valor' : ($k['nome'] >= 50 ? 'nome' . ($k['ref'] >= 30 ? ', nº da cobrança' : '') . ' ou valor (CPF/CNPJ quase nunca vem)' : 'valor e data (quase não traz nome)') ?></small></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
</section>
<?php bb_rodape();
