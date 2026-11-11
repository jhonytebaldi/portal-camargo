<?php
/* =====================================================================
   busca-bancos/index.php — busca unificada nos extratos de todas as contas.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('busca-bancos');
$pdo = db();
$contas = BbCobertura::contas($pdo);
$f = bb_filtros();
$porPagina = 100; $pag = max(1, (int)($_GET['p'] ?? 1));

// filtros em uso (para o botão "Limpar filtros" e o resumo embaixo da caixa)
$filtrosAtivos = array_values(array_filter([
    $f['per'] !== 'tudo' ? 'período' : null, $f['tipo'] !== '' ? ($f['tipo'] === 'E' ? 'só entradas' : 'só saídas') : null,
    $f['empresas'] ? 'empresa' : null, $f['contas'] ? count($f['contas']) . ' conta(s)' : null,
    $f['tolv'] ? 'tolerância de valor' : null, $f['told'] ? 'tolerância de data' : null, ($f['vmin'] !== '' || $f['vmax'] !== '') ? 'faixa de valor' : null,
    $f['origem'] !== '' ? 'origem' : null, $f['ocultar_internas'] ? 'ocultando transferências internas' : null, $f['ocultar_anuladas'] ? 'ocultando estornos/tarifas anuladas' : null,
    $f['pendentes'] ? 'só não conferidos' : null, $f['parcial'] ? 'nome parecido' : null, $f['agrupar'] ? 'agrupado por pessoa' : null,
]));
$temFiltro = $f['q'] !== '' || $filtrosAtivos;
$qs = function (array $troca = []) { $g = array_merge($_GET, $troca); unset($g['p']); foreach ($g as $k => $v) if ($v === null || $v === '') unset($g[$k]); return '?' . http_build_query($g); };

$m = BbBusca::montar($f);
$tot = BbBusca::contar($pdo, $m['where'], $m['args']);
$parcialAuto = false;
if (!$tot['n'] && !$f['parcial'] && count($m['interp']['palavras']) > 1) {
    $m2 = BbBusca::montar(['parcial' => 1] + $f); $t2 = BbBusca::contar($pdo, $m2['where'], $m2['args']);
    if ($t2['n']) { $m = $m2; $tot = $t2; $parcialAuto = true; }
}
$ocultos = $m['ocultos'] ? BbBusca::contar($pdo, $m['ocultos'][0], $m['ocultos'][1])['n'] : 0;
$linhas = $f['agrupar'] ? [] : BbBusca::linhas($pdo, $m, $porPagina, ($pag - 1) * $porPagina);
$grupos = $f['agrupar'] ? BbBusca::agrupar($pdo, $m) : [];

// contas dentro do filtro (para os avisos de cobertura e do que cada banco informa)
$noFiltro = array_filter($contas, fn($c) => (!$f['contas'] || in_array((int)$c['id'], $f['contas'], true)) && (!$f['empresas'] || in_array($c['empresa'], $f['empresas'], true)));
$dePer = $f['de']; $atePer = $f['ate'];
if ($m['interp']['datas']) { $dePer = min($m['interp']['datas']); $atePer = max($m['interp']['datas']); }
$avisos = BbCobertura::avisos($pdo, $noFiltro, $dePer ?: null, $atePer ?: null);
$int = BbCobertura::intervalos($pdo);
$silencios = BbCobertura::silenciosNoPeriodo(BbCobertura::silencios($pdo, $int), $noFiltro, $dePer ?: null, $atePer ?: null);
$cap = BbCobertura::capacidades($pdo);
$semDoc = []; $semNome = [];
foreach ($noFiltro as $cid => $c) {
    if (($cap[$cid]['n'] ?? 0) < 5) continue;
    if ($cap[$cid]['doc'] < 3) $semDoc[$c['banco']] = BbOfx::BANCOS[$c['banco']] ?? $c['banco'];
    if ($cap[$cid]['nome'] < 3) $semNome[$c['banco']] = BbOfx::BANCOS[$c['banco']] ?? $c['banco'];
}
$restrito = $f['contas'] && count($noFiltro) < count($contas);
$ignorar = !empty($_GET['ig']);
$fmtFaltas = fn(array $fs) => implode('; ', array_map(fn($x) => $x[0] === $x[1] ? BbTexto::dataBr($x[0]) : BbTexto::dataBr($x[0]) . ' a ' . BbTexto::dataBr($x[1]), array_slice($fs, 0, 4))) . (count($fs) > 4 ? '; e mais ' . (count($fs) - 4) . ' trecho(s)' : '');

bb_cabecalho('busca', $u);
?>
<?php if (!$contas): ?>
  <div class="bb-vazio"><h2>Nenhum extrato importado ainda</h2><p>Comece enviando os arquivos .ofx dos bancos.</p><a class="bb-btn" href="/busca-bancos/importar.php">Importar extratos</a></div>
<?php bb_rodape(); return; endif; ?>

<section class="bb-cob" aria-label="Período disponível por conta">
  <div class="bb-cob-tit">Temos extrato de: <a href="/busca-bancos/cobertura.php">ver mês a mês</a></div>
  <div class="bb-cob-lista">
  <?php foreach ($contas as $cid => $c): $i = $int[$cid] ?? []; $furos = count($i) > 1; ?>
    <a class="bb-cob-item<?= $furos ? ' furo' : '' ?>" href="<?= h($qs(['conta' => [$cid]])) ?>" title="Buscar só nesta conta">
      <?= bb_banco($c['banco']) ?>
      <span class="bb-cob-txt"><b><?= h($c['apelido']) ?></b><small><?= h(bb_empresa($c['empresa'])) ?></small></span>
      <span class="bb-cob-per"><?= $i ? BbTexto::dataBr($i[0][0]) . ' → ' . BbTexto::dataBr(end($i)[1]) : 'sem dados' ?><?php if ($furos): ?><em>com <?= count($i) - 1 ?> buraco(s) no meio</em><?php endif; ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</section>

<form class="bb-form" method="get" action="/busca-bancos/" id="bb-form">
  <div class="bb-q">
    <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Nome, descrição, CPF/CNPJ, valor (1500 ou 1.500,00), data (15/03), nº da cobrança… pode misturar: daniela 2000" autofocus autocomplete="off" aria-label="O que procurar">
    <button class="bb-btn" type="submit">Buscar</button>
    <?php if ($temFiltro): ?><a class="bb-btn bb-limpar" href="/busca-bancos/" title="Volta para a busca sem nenhum filtro">✕ Limpar filtros</a><?php endif; ?>
  </div>
  <?php if ($temFiltro && $filtrosAtivos): ?><p class="bb-ativos">Filtros ativos: <?= implode(' · ', array_map('h', $filtrosAtivos)) ?></p><?php endif; ?>
  <?php if ($exp = BbBusca::explicar($m['interp'])): ?><p class="bb-entendi">Entendi: <?= implode(' · ', array_map(fn($x) => '<span>' . h($x) . '</span>', $exp)) ?></p><?php endif; ?>
  <div class="bb-filtros">
    <label>Período
      <select name="per" data-auto>
        <?php foreach (['tudo' => 'Tudo que temos', 'mes' => 'Este mês', 'mesant' => 'Mês passado', '90' => 'Últimos 90 dias', 'ano' => 'Este ano', 'anoant' => 'Ano passado', 'custom' => 'Personalizado…'] as $k => $t): ?>
          <option value="<?= $k ?>"<?= $f['per'] === $k ? ' selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bb-datas"<?= $f['per'] === 'custom' ? '' : ' hidden' ?>>De <input type="date" name="de" value="<?= h($f['per'] === 'custom' ? $f['de'] : '') ?>"> até <input type="date" name="ate" value="<?= h($f['per'] === 'custom' ? $f['ate'] : '') ?>"></label>
    <fieldset><legend>Tipo</legend>
      <?php foreach (['' => 'Entradas e saídas', 'E' => 'Só entradas', 'S' => 'Só saídas'] as $k => $t): ?>
        <label class="bb-pill"><input type="radio" name="tipo" value="<?= $k ?>"<?= $f['tipo'] === $k ? ' checked' : '' ?> data-auto><span><?= $t ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Empresa</legend>
      <?php foreach (BbImportador::EMPRESAS as $k => $t): ?>
        <label class="bb-pill"><input type="checkbox" name="emp[]" value="<?= $k ?>"<?= in_array($k, $f['empresas'], true) ? ' checked' : '' ?> data-auto><span><?= h($t) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset><legend>Conta <small>(nenhuma marcada = todas)</small></legend>
      <?php foreach ($contas as $cid => $c): ?>
        <label class="bb-pill"><input type="checkbox" name="conta[]" value="<?= $cid ?>"<?= in_array($cid, $f['contas'], true) ? ' checked' : '' ?> data-auto><span><?= bb_banco($c['banco']) ?> <?= h($c['apelido']) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
  </div>
  <?php $avAberto = $f['tolv'] || $f['told'] || $f['vmin'] !== '' || $f['vmax'] !== '' || $f['ocultar_internas'] || $f['ocultar_anuladas'] || $f['pendentes'] || $f['origem'] !== '' || $f['agrupar'] || $f['parcial']; ?>
  <details class="bb-avancado"<?= $avAberto ? ' open' : '' ?>>
    <summary>Mais opções</summary>
    <div class="bb-filtros">
      <label>Tolerância no valor (± R$) <input type="text" inputmode="decimal" name="tolv" value="<?= $f['tolv'] ? h(number_format($f['tolv'], 2, ',', '')) : '' ?>" placeholder="0,00" size="6"></label>
      <label>Tolerância na data (± dias) <input type="number" name="told" min="0" max="60" value="<?= $f['told'] ?: '' ?>" placeholder="0" style="width:5em"></label>
      <label>Valor de <input type="text" inputmode="decimal" name="vmin" value="<?= $f['vmin'] !== '' ? h(number_format((float)$f['vmin'], 2, ',', '')) : '' ?>" size="8"> até <input type="text" inputmode="decimal" name="vmax" value="<?= $f['vmax'] !== '' ? h(number_format((float)$f['vmax'], 2, ',', '')) : '' ?>" size="8"></label>
      <label>Origem <select name="origem" data-auto><option value="">Qualquer</option><?php foreach (['upload' => 'Enviado na ferramenta', 'drive' => 'Pasta do Drive', 'pdf' => 'PDF da Caixa', 'omie' => 'Omie (API)'] as $k => $t): ?><option value="<?= $k ?>"<?= $f['origem'] === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
      <label class="bb-chk"><input type="checkbox" name="oi" value="1"<?= $f['ocultar_internas'] ? ' checked' : '' ?> data-auto> Ocultar transferências entre contas próprias <small>(marcação automática: confira antes de confiar)</small></label>
      <label class="bb-chk"><input type="checkbox" name="oa" value="1"<?= $f['ocultar_anuladas'] ? ' checked' : '' ?> data-auto> Ocultar estornos e tarifas que se anulam</label>
      <label class="bb-chk"><input type="checkbox" name="pendentes" value="1"<?= $f['pendentes'] ? ' checked' : '' ?> data-auto> Só o que ainda não foi conferido</label>
      <label class="bb-chk"><input type="checkbox" name="parcial" value="1"<?= $f['parcial'] ? ' checked' : '' ?> data-auto> Aceitar nome parecido (parte das palavras)</label>
      <label class="bb-chk"><input type="checkbox" name="agrupar" value="1"<?= $f['agrupar'] ? ' checked' : '' ?> data-auto> Agrupar por pessoa / empresa</label>
    </div>
  </details>
</form>

<?php /* ---------- avisos ---------- */ ?>
<?php if ($avisos): ?>
  <div class="bb-alerta" role="status"><b>Atenção: faltam extratos<?= ($dePer || $atePer) ? ' no período escolhido' : '' ?>.</b> O que aconteceu nesses dias <b>não aparece</b> na busca. <a href="/busca-bancos/importar.php">Enviar extratos</a>
    <ul><?php foreach ($avisos as $av): $c = $av['conta']; ?>
      <li><?= bb_banco($c['banco']) ?> <b><?= h($c['apelido']) ?></b>: <?= $av['tudo'] ? 'nenhum extrato' . (($dePer || $atePer) ? ' neste período' : '') : 'sem extrato de ' . h($fmtFaltas($av['faltas'])) ?></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($silencios): ?>
  <div class="bb-alerta" role="status"><b>Atenção: dias cobertos pelo extrato, mas sem nenhum lançamento<?= ($dePer || $atePer) ? ' no período escolhido' : '' ?>.</b> Fora do normal para a conta — o extrato pode ter vindo incompleto. <a href="/busca-bancos/cobertura.php">Ver cobertura</a>
    <ul><?php foreach ($silencios as $t): $c = $t['conta']; ?>
      <li><?= bb_banco($c['banco']) ?> <b><?= h($c['apelido']) ?></b>: nada de <?= BbTexto::dataBr($t['de']) ?> a <?= BbTexto::dataBr($t['ate']) ?> (<?= $t['uteis'] ?> dias úteis; costuma ter movimento em <?= (int)round(100 * $t['p']) ?>% deles). <?= h(BbCobertura::motivoSilencio($t)) ?></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$ignorar && $m['interp']['docs'] && $semDoc): ?>
  <div class="bb-alerta info"><b><?= h(implode(', ', $semDoc)) ?></b> não <?= count($semDoc) > 1 ? 'informam' : 'informa' ?> CPF/CNPJ no extrato — <?= $restrito ? 'esta busca' : 'nessas contas a busca' ?> por documento não acha nada. Procure pelo <b>nome</b> ou pelo <b>valor</b>.
    <?php if ($restrito): ?><a href="<?= h($qs(['conta' => null])) ?>">Buscar em todas as contas</a><?php endif; ?> <a href="<?= h($qs(['ig' => 1])) ?>">Ignorar aviso</a></div>
