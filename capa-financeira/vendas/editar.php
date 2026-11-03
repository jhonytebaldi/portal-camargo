<?php
/* =====================================================================
   capa-financeira/vendas/editar.php — formulário da capa de venda.
   Etapa 1 (dados da venda) pronta; etapas 2–4 entram nas próximas fases.
   Cálculos, descrição e validação vêm do servidor (api.php → previa).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
$id = (int)($_GET['id'] ?? 0);
$venda = $id ? cv_carregar($id) : null;
if ($id && !$venda) { http_response_code(404); portal_header('Venda', $u); echo '<p class="aviso">Venda não encontrada.</p>'; portal_footer(); exit; }

$empresas = cf_config('empresas', []);
$construtoras = cv_construtoras();
$hist = $venda ? cv_historico($id, 400) : [];
$v = $venda ?? (['empresa' => '', 'construtora' => '', 'endereco' => '', 'bairro' => '', 'unidade' => '', 'cod_imovel' => '', 'status_imovel' => null, 'data_venda' => null,
                 'valor_contrato' => null, 'valor_bonus' => null, 'documentacao' => null, 'descontos' => null, 'obs' => null, 'e1_status' => 'rascunho',
                 'e2_status' => 'rascunho', 'e3_status' => 'rascunho', 'e4_status' => 'rascunho', 'status' => 'andamento', 'rev' => 0, 'compradores' => []] + Venda::PADROES);
$comp = $v['compradores'] ?: [['nome' => '', 'documento' => '', 'email' => '', 'nascimento' => null, 'omie_codigo' => null, 'omie_nome' => null, 'omie_status' => null]];
$brl = fn($x) => $x === null ? '' : number_format((float)$x, 2, ',', '.');
$pct = fn($x) => rtrim(rtrim(number_format((float)$x * 100, 3, ',', ''), '0'), ',');
$cancelada = $v['status'] === 'cancelada';
$e1ok = $v['e1_status'] !== 'rascunho';

portal_header($venda ? 'Venda #' . $id : 'Nova venda', $u);
?>
<style>
main.wrap{max-width:1400px}
.cv-steps{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 4px}
.cv-step{flex:1;min-width:200px;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:13px;color:var(--mute)}
.cv-step b{display:block;color:var(--ink);font-size:14px}.cv-step.on{border:2px solid var(--moss);background:#fff}.cv-step.off{opacity:.55}
.cv-cols{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px;align-items:start}
@media(max-width:1100px){.cv-cols{grid-template-columns:1fr}}
.cv-lado{position:sticky;top:10px;max-height:calc(100vh - 20px);overflow:auto}
.cv-g{display:grid;gap:10px}.cv-g2{grid-template-columns:1fr 1fr}.cv-g3{grid-template-columns:2fr 1fr 1fr}.cv-g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:700px){.cv-g2,.cv-g3,.cv-g4{grid-template-columns:1fr}}
.cv-f label{display:block;font-size:12.5px;font-weight:600}
.cv-f input,.cv-f select,.cv-f textarea{display:block;width:100%;margin-top:3px;padding:7px 9px;font:inherit;font-size:14px;border:2px solid var(--line);border-radius:6px;background:#fff;box-sizing:border-box}
.cv-f input.cv-num{text-align:right;font-variant-numeric:tabular-nums}
.cv-f input:focus,.cv-f select:focus{border-color:var(--moss);outline:none}
.cv-f input.cv-erro{border-color:var(--clay);background:#fff6f2}
.cv-sug{display:block;font-style:italic;font-size:11.5px;color:var(--mute);font-weight:400;margin-top:2px;min-height:0}
.cv-comp{border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:8px 0;background:#fff}
.cv-comp-top{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700;margin-bottom:6px}
.cv-chip{display:inline-block;font-size:11.5px;font-weight:600;border-radius:10px;padding:1px 8px;margin-top:3px}
.cv-chip.ok{background:#e3ede8;color:var(--moss)}.cv-chip.novo{background:#fff1cc;color:#7a5a00}.cv-chip.mal{background:rgba(180,81,47,.12);color:var(--clay)}.cv-chip.esp{background:#eee;color:var(--mute)}
.cv-radio{display:flex;gap:14px;margin-top:6px}.cv-radio label{font-weight:500;font-size:14px;display:flex;gap:5px;align-items:center}
.cv-radio input{width:auto;margin:0}
.cv-calc{width:100%;border-collapse:collapse;font-size:13px}.cv-calc td{padding:3px 4px;border-bottom:1px solid #ece8dc}
.cv-calc td:last-child{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.cv-calc tr.t td{font-weight:700;background:#efece2}
.cv-calc tr.sub td{color:var(--mute);font-size:12px}
.cv-desc{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;background:#f7f7f4;border:1px solid var(--line);border-radius:6px;padding:8px;word-break:break-word}
.cv-pend ul{margin:4px 0 0 16px;padding:0;font-size:12.5px}.cv-pend .e{color:#7a2f16}.cv-pend .a{color:#5b4a0e}
.cv-botoes{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.cv-hist{font-size:12px}.cv-hist li{margin:3px 0}.cv-hist .q{color:var(--mute)}
.cv-modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:30}
.cv-modal>div{background:#fff;border-radius:10px;max-width:640px;width:92%;padding:18px 20px;box-shadow:0 10px 40px rgba(0,0,0,.25);max-height:90vh;overflow:auto}
.cv-pend-omie{border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:8px 0;font-size:13px}
details.cv-par summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--moss)}
.cv-msg{padding:10px 14px;border-radius:6px;margin:10px 0;font-size:14px}.cv-msg.ok{background:#e6f4ea;color:#1e5b34}.cv-msg.erro{background:rgba(180,81,47,.12);color:#7a2f16}
</style>
<?php cf_cabecalho($venda ? 'Venda #' . $id . ($cancelada ? ' <span class="cf-status cf-st-descartada">cancelada</span>' : '') : 'Nova venda',
    'Preencha por partes: dá pra salvar rascunho a qualquer momento e continuar depois. Cada alteração fica registrada no histórico com o seu nome.',
    [['Capa Financeira', '/capa-financeira/'], ['Vendas', '/capa-financeira/vendas/'], [$venda ? '#' . $id : 'Nova', null]], 'vendas'); ?>

<?php if (!empty($_GET['ok'])): ?><div class="cv-msg ok">✔ <?= h((string)$_GET['ok']) ?></div><?php endif; ?>
<div id="cv-msg"></div>

<div class="cv-steps">
  <div class="cv-step on"><b>1 · Dados da venda</b><?= cv_etapa_badge($v['e1_status']) ?></div>
  <div class="cv-step off"><b>2 · Distribuição das comissões</b>em construção</div>
  <div class="cv-step off"><b>3 · Fluxo do cliente e recebidos</b>em construção</div>
  <div class="cv-step off"><b>4 · Pagamentos aos corretores</b>em construção</div>
</div>

<div class="cv-cols">
<form id="cv-form" class="cv-f" autocomplete="off" onsubmit="return false">
  <section class="card">
    <h2>Empresa da venda</h2>
    <div class="cv-g cv-g2">
      <label>As contas desta venda vão para
        <select name="empresa">
          <option value="">— escolha —</option>
          <?php foreach ($empresas as $e): ?><option value="<?= h($e['id']) ?>"<?= $v['empresa'] === $e['id'] ? ' selected' : '' ?>><?= h($e['nome']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <p class="cf-dica" style="margin-top:22px">Os compradores são sempre conferidos e cadastrados no Omie da <b>Vertical</b>.</p>
    </div>
  </section>

  <section class="card">
    <h2>Compradores</h2>
    <div id="cv-comps"></div>
    <button type="button" class="btn cf-btn-sec" id="cv-add-comp">+ comprador</button>
    <p class="cf-dica">CPF ou CNPJ no mesmo campo. E-mail obrigatório para pelo menos 1 comprador; nascimento é opcional.</p>
  </section>

  <section class="card">
    <h2>Imóvel</h2>
    <div class="cv-g cv-g3">
      <label>Construtora
        <input name="construtora" list="cv-lista-construtoras" value="<?= h($v['construtora']) ?>" placeholder="escolha da lista sempre que existir">
        <span class="cv-sug" data-sug="construtora"></span>
      </label>
      <label>Cód. do imóvel (Robust)
        <input name="cod_imovel" value="<?= h($v['cod_imovel']) ?>" inputmode="numeric" placeholder="ex.: 933">
        <span class="cv-sug" data-sug="cod"></span>
      </label>
      <label>Data da venda
        <input name="data_venda" type="date" value="<?= h((string)$v['data_venda']) ?>">
      </label>
    </div>
    <datalist id="cv-lista-construtoras">
      <?php foreach ($construtoras as $c): ?><option value="<?= h($c['nome']) ?>"><?= $c['origem'] === 'capas' ? 'capas anteriores (' . (int)$c['n'] . ')' : 'cadastro do Omie' ?></option><?php endforeach; ?>
    </datalist>
    <div class="cv-g cv-g3" style="margin-top:10px">
      <label>Endereço da obra
        <input name="endereco" value="<?= h($v['endereco']) ?>" placeholder="ex.: RUA PERPETUA BORGES CUNHA, 92">
        <span class="cv-sug" data-sug="endereco"></span>
      </label>
      <label>Bairro
        <input name="bairro" value="<?= h($v['bairro']) ?>" placeholder="ex.: JARIVATUBA">
        <span class="cv-sug" data-sug="bairro"></span>
      </label>
      <label>Unidade
        <input name="unidade" value="<?= h($v['unidade']) ?>" placeholder="ex.: APTO 302 · CASA 2 · 302">
      </label>
    </div>
    <div style="margin-top:10px"><label>Situação do imóvel</label>
      <div class="cv-radio">
        <?php foreach (['PLANTA', 'PRONTO'] as $s): ?><label><input type="radio" name="status_imovel" value="<?= $s ?>"<?= $v['status_imovel'] === $s ? ' checked' : '' ?>> <?= $s ?></label><?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Valores da negociação</h2>
    <div class="cv-g cv-g4">
      <label>Valor do contrato<input class="cv-num" name="valor_contrato" value="<?= $brl($v['valor_contrato']) ?>" inputmode="decimal" placeholder="0,00"></label>
      <label>Bônus (venda maior)<input class="cv-num" name="valor_bonus" value="<?= $brl($v['valor_bonus']) ?>" inputmode="decimal" placeholder="0,00"></label>
      <label>Documentação<input class="cv-num" name="documentacao" value="<?= $brl($v['documentacao']) ?>" inputmode="decimal" placeholder="0,00"></label>
      <label>Descontos adicionais<input class="cv-num" name="descontos" value="<?= $brl($v['descontos']) ?>" inputmode="decimal" placeholder="0,00"></label>
    </div>
    <p class="cf-dica">O bônus (venda maior) sai do valor do contrato e vira o bônus distribuído na etapa 2.</p>
    <details class="cv-par"<?= array_filter(array_keys(Venda::PADROES), fn($k) => abs((float)$v[$k] - Venda::PADROES[$k]) > 1e-9) ? ' open' : '' ?>>
      <summary>Percentuais e descontos padrão (5% · 0,5% · NF 14% · brinde R$ 300 · cesta R$ 120)</summary>
      <div class="cv-g cv-g3" style="margin-top:8px">
        <label>% comissão corretores<input class="cv-num" name="pct_corretores" value="<?= $pct($v['pct_corretores']) ?>" inputmode="decimal"></label>
        <label>% fundo de MKT (imob.)<input class="cv-num" name="pct_fundo" value="<?= $pct($v['pct_fundo']) ?>" inputmode="decimal"></label>
        <label>% desconto NF<input class="cv-num" name="pct_nf" value="<?= $pct($v['pct_nf']) ?>" inputmode="decimal"></label>
        <label>% NF do bônus<input class="cv-num" name="pct_nf_bonus" value="<?= $pct($v['pct_nf_bonus']) ?>" inputmode="decimal"></label>
        <label>Desconto brinde (R$)<input class="cv-num" name="brinde" value="<?= $brl($v['brinde']) ?>" inputmode="decimal"></label>
        <label>Desconto cesta básica (R$)<input class="cv-num" name="cesta" value="<?= $brl($v['cesta']) ?>" inputmode="decimal"></label>
      </div>
    </details>
    <label style="margin-top:10px">Observação interna (opcional)<textarea name="obs" rows="2"><?= h((string)$v['obs']) ?></textarea></label>
  </section>
</form>

<aside class="cv-lado">
  <section class="card">
    <h2>Cálculo da comissão</h2>
    <table class="cv-calc" id="cv-calc"></table>
  </section>
  <section class="card">
    <h2>Descrição padrão das contas</h2>
    <div class="cv-desc" id="cv-desc">—</div>
    <p class="cf-dica">Vai em todas as contas a receber. Nas contas a pagar entra depois de <code>RECIBO XXXX - NOME - CARGO - COMISSAO -</code>.</p>
  </section>
  <section class="card cv-pend" id="cv-pend"></section>
  <section class="card">
    <div class="cv-botoes">
      <?php if (!$cancelada): ?>
        <?php if (!$e1ok): ?><button type="button" class="btn cf-btn-sec" id="cv-salvar">Salvar rascunho</button><?php endif; ?>
        <button type="button" class="btn" id="cv-concluir"><?= $e1ok ? 'Salvar alterações' : 'Concluir etapa 1' ?></button>
      <?php endif; ?>
      <?php if ($venda): ?><a class="btn cf-btn-sec" href="/capa-financeira/vendas/xlsx.php?id=<?= $id ?>">⬇ Baixar .xlsx</a><?php endif; ?>
    </div>
    <?php if ($venda): ?>
      <p class="cf-dica">Criada em <?= h(substr((string)$venda['criado_em'], 0, 16)) ?> por <?= h((string)$venda['criado_nome']) ?> · última edição <?= h(substr((string)$venda['atualizado_em'], 0, 16)) ?> por <?= h((string)$venda['atualizado_nome']) ?><?= $e1ok ? ' · etapa 1 concluída em ' . h(substr((string)$venda['e1_em'], 0, 16)) . ' por ' . h((string)$venda['e1_nome']) : '' ?></p>
      <?php if (!$cancelada): ?><p><button type="button" class="cf-x" id="cv-cancelar">cancelar esta venda</button></p><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php if ($hist): ?>
  <section class="card">
    <h2>Histórico de alterações</h2>
    <ul class="cv-hist">
      <?php foreach (array_slice($hist, 0, 40) as $hh): ?>
        <li><span class="q"><?= h(date('d/m H:i', strtotime($hh['quando']))) ?> · <?= h($hh['user_nome']) ?></span> —
          <?php if ($hh['acao'] === 'criar'): ?>criou a venda
          <?php elseif ($hh['acao'] === 'concluir'): ?><b>concluiu a etapa <?= (int)$hh['etapa'] ?></b>
          <?php elseif ($hh['acao'] === 'reabrir'): ?><b>etapa <?= (int)$hh['etapa'] ?> voltou a rascunho</b> (<?= h((string)$hh['depois']) ?>)
          <?php elseif ($hh['acao'] === 'cancelar'): ?><b>cancelou a venda</b> (<?= h((string)$hh['depois']) ?>)
          <?php elseif (in_array($hh['acao'], ['omie_cadastro', 'omie_vinculo'], true)): ?><?= h((string)$hh['campo']) ?>: <?= h((string)$hh['depois']) ?>
          <?php elseif ($hh['acao'] === 'xlsx'): ?>baixou a planilha
          <?php else: ?><?= h((string)$hh['campo']) ?>: <?= $hh['antes'] !== null && $hh['antes'] !== '' ? '<s>' . h($hh['antes']) . '</s> → ' : '' ?><?= h((string)$hh['depois']) !== '' ? h((string)$hh['depois']) : '<i>(vazio)</i>' ?>
          <?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($hist) > 40): ?><p class="cf-dica">Mostrando as 40 mais recentes de <?= count($hist) ?>.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</aside>
</div>

<template id="cv-tpl-comp">
  <div class="cv-comp">
    <div class="cv-comp-top"><span class="n">Comprador</span><button type="button" class="cf-x cv-rem" title="remover este comprador">✕</button></div>
    <div class="cv-g cv-g2">
      <label>Nome completo<input data-c="nome"></label>
      <label>CPF ou CNPJ<input data-c="documento" inputmode="numeric"><span class="cv-chip esp" data-omie>—</span></label>
      <label>E-mail<input data-c="email" type="email"></label>
      <label>Data de nascimento (opcional)<input data-c="nascimento" type="date"></label>
    </div>
  </div>
</template>

<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  let id = <?= (int)$id ?>, rev = <?= (int)$v['rev'] ?>;
  const somenteLeitura = <?= $cancelada ? 'true' : 'false' ?>;
  const comps0 = <?= json_encode(array_map(fn($c) => ['nome' => $c['nome'], 'documento' => $c['documento'] ? Venda::docFormatado($c['documento']) : '', 'email' => $c['email'], 'nascimento' => $c['nascimento'],
                                                    'omie_codigo' => $c['omie_codigo'], 'omie_nome' => $c['omie_nome'], 'omie_status' => $c['omie_status'], 'doc_salvo' => $c['documento']], $comp), JSON_UNESCAPED_UNICODE) ?>;
  const form = document.getElementById('cv-form');
  const box = document.getElementById('cv-comps');
  const tpl = document.getElementById('cv-tpl-comp');
  const brl = v => (v < 0 ? '−' : '') + 'R$ ' + Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const pc = v => (v * 100).toFixed(2).replace('.', ',') + '%';
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  let sujo = false;

  async function api(dados) {
    const r = await fetch('/capa-financeira/vendas/api.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify({...dados, csrf, id, rev})});
    try { return await r.json(); } catch (e) { return {ok:false, erros:['Resposta inválida do servidor (HTTP ' + r.status + ')']}; }
  }

  /* ---------- compradores ---------- */
  function docMask(s) {
    const d = s.replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    if (/^\d{11}$/.test(d)) return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    if (/^[0-9A-Z]{12}\d{2}$/.test(d)) return d.replace(/(..)(...)(...)(....)(..)/, '$1.$2.$3/$4-$5');
    return s;
  }
  function renumera() { box.querySelectorAll('.cv-comp').forEach((el, i) => el.querySelector('.n').textContent = 'Comprador ' + String(i + 1).padStart(2, '0')); }
  function chip(el, cls, txt) { const c = el.querySelector('[data-omie]'); c.className = 'cv-chip ' + cls; c.textContent = txt; }
  function addComp(c) {
    const el = tpl.content.firstElementChild.cloneNode(true);
    for (const k of ['nome','documento','email','nascimento']) el.querySelector('[data-c="' + k + '"]').value = c[k] || '';
    el.dataset.docSalvo = c.doc_salvo || '';
    if (c.omie_codigo) chip(el, 'ok', '✔ Omie Vertical: ' + (c.omie_nome || '') + ' (cód. ' + c.omie_codigo + ')' + (c.omie_status === 'cadastrado' ? ' — cadastrado pelo portal' : ''));
    else chip(el, 'esp', c.documento ? 'ainda não conferido no Omie' : '—');
    el.querySelector('.cv-rem').onclick = () => { if (box.children.length > 1 || confirm('Remover o único comprador?')) { el.remove(); renumera(); mudou(); } };
    const doc = el.querySelector('[data-c="documento"]');
    doc.addEventListener('blur', () => { doc.value = docMask(doc.value); conferirOmie(el); });
    el.querySelector('[data-c="nome"]').addEventListener('blur', e => { e.target.value = e.target.value.toUpperCase().replace(/\s+/g, ' ').trim(); });
    box.appendChild(el); renumera();
    if (somenteLeitura) el.querySelectorAll('input,button').forEach(x => x.disabled = true);
  }
  async function conferirOmie(el) {
    const doc = el.querySelector('[data-c="documento"]').value.replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    if (!doc) { chip(el, 'esp', '—'); return; }
    if (doc === el.dataset.docSalvo && el.querySelector('[data-omie]').classList.contains('ok')) return;
    chip(el, 'esp', 'conferindo no Omie…');
    const j = await api({acao:'omie', documento: doc, nome: el.querySelector('[data-c="nome"]').value});
    if (!j.ok) { chip(el, 'mal', (j.erros || ['erro']).join(' ')); return; }
    if (j.status === 'invalido') chip(el, 'mal', '✕ CPF/CNPJ inválido');
    else if (j.status === 'ok') chip(el, j.nome_difere ? 'novo' : 'ok', (j.nome_difere ? '⚠ no Omie está como: ' : '✔ Omie Vertical: ') + (j.nome || '') + ' (cód. ' + j.codigo + ')');
    else if (j.status === 'nao') chip(el, 'novo', 'não está no Omie — será cadastrado ao concluir a etapa');
    else if (j.status === 'so_nome') chip(el, 'mal', '⚠ ' + j.msg);
    else chip(el, 'mal', j.msg || j.status);
  }
  comps0.forEach(addComp);
  document.getElementById('cv-add-comp').onclick = () => { addComp({}); mudou(); };

  /* ---------- coleta + prévia ---------- */
  function coleta() {
    const fd = new FormData(form), o = {};
    for (const [k, val] of fd.entries()) o[k] = val;
    o.status_imovel = (form.querySelector('[name=status_imovel]:checked') || {}).value || '';
    o.compradores = [...box.querySelectorAll('.cv-comp')].map(el => {
      const c = {}; el.querySelectorAll('[data-c]').forEach(i => c[i.dataset.c] = i.value); return c;
    });
    return o;
  }
  const L = [['valor_calculo','Valor p/ cálculo da comissão (VGV)','t'],['comissao_base','Comissão base corretores'],['fundo','Fundo de MKT (imobiliária)'],
             ['comissao_total','Comissão total','t'],['desconto_nf','− Desconto nota fiscal','sub'],['brinde','− Desconto brinde','sub'],['cesta','− Desconto cesta básica','sub'],
             ['valor_final','Valor final p/ cálculo das comissões','t'],['bonus','Bônus'],['desconto_nf_bonus','− Desconto NF do bônus','sub'],['bonus_distribuicao','Bônus p/ distribuição','t'],
             ['comissao_mais_bonus','Comissão + bônus (o cliente paga à imobiliária)','t']];
  function render(j) {
    document.getElementById('cv-calc').innerHTML = L.map(([k, rot, cls]) => '<tr class="' + (cls || '') + '"><td>' + rot + '</td><td>' + brl(j.calc[k] || 0) + '</td></tr>').join('');
    document.getElementById('cv-desc').textContent = j.descricao || '—';
    const p = document.getElementById('cv-pend');
    p.innerHTML = (j.erros.length ? '<b class="e">Falta para concluir a etapa 1:</b><ul>' + j.erros.map(e => '<li class="e">' + esc(e) + '</li>').join('') + '</ul>' : '<b style="color:var(--moss)">✔ Etapa 1 pronta para concluir</b>')
      + (j.avisos.length ? '<b class="a" style="display:block;margin-top:8px">Atenção:</b><ul>' + j.avisos.map(e => '<li class="a">' + esc(e) + '</li>').join('') + '</ul>' : '');
    const s = j.sugestao, put = (k, t) => form.querySelector('[data-sug="' + k + '"]').textContent = t;
    if (s) { put('cod', 'Na Busca: ' + (s.titulo || s.tipo || '')); put('construtora', s.construtora ? 'Na Busca (Robust): ' + s.construtora : ''); put('endereco', s.endereco ? 'Na Busca (Robust): ' + s.endereco : ''); put('bairro', s.bairro ? 'Na Busca (Robust): ' + s.bairro : ''); }
    else { const cod = form.cod_imovel.value.trim(); put('cod', cod ? 'código não encontrado na Busca' : ''); ['construtora','endereco','bairro'].forEach(k => put(k, '')); }
  }
  let t = null, seq = 0;
  async function previa() { const n = ++seq; const j = await api({acao:'previa', venda: coleta()}); if (n === seq && j.ok) render(j); }
  function mudou() { sujo = true; clearTimeout(t); t = setTimeout(previa, 350); }
  form.addEventListener('input', mudou); form.addEventListener('change', mudou);
  form.querySelectorAll('.cv-num').forEach(i => i.addEventListener('blur', () => {
    const pctCampo = /^pct_/.test(i.name); let s = i.value.replace(/[R$\s%]/g, '');
    if (s === '') return;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    const n = Number(s); if (!isFinite(n)) { i.classList.add('cv-erro'); return; } i.classList.remove('cv-erro');
    i.value = pctCampo ? String(+n.toFixed(3)).replace('.', ',') : n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }));
  ['construtora','endereco','bairro','unidade'].forEach(k => form[k].addEventListener('blur', () => form[k].value = form[k].value.toUpperCase().replace(/\s+/g, ' ').trim()));
  window.addEventListener('beforeunload', e => { if (sujo) { e.preventDefault(); e.returnValue = ''; } });
  if (somenteLeitura) form.querySelectorAll('input,select,textarea,button').forEach(x => x.disabled = true);
  previa();

  /* ---------- salvar / concluir ---------- */
  const msg = (cls, html) => { const m = document.getElementById('cv-msg'); m.innerHTML = '<div class="cv-msg ' + cls + '">' + html + '</div>'; m.scrollIntoView({block:'center'}); };
  const lista = a => '<ul>' + a.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>';
  function vai(j, texto) { sujo = false; location.href = '/capa-financeira/vendas/editar.php?id=' + j.id + '&ok=' + encodeURIComponent(texto || j.msg || 'Salvo.'); }
  async function salvar(acao, confirmar) {
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = true);
    const j = await api({acao, venda: coleta(), confirmar_omie: confirmar || []});
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = false);
    if (j.id) { id = j.id; rev = j.rev; history.replaceState(null, '', '/capa-financeira/vendas/editar.php?id=' + id); }
    if (j.ok) return vai(j, j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : ''));
    if (j.confirmar) return modalOmie(j.confirmar, j.avisos || []);
    msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + lista(j.erros || []));
  }
  function modalOmie(pend, avisos) {
    const m = document.createElement('div'); m.className = 'cv-modal';
    m.innerHTML = '<div><h2 style="margin-top:0">Cadastrar no Omie da Vertical?</h2><p class="cf-dica">Os dados já foram salvos. Para concluir a etapa 1, estes compradores precisam existir no Omie:</p>'
      + pend.map((p, i) => '<div class="cv-pend-omie"><b>' + esc(p.nome) + '</b> · ' + esc(p.doc_fmt) + (p.email ? ' · ' + esc(p.email) : '') + '<br><span class="' + (p.status === 'so_nome' ? 'cv-chip mal' : 'cv-chip novo') + '">' + esc(p.msg) + '</span>'
          + '<label style="display:flex;gap:6px;margin-top:6px;font-size:13px"><input type="checkbox" data-doc="' + esc(p.documento) + '"' + (p.status === 'so_nome' ? '' : ' checked') + '> ' + (p.status === 'so_nome' ? 'É outra pessoa — cadastrar mesmo assim' : 'Cadastrar no Omie da Vertical') + '</label></div>').join('')
      + (avisos.length ? '<p class="cf-dica">' + avisos.map(esc).join('<br>') + '</p>' : '')
      + '<div class="cv-botoes"><button type="button" class="btn" data-ok>Cadastrar e concluir</button><button type="button" class="btn cf-btn-sec" data-x>Voltar</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-x]').onclick = () => { m.remove(); location.href = '/capa-financeira/vendas/editar.php?id=' + id; };
    m.querySelector('[data-ok]').onclick = () => {
      const docs = [...m.querySelectorAll('input[data-doc]:checked')].map(x => x.dataset.doc);
      if (docs.length < pend.length) { alert('Marque todos os compradores para cadastrar — ou volte e corrija os dados (ex.: o documento no Omie).'); return; }
      m.remove(); salvar('concluir', docs);
    };
  }
  const bs = document.getElementById('cv-salvar'); if (bs) bs.onclick = () => salvar('salvar');
  const bc = document.getElementById('cv-concluir'); if (bc) bc.onclick = () => salvar('concluir');
  const bx = document.getElementById('cv-cancelar');
  if (bx) bx.onclick = async () => {
    const motivo = prompt('Cancelar esta venda? Ela sai da lista (o histórico fica guardado). Informe o motivo:');
    if (!motivo) return;
    const j = await api({acao:'cancelar', motivo});
    if (j.ok) { sujo = false; location.href = '/capa-financeira/vendas/'; } else msg('erro', lista(j.erros || []));
  };
})();
</script>
<?php portal_footer();
