<?php
/* capa-financeira/vendas/etapa1.inc.php — etapa 1 (dados da venda). Incluído por editar.php. */
declare(strict_types=1);

$empresas = cf_config('empresas', []);
$construtoras = cv_construtoras();
$bairros = cv_bairros();
$comp = $v['compradores'] ?: [['nome' => '', 'documento' => '', 'email' => '', 'nascimento' => null, 'omie_codigo' => null, 'omie_nome' => null, 'omie_status' => null]];
$brl = fn($x) => $x === null ? '' : number_format((float)$x, 2, ',', '.');
$pct = fn($x) => rtrim(rtrim(number_format((float)$x * 100, 3, ',', ''), '0'), ',');
$e1ok = $v['e1_status'] !== 'rascunho';
?>
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
    <datalist id="cv-lista-bairros">
      <?php foreach ($bairros as $b): ?><option value="<?= h(mb_strtoupper($b['nome'], 'UTF-8')) ?>"><?= h($b['regiao'] . ' · ' . $b['cidade']) ?></option><?php endforeach; ?>
    </datalist>
    <div class="cv-g cv-gend" style="margin-top:10px">
      <label>CEP
        <input name="cep" value="<?= h((string)$v['cep']) ?>" inputmode="numeric" placeholder="89000-000" maxlength="9">
        <span class="cv-sug" data-sug="cep"></span>
      </label>
      <label>Endereço da obra
        <input name="endereco" value="<?= h($v['endereco']) ?>" placeholder="ex.: RUA PERPETUA BORGES CUNHA, 92">
        <span class="cv-sug" data-sug="endereco"></span>
      </label>
      <label>Bairro
        <input name="bairro" list="cv-lista-bairros" value="<?= h($v['bairro']) ?>" placeholder="escolha da lista">
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
      <label>Bônus<input class="cv-num" name="valor_bonus" value="<?= $brl($v['valor_bonus']) ?>" inputmode="decimal" placeholder="0,00"></label>
      <label>Documentação<input class="cv-num" name="documentacao" value="<?= $brl($v['documentacao']) ?>" inputmode="decimal" placeholder="0,00"></label>
      <label>Descontos adicionais<input class="cv-num" name="descontos" value="<?= $brl($v['descontos']) ?>" inputmode="decimal" placeholder="0,00"></label>
    </div>
    <p class="cf-dica">O bônus sai do valor do contrato e é distribuído na etapa 2.</p>
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
  <?php if (!$cancelada): ?>
  <section class="card">
    <div class="cv-botoes">
      <?php if (!$e1ok): ?><button type="button" class="btn cf-btn-sec" id="cv-salvar">Salvar rascunho</button><?php endif; ?>
      <button type="button" class="btn" id="cv-concluir"><?= $e1ok ? 'Salvar alterações' : 'Concluir etapa 1' ?></button>
    </div>
    <?php if ($e1ok && $venda): ?><p class="cf-dica">Etapa 1 concluída em <?= h(substr((string)$venda['e1_em'], 0, 16)) ?> por <?= h((string)$venda['e1_nome']) ?>. <a href="/capa-financeira/vendas/editar.php?id=<?= $id ?>&etapa=2">Ir para a etapa 2 →</a></p><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php cv_aside_comum($venda, $hist, $cancelada); ?>
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
  const comps0 = <?= json_encode(array_map(fn($c) => ['nome' => $c['nome'], 'documento' => $c['documento'] ? Venda::docFormatado($c['documento']) : '', 'email' => $c['email'], 'nascimento' => $c['nascimento'],
                                                    'omie_codigo' => $c['omie_codigo'], 'omie_nome' => $c['omie_nome'], 'omie_status' => $c['omie_status'], 'doc_salvo' => $c['documento']], $comp), JSON_UNESCAPED_UNICODE) ?>;
  const bairros = <?= json_encode(array_values(array_map(fn($b) => mb_strtoupper($b['nome'], 'UTF-8'), $bairros)), JSON_UNESCAPED_UNICODE) ?>;
  const form = document.getElementById('cv-form'), box = document.getElementById('cv-comps'), tpl = document.getElementById('cv-tpl-comp');
  const {esc, brl} = CV;
  const chave = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim();

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
    if (CV.somenteLeitura) el.querySelectorAll('input,button').forEach(x => x.disabled = true);
  }
  async function conferirOmie(el) {
    const doc = el.querySelector('[data-c="documento"]').value.replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    if (!doc) { chip(el, 'esp', '—'); return; }
    if (doc === el.dataset.docSalvo && el.querySelector('[data-omie]').classList.contains('ok')) return;
    chip(el, 'esp', 'conferindo no Omie…');
    const j = await CV.api({acao:'omie', documento: doc, nome: el.querySelector('[data-c="nome"]').value});
    if (!j.ok) { chip(el, 'mal', (j.erros || ['erro']).join(' ')); return; }
    if (j.status === 'invalido') chip(el, 'mal', '✕ CPF/CNPJ inválido');
    else if (j.status === 'ok') chip(el, j.nome_difere ? 'novo' : 'ok', (j.nome_difere ? '⚠ no Omie está como: ' : '✔ Omie Vertical: ') + (j.nome || '') + ' (cód. ' + j.codigo + ')');
    else if (j.status === 'nao') chip(el, 'novo', 'não está no Omie — será cadastrado ao concluir a etapa');
    else if (j.status === 'so_nome') chip(el, 'mal', '⚠ ' + j.msg);
    else chip(el, 'mal', j.msg || j.status);
  }
  comps0.forEach(addComp);
  document.getElementById('cv-add-comp').onclick = () => { addComp({}); mudou(); };

  /* ---------- CEP (ViaCEP, direto do navegador) ---------- */
  const sug = (k, html) => form.querySelector('[data-sug="' + k + '"]').innerHTML = html;
  let ultimoCep = form.cep.value.replace(/\D/g, '');
  async function buscaCep(forcar) {
    const cep = form.cep.value.replace(/\D/g, '');
    if (cep.length === 8) form.cep.value = cep.slice(0, 5) + '-' + cep.slice(5);
    if (cep.length !== 8) { sug('cep', cep ? 'CEP com 8 dígitos' : ''); return; }
    if (cep === ultimoCep && !forcar) return;
    ultimoCep = cep; sug('cep', 'buscando…');
    let j = null;
    try { const r = await fetch('https://viacep.com.br/ws/' + cep + '/json/'); j = await r.json(); } catch (e) {}
    if (!j || j.erro) { sug('cep', 'CEP não encontrado — preencha o endereço à mão'); return; }
    const cidade = (j.localidade || '') + '/' + (j.uf || '');
    const fora = !/^(joinville|araquari)$/i.test(j.localidade || '');
    sug('cep', esc(cidade) + (fora ? ' <b style="color:var(--clay)">— fora de Joinville/Araquari</b>' : ''));
    const rua = (j.logradouro || '').toUpperCase(), bai = (j.bairro || '').toUpperCase();
    const baiLista = bairros.find(b => chave(b) === chave(bai)) || bai;
    const endAtual = form.endereco.value.trim();
    if (rua && !endAtual) { form.endereco.value = rua + ', '; form.endereco.focus(); }
    else if (rua && chave(endAtual).indexOf(chave(rua)) !== 0) sug('endereco', 'CEP indica: ' + esc(rua) + ' <button type="button" data-usar="endereco" data-v="' + esc(rua + ', ') + '">usar</button>');
    if (baiLista && !form.bairro.value.trim()) form.bairro.value = baiLista;
    else if (baiLista && chave(form.bairro.value) !== chave(baiLista)) sug('bairro', 'CEP indica: ' + esc(baiLista) + ' <button type="button" data-usar="bairro" data-v="' + esc(baiLista) + '">usar</button>');
    mudou();
  }
  form.cep.addEventListener('blur', () => buscaCep(false));
  form.addEventListener('click', ev => {
    const b = ev.target.closest('[data-usar]'); if (!b) return;
    const campo = form[b.dataset.usar]; campo.value = b.dataset.v; campo.focus(); b.parentNode.innerHTML = ''; mudou();
  });

  /* ---------- bairro: lista + cadastro de bairro novo ---------- */
  form.bairro.addEventListener('blur', () => {
    const v = form.bairro.value.toUpperCase().replace(/\s+/g, ' ').trim();
    form.bairro.value = bairros.find(b => chave(b) === chave(v)) || v;
  });
  function modalBairro(nome) {
    const m = document.createElement('div'); m.className = 'cv-modal';
    m.innerHTML = '<div><h2 style="margin-top:0">Cadastrar bairro novo?</h2><p><b>' + esc(nome) + '</b> não está na lista de bairros. Só cadastre se tiver certeza da grafia — o bairro passa a valer para todas as vendas.</p>'
      + '<div class="cv-f cv-g cv-g2"><label>Região<select data-r><option>Norte</option><option>Sul</option><option>Leste</option><option>Oeste</option><option>Centro</option><option>Distrito</option><option>—</option></select></label>'
      + '<label>Cidade<select data-c><option>Joinville</option><option>Araquari</option></select></label></div>'
      + '<div class="cv-botoes" style="margin-top:12px"><button type="button" class="btn" data-ok>Cadastrar bairro</button><button type="button" class="btn cf-btn-sec" data-x>Voltar</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-x]').onclick = () => m.remove();
    m.querySelector('[data-ok]').onclick = async () => {
      const j = await CV.api({acao:'add_bairro', nome, regiao: m.querySelector('[data-r]').value, cidade: m.querySelector('[data-c]').value});
      m.remove();
      if (!j.ok) { CV.msg('erro', CV.lista(j.erros || [])); return; }
      const up = j.bairro.nome.toUpperCase(); bairros.push(up);
      const o = document.createElement('option'); o.value = up; o.textContent = j.bairro.regiao + ' · ' + j.bairro.cidade; document.getElementById('cv-lista-bairros').appendChild(o);
      form.bairro.value = up; mudou();
    };
  }

  /* ---------- coleta + prévia ---------- */
  function coleta() {
    const fd = new FormData(form), o = {};
    for (const [k, val] of fd.entries()) o[k] = val;
    o.status_imovel = (form.querySelector('[name=status_imovel]:checked') || {}).value || '';
    o.compradores = [...box.querySelectorAll('.cv-comp')].map(el => { const c = {}; el.querySelectorAll('[data-c]').forEach(i => c[i.dataset.c] = i.value); return c; });
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
    if (s) { put('cod', 'Na Busca: ' + (s.titulo || s.tipo || '')); put('construtora', s.construtora ? 'Na Busca (Robust): ' + s.construtora : '');
             if (!form.querySelector('[data-sug="endereco"] button')) put('endereco', s.endereco ? 'Na Busca (Robust): ' + s.endereco : ''); }
    else { const cod = form.cod_imovel.value.trim(); put('cod', cod ? 'código não encontrado na Busca' : ''); put('construtora', ''); if (!form.querySelector('[data-sug="endereco"] button')) put('endereco', ''); }
    const b = j.bairro, sb = form.querySelector('[data-sug="bairro"]');
    if (!sb.querySelector('button[data-usar]')) {
      if (b && !b.vazio && !b.na_lista) {
        sb.innerHTML = 'fora da lista' + (b.sugestao ? ' — você quis dizer <button type="button" data-usar="bairro" data-v="' + esc(b.sugestao.toUpperCase()) + '">' + esc(b.sugestao.toUpperCase()) + '</button>?' : '')
          + ' <button type="button" data-novo-bairro>cadastrar como novo</button>';
        sb.querySelector('[data-novo-bairro]').onclick = () => modalBairro(form.bairro.value.trim());
      } else sb.innerHTML = s && s.bairro && b && !b.vazio && chave(s.bairro) !== chave(form.bairro.value) ? 'Na Busca (Robust): ' + esc(s.bairro) : '';
    }
  }
  let t = null, seq = 0;
  async function previa() { const n = ++seq; const j = await CV.api({acao:'previa', venda: coleta()}); if (n === seq && j.ok) render(j); }
  function mudou() { CV.sujo = true; clearTimeout(t); t = setTimeout(previa, 350); }
  form.addEventListener('input', mudou); form.addEventListener('change', mudou);
  form.querySelectorAll('.cv-num').forEach(i => i.addEventListener('blur', () => CV.numBlur(i, /^pct_/.test(i.name))));
  ['construtora','endereco','unidade'].forEach(k => form[k].addEventListener('blur', () => form[k].value = form[k].value.toUpperCase().replace(/\s+/g, ' ').trim()));
  if (CV.somenteLeitura) form.querySelectorAll('input,select,textarea,button').forEach(x => x.disabled = true);
  previa();

  /* ---------- salvar / concluir ---------- */
  async function salvar(acao, confirmar) {
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = true);
    const j = await CV.api({acao, venda: coleta(), confirmar_omie: confirmar || []});
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = false);
    if (j.id) { CV.id = j.id; CV.rev = j.rev; history.replaceState(null, '', '/capa-financeira/vendas/editar.php?id=' + CV.id); }
    if (j.ok) return CV.vai(j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : ''));
    if (j.confirmar) return modalOmie(j.confirmar, j.avisos || []);
    CV.msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + CV.lista(j.erros || []));
  }
  function modalOmie(pend, avisos) {
    const m = document.createElement('div'); m.className = 'cv-modal';
    m.innerHTML = '<div><h2 style="margin-top:0">Cadastrar no Omie da Vertical?</h2><p class="cf-dica">Os dados já foram salvos. Para concluir a etapa 1, estes compradores precisam existir no Omie:</p>'
      + pend.map(p => '<div class="cv-pend-omie"><b>' + esc(p.nome) + '</b> · ' + esc(p.doc_fmt) + (p.email ? ' · ' + esc(p.email) : '') + '<br><span class="' + (p.status === 'so_nome' ? 'cv-chip mal' : 'cv-chip novo') + '">' + esc(p.msg) + '</span>'
          + '<label style="display:flex;gap:6px;margin-top:6px;font-size:13px"><input type="checkbox" data-doc="' + esc(p.documento) + '"' + (p.status === 'so_nome' ? '' : ' checked') + '> ' + (p.status === 'so_nome' ? 'É outra pessoa — cadastrar mesmo assim' : 'Cadastrar no Omie da Vertical') + '</label></div>').join('')
      + (avisos.length ? '<p class="cf-dica">' + avisos.map(esc).join('<br>') + '</p>' : '')
      + '<div class="cv-botoes"><button type="button" class="btn" data-ok>Cadastrar e concluir</button><button type="button" class="btn cf-btn-sec" data-x>Voltar</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-x]').onclick = () => { m.remove(); CV.sujo = false; location.href = '/capa-financeira/vendas/editar.php?id=' + CV.id; };
    m.querySelector('[data-ok]').onclick = () => {
      const docs = [...m.querySelectorAll('input[data-doc]:checked')].map(x => x.dataset.doc);
      if (docs.length < pend.length) { alert('Marque todos os compradores para cadastrar — ou volte e corrija os dados (ex.: o documento no Omie).'); return; }
      m.remove(); salvar('concluir', docs);
    };
  }
  const bs = document.getElementById('cv-salvar'); if (bs) bs.onclick = () => salvar('salvar');
  const bc = document.getElementById('cv-concluir'); if (bc) bc.onclick = () => salvar('concluir');
})();
</script>
