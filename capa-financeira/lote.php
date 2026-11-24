<?php
/* =====================================================================
   capa-financeira/lote.php — resolve, de uma vez, os alertas que se
   repetem em várias capas EM REVISÃO (nada é confirmado nem vai ao Omie):
     1. data da venda no histórico com o ano arrastado  → vale a da capa
     2. cliente/construtora com grafia parecida          → vale o da capa
     3. condição fora do dicionário                      → decide uma vez
   Só mexe em linhas em revisão ainda não conferidas; cada ação pede
   confirmação e diz quantas linhas mudou.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();

/** DATA_VENDA_HIST_DIFERE_G3 com o mesmo dia/mês e ano diferente (arrasto do Excel) */
function cfl_arrasto(string $f): bool
{
    return (bool)preg_match('~^DATA_VENDA_HIST_DIFERE_G3 \((\d{1,2}/\d{1,2})/(\d{2,4}) × (\d{1,2}/\d{1,2})/(\d{2,4})\)~u', $f, $m) && $m[1] === $m[3] && $m[2] !== $m[4];
}
/** texto da condição de um alerta de condição fora do dicionário: ['linha'|'bloco', texto] ou null */
function cfl_condicao(string $f): ?array
{
    if (preg_match('~^CONDICAO_DESCONHECIDA(?:_CORTADA)?:(.*)$~us', $f, $m)) return ['linha', trim($m[1])];
    if (preg_match("~^CONDICAO_BLOCO_FORA_DO_DICIONARIO \\('(.*?)' no bloco~us", $f, $m)) return ['bloco', trim($m[1])];
    return null;
}
/** linhas em revisão, ainda não conferidas, de capas em revisão (com os dados da capa) */
function cfl_linhas(PDO $pdo, ?array $capaIds = null): array
{
    $sql = "SELECT l.id, l.capa_id, l.linha_xlsx, l.tipo, l.flags, l.natureza, l.col_g, l.prefixo, l.nota_fiscal,
                   c.cod, c.cliente, c.construtora, c.data_venda
            FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id
            WHERE c.status = 'revisao' AND l.status = 'revisao' AND l.revisado = 0 AND l.flags IS NOT NULL AND l.flags <> '[]'";
    if ($capaIds !== null) {
        $capaIds = array_values(array_unique(array_filter(array_map('intval', $capaIds))));
        if (!$capaIds) return [];
        $sql .= ' AND l.capa_id IN (' . implode(',', $capaIds) . ')';
    }
    $out = [];
    foreach ($pdo->query($sql . ' ORDER BY l.capa_id, l.linha_xlsx')->fetchAll() as $l) { $l['fl'] = json_decode((string)$l['flags'], true) ?: []; $out[] = $l; }
    return $out;
}

