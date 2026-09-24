<?php
/* =====================================================================
   capa-financeira/revisar.php — revisão de uma capa: alertas, pessoas,
   datas, natureza, nota fiscal, duplicatas/alterações; confirmação.
   As edições são salvas na hora via acao.php (fetch).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT c.*, u.nome AS enviado_nome FROM cf_capas c LEFT JOIN users u ON u.id = c.enviado_por WHERE c.id = ?');
$st->execute([$id]);
$capa = $st->fetch();
if (!$capa) { http_response_code(404); exit('Capa não encontrada.'); }

// linhas em revisão sem chave Pix herdam a padrão da pessoa (capas enviadas antes do campo existir, ou pessoa que ganhou chave depois)
if ($capa['status'] === 'revisao') {
    $pdo->prepare("UPDATE cf_lancamentos l JOIN cf_pessoas p ON p.id = l.pessoa_id
                   SET l.chave_pix = p.chave_pix
                   WHERE l.capa_id = ? AND l.status = 'revisao' AND (l.chave_pix IS NULL OR l.chave_pix = '') AND p.chave_pix IS NOT NULL AND p.chave_pix <> ''")->execute([$id]);
}
$st = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE capa_id = ? ORDER BY linha_xlsx');
$st->execute([$id]);
$linhas = $st->fetchAll();
$pessoas = cf_pessoas(false);   // inclui desligados: quem saiu ainda tem recebíveis
$categorias = cf_config('categorias', []);
$empresas = array_column(cf_config('empresas', []), null, 'id');
$capaFlags = json_decode((string)$capa['capa_flags'], true) ?: [];
$anterior = null;
if ($capa['capa_anterior_id']) { $q = $pdo->prepare('SELECT id, versao, status, criado_em FROM cf_capas WHERE id = ?'); $q->execute([$capa['capa_anterior_id']]); $anterior = $q->fetch() ?: null; }
$antById = [];
if ($anterior) { $q = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE capa_id = ?'); $q->execute([$anterior['id']]); foreach ($q->fetchAll() as $a) $antById[(int)$a['id']] = $a; }
$editavel = $capa['status'] === 'revisao';
$funcoes = ['CORRETOR', 'CAPTADOR', 'COORDENADOR', 'INTEGRACAO', 'DIRETOR', 'PRE VENDA', 'FINANCEIRO', 'SAC', 'ADM'];

// pendências
$pend = [];
foreach ($linhas as $l) {
    if ($l['status'] !== 'revisao') continue;
    $fl = json_decode((string)$l['flags'], true) ?: [];
    if ($l['tipo'] === 'P' && !$l['pessoa_id']) $pend[] = "Linha {$l['linha_xlsx']}: favorecido não identificado";
    if (!$l['data_prevista']) $pend[] = "Linha {$l['linha_xlsx']}: sem data prevista";
    if (cf_flags_alerta($fl) && !(int)$l['revisado']) $pend[] = "Linha {$l['linha_xlsx']}: alerta sem conferir";
}

portal_header('Revisar capa', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:none;width:auto;margin:0 18px}</style>
<script src="/capa-financeira/pix.js?v=<?= @filemtime(__DIR__ . '/pix.js') ?: 1 ?>"></script>
<div class="cf-rev">
<?php cf_cabecalho('Capa #' . (int)$capa['id'] . ' — ' . h($capa['cliente']) . ((int)$capa['versao'] > 1 ? ' <span class="cf-tag">versão ' . (int)$capa['versao'] . '</span>' : ''),
    h($capa['construtora']) . ' · ' . h($capa['unidade']) . ' · ' . h($capa['bairro']) . ' · COD <b>' . (h((string)$capa['cod']) ?: '—') . '</b> · venda <b>' . cf_data_br($capa['data_venda']) . '</b> · empresa <b>' . h($empresas[$capa['empresa']]['nome'] ?? strtoupper($capa['empresa'])) . '</b>',
    [['Capa Financeira', '/capa-financeira/'], ['Capas', '/capa-financeira/'], ['Capa #' . (int)$capa['id'], null]], 'capas',
    '<p class="cf-dica">Arquivo: ' . h($capa['arquivo_nome']) . ' · enviado por ' . h((string)$capa['enviado_nome']) . ' em ' . h(substr((string)$capa['criado_em'], 0, 16)) . ' · status <span class="cf-status cf-st-' . h($capa['status']) . '">' . h($capa['status']) . '</span>'
    . ($anterior ? ' · substitui a <a href="/capa-financeira/revisar.php?id=' . (int)$anterior['id'] . '">capa #' . (int)$anterior['id'] . ' (v' . (int)$anterior['versao'] . ')</a>' : '') . '</p>'); ?>

<div class="cf-resumo">
  <div class="cf-kpi"><span>A pagar</span><b><?= cf_brl($capa['total_pagar']) ?></b></div>
  <div class="cf-kpi"><span>A receber</span><b><?= cf_brl($capa['total_receber']) ?></b></div>
  <div class="cf-kpi"><span>Bônus (capa)</span><b><?= cf_brl($capa['valor_bonus']) ?></b></div>
  <div class="cf-kpi"><span>VGV</span><b><?= cf_brl($capa['vgv']) ?></b></div>
</div>

<?php if ($capaFlags): ?>
<div class="aviso"><b>Avisos da capa:</b><ul class="cf-flags"><?php foreach ($capaFlags as $f): ?><li><?= h(cf_flag_texto($f)) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($editavel && ($capa['cod'] === null || $capa['cod'] === '')): ?>
<div class="aviso"><b>Capa sem COD do imóvel.</b> Informe o código do imóvel no Robust para continuar:
  <input id="cf-cod" class="cf-in" placeholder="ex.: 1375" style="width:120px"> <button class="btn" id="btn-cod" type="button">Salvar COD</button></div>
<?php endif; ?>
<?php if ($capa['obs_capa']): ?><div class="aviso">Observação na capa (C8): <b><?= h($capa['obs_capa']) ?></b></div><?php endif; ?>
<?php
// nomes divergentes entre capa e histórico: oferece as opções para padronizar (vale para observação do Omie e recibo)
$opcoes = ['cliente' => [], 'construtora' => []];
if ($editavel) foreach ($linhas as $l) { if ($l['status'] !== 'revisao' || (int)$l['revisado']) continue;
    foreach (json_decode((string)$l['flags'], true) ?: [] as $f) { $c = cf_flag_codigo($f);
        if (in_array($c, ['CLIENTES_HIST_GRAFIA', 'CLIENTES_HIST_DIFEREM_CAPA'], true) && preg_match("/\('(.*)'\)$/u", $f, $m)) $opcoes['cliente'][$m[1]] = ($opcoes['cliente'][$m[1]] ?? 0) + 1;
        if (in_array($c, ['CONSTRUTORA_HIST_GRAFIA', 'CONSTRUTORA_HIST_DIFERE_CAPA'], true) && preg_match("/\('(.*)'\)$/u", $f, $m)) $opcoes['construtora'][$m[1]] = ($opcoes['construtora'][$m[1]] ?? 0) + 1; } }
foreach (['cliente' => 'Cliente', 'construtora' => 'Construtora'] as $campo => $rot): if (!$opcoes[$campo]) continue; ?>
<div class="cf-escolha" data-campo="<?= $campo ?>"><b><?= $rot ?> escrito de formas diferentes na capa e no histórico.</b> Escolha qual grafia vale (vai para a observação do Omie e para o recibo; as linhas ficam conferidas):
  <label><input type="radio" name="esc-<?= $campo ?>" value="<?= h((string)$capa[$campo]) ?>" checked> <b>Capa (B<?= $campo === 'cliente' ? 2 : 3 ?>):</b> <?= h((string)$capa[$campo]) ?></label>
  <?php foreach ($opcoes[$campo] as $nome => $n): ?><label><input type="radio" name="esc-<?= $campo ?>" value="<?= h($nome) ?>"> <b>Histórico (<?= $n ?> linha<?= $n > 1 ? 's' : '' ?>):</b> <?= h($nome) ?></label><?php endforeach; ?>
  <label><input type="radio" name="esc-<?= $campo ?>" value="__outro__"> Outro: <input type="text" class="cf-in" data-outro style="width:60%;max-width:420px" placeholder="digite o nome correto"></label>
  <button type="button" class="btn cf-btn-sec cf-usar-nome">Usar este nome</button>
</div>
<?php endforeach; ?>

<?php if ($editavel): ?>
<div class="cf-barra">
  <div id="cf-pend" class="<?= $pend ? 'cf-pend' : 'cf-pend ok' ?>">
    <?php if ($pend): ?><b><?= count($pend) ?> pendência(s) antes de confirmar:</b><ul><?php foreach (array_slice($pend, 0, 12) as $p): ?><li><?= h($p) ?></li><?php endforeach; ?><?= count($pend) > 12 ? '<li>…</li>' : '' ?></ul>
    <?php else: ?>Tudo revisado. Pode confirmar.<?php endif; ?>
  </div>
  <div class="cf-acoes">
    <button class="btn" id="btn-confirmar" <?= $pend ? 'disabled' : '' ?>>Confirmar capa</button>
    <button class="btn cf-btn-sec" id="btn-descartar">Descartar</button>
    <button class="btn cf-btn-sec cf-btn-perigo" id="btn-excluir" title="apaga a capa e as linhas de vez (só enquanto não foi confirmada)">Excluir</button>
  </div>
</div>
<?php elseif ($capa['status'] === 'confirmada'): ?>
<div class="cf-barra"><div class="cf-pend ok">Capa confirmada<?= $capa['confirmado_em'] ? ' em ' . h(substr((string)$capa['confirmado_em'], 0, 16)) : '' ?>. Para mudar alguma coisa, reabra: os códigos de integração continuam os mesmos; linhas já exportadas para o Omie que forem alteradas entram na lista "alterações depois da exportação".</div>
  <div class="cf-acoes"><button class="btn cf-btn-sec" id="btn-reabrir">Reabrir para editar</button></div></div>
<?php elseif ($capa['status'] === 'descartada'): ?>
<div class="cf-barra"><div class="cf-pend ok">Capa descartada.</div><div class="cf-acoes"><button class="btn cf-btn-sec cf-btn-perigo" id="btn-excluir">Excluir de vez</button></div></div>
<?php endif; ?>

<?php
$legenda = ['IGUAL' => 'igual à versão anterior', 'ALTERADA' => 'alterada em relação à versão anterior', 'NOVA' => 'nova nesta versão', 'POSSIVEL_DUPLICATA' => 'possível duplicata dentro desta capa'];
function cf_linha_diff(array $l, array $antById): string {
    if (!$l['dup_tipo']) return '';
    $cls = ['IGUAL' => 'cf-d-igual', 'ALTERADA' => 'cf-d-alt', 'NOVA' => 'cf-d-nova', 'POSSIVEL_DUPLICATA' => 'cf-d-dup'][$l['dup_tipo']] ?? '';
    $tit = '';
    if ($l['dup_tipo'] === 'ALTERADA' && isset($antById[(int)$l['dup_de']])) {
        $a = $antById[(int)$l['dup_de']];
        $tit = 'antes: ' . cf_data_br($a['data_prevista']) . ' ' . cf_brl($a['valor']) . ' NF=' . ($a['nota_fiscal'] ?: '—') . ' ' . ($a['natureza'] ?: '');
    }
    return '<span class="cf-tag ' . $cls . '" title="' . h($tit) . '">' . h($l['dup_tipo']) . '</span>';
}
/** célula da esquerda: nº da linha, diff, pílulas de alerta e o botão Conferido (+ "todos iguais") */
function cf_render_alertas(array $l, array $fl, array $antById, bool $editavel, bool $rem, array $repeticoes): string {
    $al = cf_flags_alerta($fl); $rev = (int)$l['revisado'] === 1;
    $out = '<span class="cf-lnum">' . (int)$l['linha_xlsx'] . '</span> ' . cf_linha_diff($l, $antById);
    foreach ($fl as $f) {
        $info = in_array(cf_flag_codigo($f), CF_FLAGS_INFO, true); if ($f === 'PESSOA_NAO_IDENTIFICADA') continue;
        $cls = $info ? 'cf-ok' : ($rev ? 'cf-ok' : 'cf-' . cf_nivel_flag($f));
        $out .= '<br><span class="cf-pill ' . $cls . '" title="' . h(cf_flag_texto($f)) . '">' . ($rev && !$info ? '✔ ' : '') . h(cf_flag_curto($f)) . '</span>';
    }
    if ($al && !$rev && $editavel && !$rem) {
        $out .= '<button type="button" class="cf-ok-btn" title="' . h(implode(' | ', array_map('cf_flag_texto', $al))) . '">✔ Conferido</button>';
        $cods = array_unique(array_map('cf_flag_codigo', $al)); $iguais = 0;
        foreach ($cods as $c) $iguais = max($iguais, ($repeticoes[$c] ?? 1) - 1);
        if ($iguais > 0) $out .= '<button type="button" class="cf-ok-todos" data-cods="' . h(implode(' ', $cods)) . '">conferir todos iguais (+' . $iguais . ')</button>';
    }
    return $out;
}
// quantas linhas em revisão têm cada código de alerta (para o "conferir todos iguais")
$repeticoes = [];
foreach ($linhas as $l) { if ($l['status'] !== 'revisao' || (int)$l['revisado']) continue; foreach (array_unique(array_map('cf_flag_codigo', cf_flags_alerta(json_decode((string)$l['flags'], true) ?: []))) as $c) $repeticoes[$c] = ($repeticoes[$c] ?? 0) + 1; }
function cf_row_class(array $l, array $fl): string {
    $al = cf_flags_alerta($fl); if (!$al || (int)$l['revisado']) return '';
    foreach ($al as $f) if (cf_nivel_flag($f) === 'grave') return 'cf-tem-grave';
    return 'cf-tem-leve';
}
$pagar = array_filter($linhas, fn($l) => $l['tipo'] === 'P');
$receber = array_filter($linhas, fn($l) => $l['tipo'] === 'R');
?>