<?php endif; ?>
<?php if (!$ignorar && $m['interp']['palavras'] && $semNome): ?>
  <div class="bb-alerta info"><b><?= h(implode(', ', $semNome)) ?></b> quase não <?= count($semNome) > 1 ? 'trazem' : 'traz' ?> nome no extrato — nessas contas procure pelo <b>valor</b> e pela <b>data</b>. <a href="<?= h($qs(['ig' => 1])) ?>">Ignorar aviso</a></div>
<?php endif; ?>
<?php if ($restrito && !$ignorar): $bs = []; foreach ($noFiltro as $cid => $c) if (isset($cap[$cid])) $bs[] = '<b>' . h($c['apelido']) . '</b> informa ' . implode(', ', array_filter([$cap[$cid]['nome'] >= 3 ? 'nome (' . $cap[$cid]['nome'] . '% dos lançamentos)' : null, $cap[$cid]['doc'] >= 3 ? 'CPF/CNPJ (' . $cap[$cid]['doc'] . '%)' : null, $cap[$cid]['ref'] >= 3 ? 'nº de cobrança/documento (' . $cap[$cid]['ref'] . '%)' : null, 'valor e data'])) . '; não informa ' . (implode(', ', array_filter([$cap[$cid]['nome'] < 3 ? 'nome' : null, $cap[$cid]['doc'] < 3 ? 'CPF/CNPJ' : null, 'chave Pix'])));
  if ($bs): ?><div class="bb-nota">Busca limitada a <?= count($noFiltro) ?> conta(s). <?= implode('. ', $bs) ?>. <a href="<?= h($qs(['conta' => null])) ?>">Buscar em todas</a> · <a href="<?= h($qs(['ig' => 1])) ?>">Ignorar</a></div><?php endif; endif; ?>