/* ---------- ações JSON ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    $acao = (string)($in['acao'] ?? '');
    try {
        $up = $pdo->prepare('UPDATE cf_lancamentos SET flags = ? WHERE id = ?');
        if ($acao === 'vale_capa') {
            // tira o alerta (a informação da capa é a que vale) nas capas marcadas
            $tipo = (string)($in['tipo'] ?? '');
            if (!in_array($tipo, ['data', 'grafia'], true)) $falha('Tipo de alerta inválido — recarregue a página e tente de novo.');
            $linhas = cfl_linhas($pdo, (array)($in['capas'] ?? []));
            if (!$linhas) $falha('Nenhuma capa marcada tem esse alerta pendente — alguém pode já ter resolvido. Recarregue a página.');
            $n = 0; $capas = [];
            $pdo->beginTransaction();
            foreach ($linhas as $l) {
                $novo = array_values(array_filter($l['fl'], fn($f) => $tipo === 'data' ? !cfl_arrasto($f) : !in_array(cf_flag_codigo($f), ['CLIENTES_HIST_GRAFIA', 'CONSTRUTORA_HIST_GRAFIA'], true)));
                if (count($novo) === count($l['fl'])) continue;
                $up->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), (int)$l['id']]); $n++; $capas[(int)$l['capa_id']] = true;
            }
            $pdo->commit();
            if ($n === 0) $falha('Nenhuma linha das capas marcadas tem mais esse alerta — alguém pode já ter resolvido. Recarregue a página.');
            exit(json_encode(['ok' => true, 'msg' => $n . ' linha(s) de ' . count($capas) . ' capa(s) resolvidas: vale ' . ($tipo === 'data' ? 'a data da venda da capa' : 'o nome que está na capa') . '.'], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'condicao') {
            $classe = (string)($in['classe'] ?? ''); $chave = (string)($in['chave'] ?? ''); $op = (string)($in['op'] ?? '');
            if (!in_array($classe, ['linha', 'bloco'], true) || $chave === '') $falha('Condição inválida — recarregue a página e tente de novo.');
            $alvo = fn(string $f) => ($c = cfl_condicao($f)) && $c[0] === $classe && CapaParser::key($c[1]) === $chave;
            $linhas = array_values(array_filter(cfl_linhas($pdo), fn($l) => (bool)array_filter($l['fl'], $alvo)));
            if (!$linhas) $falha('Nenhuma linha pendente tem mais essa condição — alguém pode já ter resolvido. Recarregue a página.');
            $n = 0; $nNf = 0; $extra = '';
            if ($op === 'dicionario') {
                if ($classe !== 'linha') $falha('Texto do bloco de corretores não entra no dicionário por aqui: ele não foi copiado para a Nota Fiscal. Use "ignorar".');
                $codigo = mb_strtoupper(CapaParser::norm((string)($in['codigo'] ?? '')), 'UTF-8');
                if ($codigo === '') $falha('Informe o código que vai para a Nota Fiscal (até 20 caracteres).');
                if (mb_strlen($codigo, 'UTF-8') > 20) $falha('"' . $codigo . '" tem ' . mb_strlen($codigo, 'UTF-8') . ' caracteres; o campo Nota Fiscal do Omie aceita até 20. Encurte o código.');
                $dict = cf_config('nf_dict'); if (!is_array($dict) || !$dict) $dict = CapaParser::NF_DICT_PADRAO;
                $antigo = new CapaParser($dict);
                $dict[$chave] = $codigo;
                $novoP = new CapaParser($dict);
                $nf = function (CapaParser $p, array $l): array {
                    $fl = [];
                    if ($l['tipo'] === 'R') [$v] = $p->buildNf(null, $l['col_g'], null, $fl);
                    elseif ($l['natureza'] === 'REPASSE') $v = 'REPASSE';
                    else [$v] = $p->buildNf($l['natureza'], $l['col_g'], $l['prefixo'], $fl);
                    return [$v, $fl];
                };
                $upNf = $pdo->prepare('UPDATE cf_lancamentos SET flags = ?, nota_fiscal = ? WHERE id = ?');
                $pdo->beginTransaction();
                cf_config_set('nf_dict', $dict);
                foreach ($linhas as $l) {
                    $novo = array_values(array_filter($l['fl'], fn($f) => !$alvo($f)));
                    [$nfAntes] = $nf($antigo, $l); [$nfDepois, $flDepois] = $nf($novoP, $l);
                    // só troca a NF se ela ainda é a que o leitor tinha montado (não mexe no que alguém já editou à mão)
                    if ((string)$l['nota_fiscal'] === (string)$nfAntes && (string)$nfDepois !== (string)$nfAntes) {
                        if (!in_array('NF_CORTADA_20', $flDepois, true)) $novo = array_values(array_filter($novo, fn($f) => $f !== 'NF_CORTADA_20'));
                        $upNf->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), $nfDepois, (int)$l['id']]); $nNf++;
                    } else $up->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), (int)$l['id']]);
                    $n++;
                }
                $pdo->commit();
                $extra = ' Condição adicionada ao dicionário com o código "' . $codigo . '" (vale também para as próximas capas); ' . $nNf . ' linha(s) tiveram a Nota Fiscal refeita' . ($n - $nNf ? ' e ' . ($n - $nNf) . ' ficaram como estavam (NF já editada à mão ou sem mudança)' : '') . '.';
            } elseif ($op === 'manter') {
                $pdo->beginTransaction();
                foreach ($linhas as $l) { $up->execute([json_encode(array_values(array_filter($l['fl'], fn($f) => !$alvo($f))), JSON_UNESCAPED_UNICODE), (int)$l['id']]); $n++; }
                if ($classe === 'bloco' && !empty($in['sempre'])) {
                    $ig = (array)cf_config('cond_ignorar', []);
                    if (!in_array($chave, $ig, true)) { $ig[] = $chave; cf_config_set('cond_ignorar', $ig); }
                    $extra = ' Esse texto não gera mais alerta nas próximas capas.';
                }
                $pdo->commit();
            } else $falha('Operação inválida — recarregue a página e tente de novo.');
            exit(json_encode(['ok' => true, 'msg' => $n . ' linha(s) resolvidas.' . $extra], JSON_UNESCAPED_UNICODE));
        }
        $falha('Ação inválida — recarregue a página e tente de novo.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $falha('Falhou: ' . $e->getMessage() . '. Nada foi alterado. Se for erro do banco (SQLSTATE), rode a migração em Admin › Migração do banco e tente de novo; se persistir, me avise com esta mensagem.', 500);
    }
}

/* ---------- tela ---------- */
$linhas = cfl_linhas($pdo);
$capasInfo = []; $porCapa = [];
$datas = []; $grafias = []; $conds = []; $outros = [];
foreach ($linhas as $l) {
    $cid = (int)$l['capa_id'];
    $capasInfo[$cid] = ['cod' => $l['cod'], 'cliente' => $l['cliente'], 'construtora' => $l['construtora']];
    $temAlerta = false;
    foreach ($l['fl'] as $f) {
        $cod = cf_flag_codigo($f);
        if (in_array($cod, CF_FLAGS_INFO, true)) continue;
        $temAlerta = true;
        if (cfl_arrasto($f)) {
            preg_match('~\((.*?) × (.*?)\)~u', $f, $m);
            $datas[$cid]['par'][$m[1] . ' → ' . $m[2]] = true; $datas[$cid]['n'] = ($datas[$cid]['n'] ?? 0) + 1;
        } elseif ($cod === 'CLIENTES_HIST_GRAFIA' || $cod === 'CONSTRUTORA_HIST_GRAFIA') {
            preg_match("~\\('(.*)'\\)~us", $f, $m);
            $k = $cid . '|' . $cod . '|' . ($m[1] ?? '');
            $grafias[$k] = ['capa' => $cid, 'campo' => $cod === 'CLIENTES_HIST_GRAFIA' ? 'cliente' : 'construtora', 'hist' => $m[1] ?? '', 'n' => ($grafias[$k]['n'] ?? 0) + 1];
        } elseif ($c = cfl_condicao($f)) {
            $k = $c[0] . '|' . CapaParser::key($c[1]);
            $conds[$k] ??= ['classe' => $c[0], 'chave' => CapaParser::key($c[1]), 'texto' => $c[1], 'n' => 0, 'capas' => []];
            $conds[$k]['n']++; $conds[$k]['capas'][$cid] = true;
        } else {
            $outros[$cod] ??= ['n' => 0, 'capas' => [], 'ex' => $f];
            $outros[$cod]['n']++; $outros[$cod]['capas'][$cid] = true;
        }
    }
    if ($temAlerta) $porCapa[$cid] = ($porCapa[$cid] ?? 0) + 1;
}
uasort($conds, fn($a, $b) => $b['n'] <=> $a['n']);
uasort($outros, fn($a, $b) => $b['n'] <=> $a['n']);
asort($porCapa);
$nRev = (int)$pdo->query("SELECT COUNT(*) FROM cf_capas WHERE status = 'revisao'")->fetchColumn();
$capaLink = fn(int $id) => '<a href="/capa-financeira/revisar.php?id=' . $id . '" target="_blank">#' . $id . '</a> ' . h(($capasInfo[$id]['cod'] ? 'COD ' . $capasInfo[$id]['cod'] . ' · ' : '') . mb_substr((string)$capasInfo[$id]['cliente'], 0, 60));