<h2 class="cf-h2">Contas a pagar <small><?= count($pagar) ?> linha(s)</small></h2>
<div class="cf-tbl-wrap">
<table class="grid cf-tbl" id="tbl-pagar">
<colgroup><col style="width:19%"><col style="width:9%"><col style="width:10%"><col style="width:13%"><col style="width:10%"><col style="width:7%"><col style="width:7%"><col style="width:10%"><col style="width:6%"><col style="width:7%"><col style="width:2%"></colgroup>
<thead><tr><th>Linha · alertas</th><th>Data prevista</th><th>Favorecido (col. C)</th><th>Pessoa (dicionário)</th><th>Chave Pix</th><th>Função</th><th>Natureza</th><th>Categoria Omie</th><th>Valor</th><th>Nota Fiscal / condição</th><th></th></tr></thead>
<tbody>
<?php foreach ($pagar as $l): $fl = json_decode((string)$l['flags'], true) ?: []; $rem = $l['status'] === 'removido'; ?>
<tr class="cf-row <?= $rem ? 'cf-removida' : '' ?> <?= cf_row_class($l, $fl) ?>" data-id="<?= (int)$l['id'] ?>" data-cf="<?= h(CapaParser::key($l['cf_raw'])) ?>" data-cods="<?= h(implode(' ', array_unique(array_map('cf_flag_codigo', cf_flags_alerta($fl))))) ?>" data-rev="<?= (int)$l['revisado'] ?>">
  <td class="cf-alertas"><?= cf_render_alertas($l, $fl, $antById, $editavel, $rem, $repeticoes) ?></td>
  <td>
    <?php if ($editavel && !$rem): ?><input type="date" class="cf-in" data-campo="data_prevista" value="<?= h((string)$l['data_prevista']) ?>">
    <?php else: ?><?= cf_data_br($l['data_prevista']) ?><?php endif; ?>
    <?php if ($l['data_raw'] && $l['data_raw'] !== $l['data_prevista']): ?><br><small class="cf-raw">na capa: <?= h($l['data_raw']) ?></small><?php endif; ?>
  </td>
  <td><b><?= h($l['cf_raw']) ?></b><?php if ($l['favorecido_hist'] && CapaParser::key($l['favorecido_hist']) !== CapaParser::key($l['cf_raw'])): ?><br><small class="cf-raw">histórico: <?= h($l['favorecido_hist']) ?></small><?php endif; ?></td>
  <td>
    <?php if ($editavel && !$rem): ?>
      <select class="cf-in cf-pessoa <?= $l['pessoa_id'] ? '' : 'cf-vazio' ?>" data-campo="pessoa_id">
        <option value="">— não identificado —</option>
        <option value="__nova__">＋ Cadastrar nova pessoa…</option>
        <?php $sug = $l['pessoa_id'] ? [] : cf_sugerir_pessoas($l['cf_raw'], $pessoas);
        if ($sug): ?><optgroup label="Parecidos (confira!)"><?php foreach ($sug as $pid): ?><option value="<?= $pid ?>">≈ <?= h($pessoas[$pid]['nome']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <?php $terc = array_filter($pessoas, fn($p) => ($p['tipo'] ?? 'equipe') === 'terceiro'); if ($terc || $l['natureza'] === 'REPASSE'): ?><optgroup label="Construtoras / terceiros (repasse)"><?php foreach ($terc as $pid => $p): ?><option value="<?= $pid ?>" <?= (int)$l['pessoa_id'] === $pid ? 'selected' : '' ?>><?= h($p['nome']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <optgroup label="Equipe"><?php foreach ($pessoas as $pid => $p): if (!$p['ativo'] || ($p['tipo'] ?? 'equipe') === 'terceiro') continue; ?><option value="<?= $pid ?>" <?= (int)$l['pessoa_id'] === $pid ? 'selected' : '' ?>><?= h($p['nome']) ?></option><?php endforeach; ?></optgroup>
        <optgroup label="Desligados"><?php foreach ($pessoas as $pid => $p): if ($p['ativo'] || ($p['tipo'] ?? 'equipe') === 'terceiro') continue; ?><option value="<?= $pid ?>" <?= (int)$l['pessoa_id'] === $pid ? 'selected' : '' ?>><?= h($p['nome']) ?> (desligado)</option><?php endforeach; ?></optgroup>
      </select>
      <?php if (!$l['pessoa_id']): ?><label class="cf-mini"><input type="checkbox" class="cf-salvar-alias" checked> salvar "<?= h($l['cf_raw']) ?>" como apelido</label><?php endif; ?>
    <?php else: ?><?= h($pessoas[(int)$l['pessoa_id']]['nome'] ?? '—') ?><?php endif; ?>
  </td>
  <td class="cf-pix-cel"><?php if ($editavel && !$rem): ?><input type="text" class="cf-in cf-pix" data-campo="chave_pix" value="<?= h((string)$l['chave_pix']) ?>" placeholder="CPF, CNPJ, e-mail…" autocomplete="off">
      <?php if ($l['pessoa_id'] && empty($pessoas[(int)$l['pessoa_id']]['chave_pix'])): ?><label class="cf-mini"><input type="checkbox" class="cf-pix-salvar" checked> salvar na pessoa</label><?php endif; ?>
      <?php else: ?><?= h((string)$l['chave_pix']) ?: '—' ?><?php endif; ?></td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="funcao"><?php foreach (array_unique(array_merge($funcoes, [$l['funcao'] ?: 'CORRETOR'])) as $f): ?><option <?= $f === $l['funcao'] ? 'selected' : '' ?>><?= h($f) ?></option><?php endforeach; ?></select><?php else: ?><?= h((string)$l['funcao']) ?><?php endif; ?></td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="natureza"><option <?= $l['natureza'] === 'COMISSAO' ? 'selected' : '' ?>>COMISSAO</option><option <?= $l['natureza'] === 'BONUS' ? 'selected' : '' ?>>BONUS</option><option <?= $l['natureza'] === 'REPASSE' ? 'selected' : '' ?>>REPASSE</option></select><?php else: ?><?= h((string)$l['natureza']) ?><?php endif; ?></td>
  <td class="cf-cat"><?php if ($editavel && !$rem && $l['natureza'] === 'REPASSE'): ?><select class="cf-in" data-campo="categoria"><?php foreach (array_unique([$categorias['REPASSE'] ?? '', $categorias['REPASSE_FUTURO'] ?? '']) as $c): if ($c === '') continue; ?><option <?= $c === $l['categoria'] ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select><?php else: ?><?= h((string)$l['categoria']) ?: '<span class="cf-tag cf-grave">sem categoria</span>' ?><?php endif; ?></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?php if ($editavel && !$rem): ?><input type="text" class="cf-in cf-nf" maxlength="20" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>"><?php else: ?><?= h((string)$l['nota_fiscal']) ?><?php endif; ?>
      <?php if ($l['condicao'] || $l['col_g']): ?><br><small class="cf-raw"><?= h((string)$l['condicao']) ?><?= $l['prefixo'] && $l['col_g'] && CapaParser::key($l['prefixo']) !== CapaParser::key($l['col_g']) ? ' · G: ' . h($l['col_g']) . ' · prefixo: ' . h($l['prefixo']) : '' ?></small><?php endif; ?></td>
  <td><?php if ($editavel): ?><button class="cf-x" data-acao="<?= $rem ? 'restaurar' : 'remover' ?>" title="<?= $rem ? 'restaurar' : 'remover esta linha' ?>"><?= $rem ? '↺' : '✕' ?></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="8">Total a pagar (linhas ativas)</td><td class="cf-num"><?= cf_brl(array_sum(array_map(fn($l) => $l['status'] === 'removido' ? 0 : (float)$l['valor'], $pagar))) ?></td><td colspan="2"></td></tr></tfoot>
</table></div>

<h2 class="cf-h2">Contas a receber <small><?= count($receber) ?> linha(s)</small></h2>
<div class="cf-tbl-wrap">
<table class="grid cf-tbl" id="tbl-receber">
<colgroup><col style="width:24%"><col style="width:10%"><col style="width:20%"><col style="width:6%"><col style="width:18%"><col style="width:8%"><col style="width:11%"><col style="width:3%"></colgroup>
<thead><tr><th>Linha · alertas</th><th>Data prevista</th><th>Cliente (col. C)</th><th>Parcela</th><th>Categoria Omie</th><th>Valor</th><th>Fluxo</th><th></th></tr></thead>
<tbody>
<?php foreach ($receber as $l): $fl = json_decode((string)$l['flags'], true) ?: []; $rem = $l['status'] === 'removido'; ?>
<tr class="cf-row <?= $rem ? 'cf-removida' : '' ?> <?= cf_row_class($l, $fl) ?>" data-id="<?= (int)$l['id'] ?>" data-cods="<?= h(implode(' ', array_unique(array_map('cf_flag_codigo', cf_flags_alerta($fl))))) ?>" data-rev="<?= (int)$l['revisado'] ?>">
  <td class="cf-alertas"><?= cf_render_alertas($l, $fl, $antById, $editavel, $rem, $repeticoes) ?></td>
  <td><?php if ($editavel && !$rem): ?><input type="date" class="cf-in" data-campo="data_prevista" value="<?= h((string)$l['data_prevista']) ?>"><?php else: ?><?= cf_data_br($l['data_prevista']) ?><?php endif; ?>
      <?php if ($l['data_raw'] && $l['data_raw'] !== $l['data_prevista']): ?><br><small class="cf-raw">na capa: <?= h($l['data_raw']) ?></small><?php endif; ?></td>
  <td><b><?= h($l['cf_raw']) ?></b></td>
  <td><?= $l['parcela'] ? (int)$l['parcela'] . '/' . (int)$l['total_parcelas'] : '—' ?></td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="categoria">
        <?php foreach (array_unique([$categorias['RECEBER_NOVO'] ?? '', $categorias['RECEBER_USADO'] ?? '', $categorias['RECEBER_BONUS'] ?? '', $categorias['RECEBER_REPASSE'] ?? '', $categorias['RECEBER_REPASSE_FUTURO'] ?? '']) as $c): if ($c === '') continue; ?>
          <option <?= $c === $l['categoria'] ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
        <?php if ($l['status_imovel'] === 'PRONTO'): ?><br><small class="cf-raw">imóvel PRONTO: confirme se é usado</small><?php endif; ?>
      <?php else: ?><?= h((string)$l['categoria']) ?><?php endif; ?></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><small><?= h((string)$l['rotulo_fluxo']) ?></small></td>
  <td><?php if ($editavel): ?><button class="cf-x" data-acao="<?= $rem ? 'restaurar' : 'remover' ?>"><?= $rem ? '↺' : '✕' ?></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="5">Total a receber (linhas ativas)</td><td class="cf-num"><?= cf_brl(array_sum(array_map(fn($l) => $l['status'] === 'removido' ? 0 : (float)$l['valor'], $receber))) ?></td><td colspan="2"></td></tr></tfoot>
</table></div>

<p class="cf-dica">Legenda: <?php foreach ($legenda as $k => $v): ?><span class="cf-tag"><?= $k ?></span> <?= $v ?> &nbsp; <?php endforeach; ?></p>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content;
  const capaId = <?= (int)$capa['id'] ?>;
  async function acao(body){
    body.csrf = csrf; body.capa_id = capaId;
    const r = await fetch('/capa-financeira/acao.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify(body)});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!r.ok || !j || !j.ok) { alert('Não salvou: ' + (j && j.erro ? j.erro : r.status)); return null; }
    return j;
  }
  function atualizaPend(j){
    const box = document.getElementById('cf-pend'); const btn = document.getElementById('btn-confirmar');
    if (!box || !j || !j.pendencias) return;
    if (j.pendencias.length) { box.className = 'cf-pend'; box.innerHTML = '<b>' + j.pendencias.length + ' pendência(s) antes de confirmar:</b><ul>' + j.pendencias.slice(0,12).map(p => '<li>' + p + '</li>').join('') + '</ul>'; btn.disabled = true; }
    else { box.className = 'cf-pend ok'; box.textContent = 'Tudo revisado. Pode confirmar.'; btn.disabled = false; }
  }
  function toast(msg){ const t = document.createElement('div'); t.className = 'cf-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 3500); }
  function setPix(row, val){ const px = row && row.querySelector('.cf-pix'); if (!px) return; px.value = val || ''; px.dispatchEvent(new Event('input')); const sp = row.querySelector('.cf-pix-salvar'); if (sp && val) sp.parentElement.remove(); }
  function aplicarPessoa(tr, pid, ids, pix){
    // reflete nas outras linhas do mesmo favorecido sem recarregar (pix: chave cadastrada da pessoa, já gravada no servidor)
    ids.forEach(oid => { const otr = document.querySelector('tr[data-id="' + oid + '"]'); if (!otr) return;
      const sel = otr.querySelector('.cf-pessoa'); if (sel) { sel.value = pid; sel.classList.toggle('cf-vazio', !pid); }
      const cb = otr.querySelector('.cf-salvar-alias'); if (cb) cb.parentElement.remove();
      if (pix) setPix(otr, pix);
      otr.querySelectorAll('.cf-aplicar').forEach(x => x.remove()); });
  }
  function marcaRevisado(tr, j){
    if (!j.tem_grave_pendente) {
      tr.classList.remove('cf-tem-grave', 'cf-tem-leve'); tr.dataset.rev = '1';
      tr.querySelectorAll('.cf-ok-btn,.cf-ok-todos').forEach(b => b.remove());
      tr.querySelectorAll('.cf-pill.cf-grave,.cf-pill.cf-leve').forEach(p => { p.className = 'cf-pill cf-ok'; if (!p.textContent.startsWith('✔')) p.textContent = '✔ ' + p.textContent; });
    }
  }
  function conferir(ids){
    return acao({acao:'revisar_varios', ids}).then(j => { if (!j) return null;
      ids.forEach(id => { const tr = document.querySelector('tr[data-id="' + id + '"]'); if (tr) marcaRevisado(tr, {tem_grave_pendente:false}); });
      atualizaPend(j); return j; });
  }
  function novaPessoaForm(el, tr){
    const id = +tr.dataset.id; const nomeCapa = tr.querySelector('td:nth-child(3) b').textContent.trim();
    tr.querySelectorAll('.cf-aplicar,.cf-nova').forEach(x => x.remove());
    const outras = [...document.querySelectorAll('tr.cf-row[data-cf="' + tr.dataset.cf + '"]')].filter(o => o !== tr && o.querySelector('.cf-pessoa'));
    const box = document.createElement('div'); box.className = 'cf-aplicar cf-nova';
    box.innerHTML = '<div style="width:100%"><b>Nova pessoa</b></div>'
      + '<input class="cf-in" placeholder="Nome (como vai no recibo)" data-n="nome" style="width:100%;max-width:none"> '
      + '<input class="cf-in" placeholder="Razão social (empresa)" data-n="razao_social" style="width:100%;max-width:none"> '
      + '<input class="cf-in" placeholder="CNPJ" data-n="cnpj" style="width:48%"> <input class="cf-in" placeholder="CPF" data-n="cpf" style="width:48%"> '
      + '<input class="cf-in cf-pix" placeholder="Chave Pix (CPF, CNPJ, e-mail, telefone, aleatória)" data-n="chave_pix" style="width:100%;max-width:none"> '
      + (outras.length ? '<label class="cf-mini" style="width:100%"><input type="checkbox" data-n="todas" checked> aplicar também nas outras ' + outras.length + ' linha(s) deste favorecido</label>' : '')
      + '<button type="button" data-t="salvar">Salvar e usar</button> <button type="button" class="sec" data-t="cancelar">Cancelar</button>';
    el.insertAdjacentElement('afterend', box);
    window.cfPix && window.cfPix.liga(box.querySelector('[data-n=chave_pix]'));
    const inNome = box.querySelector('[data-n=nome]'); inNome.value = nomeCapa.split(' ').map(w => w.length > 2 ? w[0] + w.slice(1).toLowerCase() : w.toLowerCase()).join(' '); inNome.focus();
    box.querySelector('[data-t=cancelar]').addEventListener('click', () => { box.remove(); el.value = ''; });
    box.querySelector('[data-t=salvar]').addEventListener('click', async () => {
      const pixEl = box.querySelector('[data-n=chave_pix]');
      if (pixEl.value && pixEl.dataset.pixValido !== '1') { alert('Chave Pix inválida — confira antes de salvar.'); return; }
      const b = {acao:'nova_pessoa', id, nome: inNome.value, razao_social: box.querySelector('[data-n=razao_social]').value,
        cnpj: box.querySelector('[data-n=cnpj]').value, cpf: box.querySelector('[data-n=cpf]').value, chave_pix: pixEl.value,
        aplicar_todas: (box.querySelector('[data-n=todas]') && box.querySelector('[data-n=todas]').checked) ? 1 : 0};
      const j = await acao(b); if (!j) return;
      // adiciona a pessoa nova em todos os selects da página
      document.querySelectorAll('select.cf-pessoa').forEach(sel => { const o = document.createElement('option'); o.value = j.pessoa.id; o.textContent = j.pessoa.nome; sel.appendChild(o); });
      el.value = j.pessoa.id; el.classList.remove('cf-vazio'); box.remove();
      const cb = tr.querySelector('.cf-salvar-alias'); if (cb) cb.parentElement.remove();
      if (j.pessoa.chave_pix) setPix(tr, j.pessoa.chave_pix);
      if (j.aplicado_em && j.aplicado_em.length) aplicarPessoa(tr, String(j.pessoa.id), j.aplicado_em, j.pessoa.chave_pix);
      toast('Pessoa "' + j.pessoa.nome + '" cadastrada' + (j.aplicado_em && j.aplicado_em.length ? ' e aplicada em ' + (j.aplicado_em.length + 1) + ' linhas' : ''));
      tr.classList.toggle('cf-tem-grave', !!j.tem_grave_pendente); atualizaPend(j);
    });
  }
  document.querySelectorAll('.cf-in').forEach(el => {
    el.addEventListener('change', async () => {
      const tr = el.closest('tr'); const id = +tr.dataset.id; const campo = el.dataset.campo;
      let valor = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      if (campo === 'pessoa_id' && valor === '__nova__') { novaPessoaForm(el, tr); return; }
      if (campo === 'chave_pix') {
        if (el.value && el.dataset.pixValido !== '1') { toast('Chave Pix inválida — não salvei'); return; }
        if (el.value && el.dataset.pixValor === '') { toast('Chave Pix incompleta — não salvei'); return; }
        valor = el.dataset.pixValor || '';
      }
      const body = {acao:'editar', id, campo, valor};
      if (campo === 'chave_pix') { const sp = tr.querySelector('.cf-pix-salvar'); body.salvar_na_pessoa = sp && sp.checked ? 1 : 0; }
      if (campo === 'pessoa_id') {
        const cb = tr.querySelector('.cf-salvar-alias'); body.salvar_alias = cb && cb.checked ? 1 : 0;
        // outras linhas com o mesmo favorecido (coluna C) ainda sem pessoa ou com pessoa diferente
        const outras = [...document.querySelectorAll('tr.cf-row[data-cf="' + tr.dataset.cf + '"]')].filter(o => o !== tr && o.querySelector('.cf-pessoa') && o.querySelector('.cf-pessoa').value !== valor);
        if (valor && outras.length) {
          tr.querySelectorAll('.cf-aplicar').forEach(x => x.remove());
          const box = document.createElement('div'); box.className = 'cf-aplicar';
          box.innerHTML = '<span>Mesmo favorecido em mais ' + outras.length + ' linha(s):</span> <button type="button" data-t="todas">Aplicar em todas</button> <button type="button" class="sec" data-t="uma">Só nesta</button>';
          el.insertAdjacentElement('afterend', box);
          box.querySelectorAll('button').forEach(b => b.addEventListener('click', async () => {
            body.aplicar_todas = b.dataset.t === 'todas' ? 1 : 0; box.remove();
            const j = await acao(body); if (!j) return;
            if (j.pessoa_tem_pix) setPix(tr, j.chave_pix);
            if (j.aplicado_em) { aplicarPessoa(tr, valor, j.aplicado_em, j.pessoa_tem_pix ? j.chave_pix : ''); toast('Pessoa aplicada em ' + (j.aplicado_em.length + 1) + ' linhas'); }
            el.classList.toggle('cf-vazio', !el.value); if (cb && el.value) cb.parentElement.remove();
            tr.classList.toggle('cf-tem-grave', !!j.tem_grave_pendente); atualizaPend(j);
          }));
          return;
        }
      }
      const j = await acao(body);
      if (!j) return;
      if (campo === 'natureza' && (valor === 'REPASSE' || tr.querySelector('.cf-cat select'))) { location.reload(); return; }
      if (j.categoria !== undefined) tr.querySelector('.cf-cat').textContent = j.categoria || '';
      if (campo === 'pessoa_id' && j.pessoa_tem_pix) setPix(tr, j.chave_pix);
      if (campo === 'chave_pix' && j.chave_pix) { const sp = tr.querySelector('.cf-pix-salvar'); if (sp) sp.parentElement.remove(); }
      if (j.nota_fiscal !== undefined) { const nf = tr.querySelector('.cf-nf'); if (nf) nf.value = j.nota_fiscal || ''; }
      if (campo === 'pessoa_id') { el.classList.toggle('cf-vazio', !el.value); const cb = tr.querySelector('.cf-salvar-alias'); if (cb && el.value) cb.parentElement.remove(); }
      if (campo === 'revisado' || campo === 'pessoa_id' || campo === 'data_prevista') marcaRevisado(tr, j);
      atualizaPend(j);
    });
  });
  // Conferido (uma linha) e "conferir todos iguais" (todas as linhas pendentes que têm algum dos mesmos alertas)
  document.querySelectorAll('.cf-ok-btn').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const j = await conferir([+tr.dataset.id]); if (j) toast('Linha conferida');
  }));
  document.querySelectorAll('.cf-ok-todos').forEach(b => b.addEventListener('click', async () => {
    const cods = b.dataset.cods.split(' ');
    const ids = [...document.querySelectorAll('tr.cf-row[data-rev="0"]')].filter(tr => (tr.dataset.cods || '').split(' ').some(c => cods.includes(c))).map(tr => +tr.dataset.id);
    if (!ids.length) return;
    if (!confirm('Marcar como conferidas ' + ids.length + ' linha(s) com o mesmo tipo de alerta?')) return;
    const j = await conferir(ids); if (j) toast(ids.length + ' linhas conferidas');
  }));
  document.querySelectorAll('.cf-x').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const j = await acao({acao: b.dataset.acao, id: +tr.dataset.id});
    if (j) location.reload();
  }));
  const bc = document.getElementById('btn-confirmar');
  if (bc) bc.addEventListener('click', async () => {
    if (!confirm('Confirmar esta capa? Os lançamentos passam a valer para exportação e recibos.')) return;
    const j = await acao({acao:'confirmar'}); if (j) location.href = '/capa-financeira/?ok=confirmada&id=' + capaId;
  });
  document.querySelectorAll('.cf-usar-nome').forEach(b => b.addEventListener('click', async () => {
    const box = b.closest('.cf-escolha'); const campo = box.dataset.campo;
    let v = box.querySelector('input[type=radio]:checked').value; if (v === '__outro__') v = box.querySelector('[data-outro]').value.trim();
    if (!v) { toast('Digite o nome'); return; }
    const j = await acao({acao:'editar_capa', campo, valor: v}); if (j) { toast('Nome padronizado'); location.reload(); }
  }));
  const bcod = document.getElementById('btn-cod');
  if (bcod) bcod.addEventListener('click', async () => { const j = await acao({acao:'editar_capa', campo:'cod', valor: document.getElementById('cf-cod').value}); if (j) location.reload(); });
  const bd = document.getElementById('btn-descartar');
  if (bd) bd.addEventListener('click', async () => {
    if (!confirm('Descartar esta capa? Nada dela será exportado.')) return;
    const j = await acao({acao:'descartar'}); if (j) location.href = '/capa-financeira/';
  });
  const br = document.getElementById('btn-reabrir');
  if (br) br.addEventListener('click', async () => {
    if (!confirm('Reabrir esta capa para edição? Ela sai de "confirmada" até você confirmar de novo.')) return;
    const j = await acao({acao:'reabrir'}); if (j) location.reload();
  });
  const be = document.getElementById('btn-excluir');
  if (be) be.addEventListener('click', async () => {
    if (!confirm('Excluir esta capa de vez? O arquivo e todas as linhas somem — não tem como desfazer (dá pra reenviar a planilha depois).')) return;
    const j = await acao({acao:'excluir'}); if (j) location.href = '/capa-financeira/';
  });
})();
</script>
</div>
<?php portal_footer();