<?php if ($parcialAuto): ?><div class="bb-nota">Nenhum lançamento tem <b>todas</b> as palavras. Mostrando os que têm <b>parte delas</b> (os bancos cortam e mascaram nomes), os mais parecidos primeiro.</div><?php endif; ?>
<?php if ($ocultos): ?><div class="bb-nota"><?= $ocultos ?> lançamento(s) que batem com a busca estão ocultos porque você marcou "ocultar" em Mais opções. <a href="<?= h($qs(['oi' => null, 'oa' => null])) ?>">Mostrar tudo</a></div><?php endif; ?>

<?php /* ---------- totais ---------- */ ?>
<div class="bb-totais">
  <div><small>Lançamentos</small><b><?= number_format($tot['n'], 0, ',', '.') ?></b></div>
  <div class="ent"><small>Entradas</small><b><?= BbTexto::brl($tot['ent']) ?></b></div>
  <div class="sai"><small>Saídas</small><b><?= BbTexto::brl($tot['sai']) ?></b></div>
  <div><small>Saldo do resultado</small><b><?= BbTexto::brl($tot['ent'] + $tot['sai']) ?></b></div>
  <?php if ($tot['n'] && !$f['agrupar']): $pct = (int)floor(100 * $tot['conf'] / $tot['n']); ?>
  <div class="bb-conf-box"><small>Conferidos</small><b><?= number_format($tot['conf'], 0, ',', '.') ?> <span>de <?= number_format($tot['n'], 0, ',', '.') ?></span></b>
    <span class="bb-conf-barra" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></span>
    <span class="bb-conf-acoes" data-filtros="<?= h(http_build_query(array_diff_key($_GET, ['p' => 1]))) ?>" data-total="<?= $tot['n'] ?>">
      <?php if ($tot['conf'] < $tot['n']): ?><button type="button" class="bb-btn sec" data-conf-todos="1">✓ Marcar <?= $tot['n'] > 1 ? 'os ' . number_format($tot['n'], 0, ',', '.') : 'o' ?> como conferido<?= $tot['n'] > 1 ? 's' : '' ?></button><?php endif; ?>
      <?php if ($tot['conf']): ?><button type="button" class="bb-btn sec bb-desm" data-conf-todos="0">Desmarcar <?= $tot['conf'] > 1 ? 'os ' . number_format($tot['conf'], 0, ',', '.') : 'o' ?></button><?php endif; ?></span></div>
  <?php endif; ?>
  <span class="bb-totais-acoes"><?php if ($tot['n']): ?><a class="bb-btn sec" href="/busca-bancos/exportar.php<?= h($qs(['fmt' => 'xlsx'])) ?>">Baixar Excel</a> <a class="bb-btn sec" href="/busca-bancos/exportar.php<?= h($qs(['fmt' => 'csv'])) ?>">CSV</a><?php endif; ?></span>