portal_header('Resolver em lote', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1400px}.cfl-sec{margin:0 0 18px;padding:16px 18px}.cfl-sec h2{margin:0 0 4px;font-size:18px}.cfl-n{font-weight:400;color:#667;font-size:14px}.cfl-acoes{margin:10px 0 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center}.cfl-cod{width:190px}</style>
<div class="cf-rev">
<?php cf_cabecalho('Resolver alertas em lote', 'Alertas que se repetem nas ' . $nRev . ' capa(s) em revisão. Cada decisão vale para todas as linhas iguais; nada é confirmado nem enviado ao Omie por aqui.', [['Capa Financeira', '/capa-financeira/'], ['Resolver em lote', null]], 'capas'); ?>

<?php if (!$linhas): ?>
  <p class="home-sub">Nenhuma capa em revisão tem alerta pendente. Envie capas em <a href="/capa-financeira/">Capas</a> (dá para selecionar várias de uma vez).</p>
<?php else: ?>

<section class="card cfl-sec">
  <h2>1. Data da venda com o ano arrastado <span class="cfl-n"><?= count($datas) ?> capa(s) · <?= array_sum(array_column($datas, 'n')) ?> linha(s)</span></h2>
  <p class="cf-dica">O histórico da linha traz a data da venda com o mesmo dia e mês da capa, mas outro ano — é o Excel somando 1 ao arrastar. Marcando, vale a data da venda que está na capa (a linha deixa de pedir conferência; a data da capa não muda).</p>
  <?php if (!$datas): ?><p class="cf-raw">Nada pendente.</p><?php else: ?>
  <div class="cf-tbl-wrap"><table class="grid cf-tbl"><thead><tr><th><input type="checkbox" class="cfl-todos" data-g="data" checked></th><th>Capa</th><th>No histórico → na capa</th><th>Linhas</th></tr></thead><tbody>
  <?php foreach ($datas as $cid => $d): ?><tr><td><input type="checkbox" class="cfl-sel" data-g="data" value="<?= (int)$cid ?>" checked></td><td><?= $capaLink((int)$cid) ?></td><td><?= h(implode(' · ', array_keys($d['par']))) ?></td><td><?= (int)$d['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="cfl-acoes"><button type="button" class="btn cfl-vale" data-tipo="data">Vale a data da capa nas marcadas</button></div>
  <?php endif; ?>
</section>

<section class="card cfl-sec">
  <h2>2. Cliente ou construtora com grafia parecida <span class="cfl-n"><?= count(array_unique(array_column($grafias, 'capa'))) ?> capa(s) · <?= array_sum(array_column($grafias, 'n')) ?> linha(s)</span></h2>
  <p class="cf-dica">O nome no histórico difere do nome da capa por poucas letras (erro de digitação). Confira os pares: marcando, vale o nome que está na capa. Se o certo for o do histórico, desmarque e corrija o nome na revisão da capa.</p>
  <?php if (!$grafias): ?><p class="cf-raw">Nada pendente.</p><?php else: ?>
  <div class="cf-tbl-wrap"><table class="grid cf-tbl"><thead><tr><th><input type="checkbox" class="cfl-todos" data-g="grafia" checked></th><th>Capa</th><th>Campo</th><th>No histórico</th><th>Na capa (vale este)</th><th>Linhas</th></tr></thead><tbody>
  <?php foreach ($grafias as $g): ?><tr><td><input type="checkbox" class="cfl-sel" data-g="grafia" value="<?= (int)$g['capa'] ?>" checked></td><td><?= $capaLink((int)$g['capa']) ?></td><td><?= h($g['campo']) ?></td><td><?= h($g['hist']) ?></td><td><b><?= h((string)$capasInfo[$g['capa']][$g['campo']]) ?></b></td><td><?= (int)$g['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="cfl-acoes"><button type="button" class="btn cfl-vale" data-tipo="grafia">Vale o nome da capa nas marcadas</button> <span class="cf-dica" style="margin:0">Uma capa marcada resolve os dois campos (cliente e construtora) dela.</span></div>
  <?php endif; ?>
</section>

<section class="card cfl-sec">
  <h2>3. Condições fora do dicionário <span class="cfl-n"><?= count($conds) ?> texto(s) · <?= array_sum(array_column($conds, 'n')) ?> linha(s)</span></h2>
  <p class="cf-dica"><b>Na linha</b> (coluna G / prefixo): o texto já foi para a Nota Fiscal como está, cortado em 20 caracteres. Dê um código curto para entrar no dicionário (vale para as próximas capas e refaz a NF destas linhas) ou mantenha como está. <b>No bloco de corretores</b>: o texto não foi copiado para a NF; se for só uma anotação ("já recebeu", "descontado 200"), ignore.</p>
  <?php if (!$conds): ?><p class="cf-raw">Nada pendente.</p><?php else: ?>
  <div class="cf-tbl-wrap"><table class="grid cf-tbl"><thead><tr><th>Texto na capa</th><th>Onde</th><th>Linhas</th><th>Capas</th><th>O que fazer</th></tr></thead><tbody>
  <?php foreach ($conds as $c): $ids = array_keys($c['capas']); ?>
  <tr data-classe="<?= h($c['classe']) ?>" data-chave="<?= h($c['chave']) ?>" data-texto="<?= h($c['texto']) ?>" data-n="<?= (int)$c['n'] ?>">
    <td><b><?= h($c['texto']) ?></b></td><td><?= $c['classe'] === 'linha' ? 'na linha' : 'bloco de corretores' ?></td><td><?= (int)$c['n'] ?></td>
    <td><?= count($ids) ?> <small class="cf-raw">(<?php foreach (array_slice($ids, 0, 4) as $i => $id) echo ($i ? ', ' : '') . '<a href="/capa-financeira/revisar.php?id=' . (int)$id . '" target="_blank">#' . (int)$id . '</a>'; echo count($ids) > 4 ? '…' : ''; ?>)</small></td>
    <td><?php if ($c['classe'] === 'linha'): ?>
      <input type="text" class="cf-in cfl-cod" maxlength="20" value="<?= h(mb_substr($c['chave'], 0, 20)) ?>" title="código que vai para a Nota Fiscal (até 20 caracteres)">
      <button type="button" class="btn cf-btn-sec cfl-dic" style="padding:5px 10px;font-size:13px">adicionar ao dicionário</button>
      <button type="button" class="cf-x cfl-manter">manter como está</button>
    <?php else: ?>
      <button type="button" class="btn cf-btn-sec cfl-manter" style="padding:5px 10px;font-size:13px">não é condição — ignorar</button>
      <label style="font-size:13px"><input type="checkbox" class="cfl-sempre" checked> ignorar também nas próximas capas</label>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section class="card cfl-sec">
  <h2>O que sobra para conferir capa a capa</h2>
  <?php if ($outros): ?>
  <div class="cf-tbl-wrap"><table class="grid cf-tbl"><thead><tr><th>Alerta</th><th>Linhas</th><th>Capas</th></tr></thead><tbody>
  <?php foreach ($outros as $cod => $o): ?><tr><td><?= str_starts_with($o['ex'], 'GRAVE:') ? '<span class="cf-flag cf-grave">grave</span> ' : '' ?><?= h(preg_replace('/[:(].*$/u', '', cf_flag_curto($o['ex'])) ?: $cod) ?></td><td><?= (int)$o['n'] ?></td><td><?= count($o['capas']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="cf-raw">Nenhum outro alerta de linha pendente.</p><?php endif; ?>
  <p class="cf-dica">Capas em revisão, das que têm menos linhas com alerta para as que têm mais (favorecido não identificado, "quem pagou" e avisos da capa aparecem na revisão de cada uma):</p>
  <p><?php foreach ($porCapa as $cid => $n): ?><span style="display:inline-block;margin:2px 10px 2px 0"><?= $capaLink((int)$cid) ?> <small class="cf-raw">(<?= (int)$n ?>)</small></span><?php endforeach; ?></p>
</section>
<?php endif; ?>
</div>
<script>
(() => {
  const csrf = document.querySelector('meta[name=csrf]').content, esc = s => window.CFModal ? CFModal.esc(s) : String(s);
  async function post(body) {
    body.csrf = csrf; let r, j = null;
    try { r = await fetch('/capa-financeira/lote.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}); }
    catch (e) { await CFModal.aviso({tipo: 'erro', titulo: 'Sem conexão com o portal', texto: 'A requisição não chegou ao servidor. Confira sua internet e tente de novo.'}); return null; }
    try { j = await r.json(); } catch (e) {}
    if (!r.ok || !j || !j.ok) { await CFModal.aviso({tipo: 'erro', titulo: 'Não foi possível concluir', texto: j && j.erro ? j.erro : 'Erro ' + r.status + ': resposta inesperada do servidor. Recarregue a página (a sessão pode ter expirado) e tente de novo.'}); return null; }
    return j;
  }
  async function feito(j, titulo) { await CFModal.aviso({tipo: 'ok', titulo, texto: j.msg + ' A página vai recarregar.'}); location.reload(); }
  document.querySelectorAll('.cfl-todos').forEach(t => t.addEventListener('change', () => document.querySelectorAll('.cfl-sel[data-g="' + t.dataset.g + '"]').forEach(c => c.checked = t.checked)));
  document.querySelectorAll('.cfl-vale').forEach(b => b.addEventListener('click', async () => {
    const tipo = b.dataset.tipo, capas = [...new Set([...document.querySelectorAll('.cfl-sel[data-g="' + tipo + '"]:checked')].map(c => +c.value))];
    if (!capas.length) { await CFModal.aviso({tipo: 'info', titulo: 'Nada marcado', texto: 'Marque pelo menos uma capa na lista.'}); return; }
    const oque = tipo === 'data' ? 'a data da venda que está na capa' : 'o nome (cliente/construtora) que está na capa';
    if (!await CFModal.pergunta({titulo: 'Resolver em ' + capas.length + ' capa(s)?', html: '<p style="margin:0">Nas linhas com esse alerta passa a valer <b>' + oque + '</b> e elas deixam de pedir conferência. Os outros alertas dessas linhas continuam.</p>', ok: 'Sim, resolver'})) return;
    b.disabled = true; const j = await post({acao: 'vale_capa', tipo, capas}); b.disabled = false; if (j) feito(j, 'Alertas resolvidos');
  }));
  document.querySelectorAll('.cfl-dic').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'), codigo = tr.querySelector('.cfl-cod').value.trim().toUpperCase();
    if (!codigo) { await CFModal.aviso({tipo: 'info', titulo: 'Falta o código', texto: 'Digite o código curto (até 20 caracteres) que vai para a Nota Fiscal.'}); return; }
    if (!await CFModal.pergunta({titulo: 'Adicionar ao dicionário?', html: '<p style="margin:0">"<b>' + esc(tr.dataset.texto) + '</b>" passa a virar <b>' + esc(codigo) + '</b> na Nota Fiscal — nestas ' + tr.dataset.n + ' linha(s) e nas próximas capas. NF que alguém já editou à mão não é alterada.</p>', ok: 'Sim, adicionar'})) return;
    b.disabled = true; const j = await post({acao: 'condicao', op: 'dicionario', classe: tr.dataset.classe, chave: tr.dataset.chave, codigo}); b.disabled = false; if (j) feito(j, 'Condição adicionada');
  }));
  document.querySelectorAll('.cfl-manter').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'), bloco = tr.dataset.classe === 'bloco', sempre = bloco && tr.querySelector('.cfl-sempre').checked;
    const html = bloco ? '<p style="margin:0">"<b>' + esc(tr.dataset.texto) + '</b>" é só uma anotação do bloco de corretores: as ' + tr.dataset.n + ' linha(s) deixam de pedir conferência por isso e nada vai para a Nota Fiscal.' + (sempre ? ' Nas próximas capas esse texto não gera mais alerta.' : '') + '</p>'
                       : '<p style="margin:0">As ' + tr.dataset.n + ' linha(s) com "<b>' + esc(tr.dataset.texto) + '</b>" ficam com a Nota Fiscal como está hoje e deixam de pedir conferência por isso. O dicionário não muda.</p>';
    if (!await CFModal.pergunta({titulo: bloco ? 'Ignorar esse texto?' : 'Manter como está?', html, ok: 'Sim'})) return;
    b.disabled = true; const j = await post({acao: 'condicao', op: 'manter', classe: tr.dataset.classe, chave: tr.dataset.chave, sempre: sempre ? 1 : 0}); b.disabled = false; if (j) feito(j, 'Alertas resolvidos');
  }));
})();
</script>
<?php portal_footer();