</div>

<?php if ($tot['n'] && !$f['agrupar']): ?><p class="bb-nota bb-conf-ajuda"><b>Conferência:</b> marque <span class="bb-confpill on" aria-hidden="true">✓ Conferido</span> quando alguém já tiver checado o lançamento (bateu com a planilha, com o Omie ou com o comprovante). Fica registrado quem marcou e quando, e em <i>Mais opções → Só o que ainda não foi conferido</i> aparece só o que falta. "Marcar os … como conferidos" vale para <b>todos os resultados desta busca</b>, não só os da página.</p><?php endif; ?>
<?php if (!$tot['n']): ?>
  <div class="bb-vazio"><h2>Nada encontrado</h2><p>Confira os avisos de cobertura acima: se o período não tem extrato, o lançamento pode existir e só não estar aqui. Você também pode aumentar a tolerância de valor/data em "Mais opções".</p></div>
<?php elseif ($f['agrupar']): ?>
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Pessoa / empresa</th><th>CPF/CNPJ</th><th class="num">Lanç.</th><th class="num">Entradas</th><th class="num">Saídas</th><th>Período</th><th class="num">Contas</th></tr></thead><tbody>
    <?php foreach ($grupos as $g): $busca = $g['doc'] !== '' ? $g['doc'] : $g['nome']; ?>
      <tr><td><?php if ($g['chave'] === ''): ?><i>sem nome identificado (tarifas, débitos automáticos…)</i><?php else: ?><a href="<?= h($qs(['q' => $busca, 'agrupar' => null])) ?>"><?= h($g['nome'] ?: BbTexto::docFmt($g['doc'])) ?></a><?php endif; ?></td>
        <td class="mono"><?= h(BbTexto::docFmt($g['doc'])) ?></td><td class="num"><?= (int)$g['n'] ?></td>
        <td class="num ent"><?= (float)$g['ent'] ? BbTexto::brl((float)$g['ent']) : '' ?></td><td class="num sai"><?= (float)$g['sai'] ? BbTexto::brl((float)$g['sai']) : '' ?></td>
        <td><?= BbTexto::dataBr($g['de']) ?><?= $g['de'] !== $g['ate'] ? ' a ' . BbTexto::dataBr($g['ate']) : '' ?></td><td class="num"><?= (int)$g['contas'] ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php else: ?>
  <div class="bb-tab-wrap"><table class="bb-tab bb-res">
    <thead><tr><th>Data</th><th>Conta</th><th>Quem / descrição</th><th>CPF/CNPJ</th><th class="num">Valor</th><th>Conferência</th></tr></thead><tbody>
    <?php foreach ($linhas as $l): $v = (float)$l['valor']; ?>
      <tr class="bb-lin<?= $l['anulada'] || $l['interna'] ? ' apagado' : '' ?>" data-id="<?= (int)$l['id'] ?>" tabindex="0" role="button" aria-expanded="false">
        <td class="mono"><?= BbTexto::dataBr($l['data']) ?></td>
        <td><?= bb_banco($l['banco']) ?> <span class="bb-conta"><?= h($l['apelido']) ?></span></td>
        <td><?php if ($l['contraparte'] !== ''): ?><b><?= h($l['contraparte']) ?></b> <?php endif; ?><span class="bb-memo"><?= h($l['memo']) ?></span>
          <?php if ($l['interna']): ?><span class="bb-tag">transferência interna</span><?php endif; ?><?php if ($l['anulada']): ?><span class="bb-tag">anulada</span><?php endif; ?><?php if ($l['origem'] === 'pdf'): ?><span class="bb-tag">PDF</span><?php endif; ?>
          <?php if ($l['nota'] !== ''): ?><br><span class="bb-nota-lin">✎ <?= h($l['nota']) ?></span><?php endif; ?></td>
        <td class="mono"><?= h(BbTexto::docFmt($l['doc'])) ?></td>
        <td class="num <?= $v >= 0 ? 'ent' : 'sai' ?>"><?= BbTexto::brl($v) ?></td>
        <td><?php $quem = $l['conferido'] ? trim('Conferido' . ($l['conferido_nome'] ? ' por ' . $l['conferido_nome'] : '') . ($l['conferido_em'] ? ' em ' . date('d/m/Y H:i', strtotime($l['conferido_em'])) : '')) . '. Clique para desmarcar.' : 'Clique quando tiver checado este lançamento (bateu com a planilha, o Omie ou o comprovante).'; ?>
          <button type="button" class="bb-confpill<?= $l['conferido'] ? ' on' : '' ?>" aria-pressed="<?= $l['conferido'] ? 'true' : 'false' ?>" title="<?= h($quem) ?>"><?= $l['conferido'] ? '✓ Conferido' : 'Conferir' ?></button></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php $paginas = (int)ceil($tot['n'] / $porPagina); if ($paginas > 1): ?>
    <nav class="bb-pag">Página <?= $pag ?> de <?= $paginas ?>
      <?php if ($pag > 1): ?><a class="bb-btn sec" href="<?= h($qs() . '&p=' . ($pag - 1)) ?>">← Anterior</a><?php endif; ?>
      <?php if ($pag < $paginas): ?><a class="bb-btn sec" href="<?= h($qs() . '&p=' . ($pag + 1)) ?>">Próxima →</a><?php endif; ?></nav>
  <?php endif; ?>
<?php endif; ?>
<?php
bb_rodape();
