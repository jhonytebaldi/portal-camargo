/* =====================================================================
   capa-financeira/cf-omie-cad.js — seletor de cadastro do Omie (cliente/fornecedor).
     const c = await CFOmie.escolher({empresa, empresaNome, termo, doc, titulo, csrf})
       → {codigo, nome, doc} escolhido ou cadastrado agora, ou null se cancelou.
   Busca pelo nome ou CPF/CNPJ na lista de cadastros do Omie da empresa (parecidos primeiro);
   se não existir, cadastra ali mesmo (nome ≤ 60, CPF/CNPJ válido, e-mail opcional).
   ===================================================================== */
(() => {
  if (window.CFOmie) return;
  const css = `
.cfo-fundo{position:fixed;inset:0;background:rgba(20,30,25,.45);display:flex;align-items:center;justify-content:center;z-index:900}
.cfo-caixa{background:#fff;border-radius:14px;width:min(760px,94vw);max-height:90vh;overflow:auto;box-shadow:0 18px 60px rgba(0,0,0,.3);padding:20px 22px}
.cfo-caixa h2{margin:0 0 4px;font-size:18px}.cfo-caixa .sub{font-size:13px;color:#667;margin:0 0 12px}
.cfo-busca{display:flex;gap:8px}.cfo-busca input{flex:1;padding:8px 10px;font:inherit;border:2px solid #d9d6cc;border-radius:6px}
.cfo-caixa button{font:inherit;font-size:13.5px;font-weight:600;padding:7px 14px;border-radius:7px;border:1px solid #2f6b4f;background:#fff;color:#2f6b4f;cursor:pointer}
.cfo-caixa button.pri{background:#2f6b4f;color:#fff}
.cfo-lista{margin:12px 0;max-height:300px;overflow:auto}.cfo-lista table{width:100%;border-collapse:collapse;font-size:13.5px}
.cfo-lista td,.cfo-lista th{padding:6px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.cfo-lista th{font-size:11.5px;color:#889;text-transform:uppercase}
.cfo-lista small{color:#889}.cfo-msg{font-size:13px;color:#7a5a00;margin:8px 0}
.cfo-novo{border:1px solid #e3e0d6;border-radius:8px;padding:10px 12px;margin-top:8px}.cfo-novo summary{cursor:pointer;font-weight:600;color:#2f6b4f}
.cfo-novo label{display:block;font-size:12.5px;font-weight:600;margin-top:8px}.cfo-novo input{display:block;width:100%;box-sizing:border-box;padding:7px 9px;font:inherit;border:2px solid #d9d6cc;border-radius:6px;margin-top:3px}
.cfo-rod{display:flex;justify-content:flex-end;margin-top:12px}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  const fmtDoc = d => { d = String(d || '').replace(/\D/g, ''); return d.length === 11 ? d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4') : d.length === 14 ? d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5') : d; };

  function escolher(o) {
    o = o || {};
    const csrf = o.csrf || (document.querySelector('meta[name=csrf]') || {}).content || (window.CV && CV.csrf) || '';
    const post = async body => {
      try {
        const r = await fetch('/capa-financeira/omie-cadastro.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...body, csrf, empresa: o.empresa})});
        return await r.json();
      } catch (e) { return {ok: false, erro: 'sem conexão com o portal'}; }
    };
    return new Promise(res => {
      const f = document.createElement('div'); f.className = 'cfo-fundo';
      f.innerHTML = '<div class="cfo-caixa" role="dialog" aria-modal="true"><h2>' + esc(o.titulo || 'Cadastro no Omie') + '</h2>'
        + '<p class="sub">Cadastros parecidos no Omie da <b>' + esc(o.empresaNome || String(o.empresa || '').toUpperCase()) + '</b>. Escolha o certo — ou cadastre, se não existir.</p>'
        + '<div class="cfo-busca"><input data-busca value="' + esc(o.termo || '') + '" placeholder="nome ou CPF/CNPJ"><button type="button" data-buscar>Buscar</button><button type="button" data-atualizar title="relê os cadastros do Omie (se acabou de cadastrar por lá)">atualizar lista</button></div>'
        + '<div class="cfo-lista" data-lista></div>'
        + '<details class="cfo-novo" data-novo><summary>Não está na lista? Cadastrar no Omie agora</summary>'
        + '<label>Nome completo / razão social (até 60) <input data-nome maxlength="60" value="' + esc((o.termo || '').slice(0, 60)) + '"></label>'
        + '<label>CPF ou CNPJ <input data-doc value="' + esc(fmtDoc(o.doc || '')) + '"></label>'
        + '<label>E-mail (opcional) <input data-email></label>'
        + '<div class="cfo-msg" data-msg></div><button type="button" class="pri" data-cadastrar>Cadastrar no Omie</button></details>'
        + '<div class="cfo-rod"><button type="button" data-cancelar>Cancelar</button></div></div>';
      document.body.appendChild(f);
      const $ = s => f.querySelector(s);
      const fim = v => { document.removeEventListener('keydown', tecla); f.remove(); res(v); };
      const tecla = e => { if (e.key === 'Escape' && !document.querySelector('.cfm-fundo')) fim(null); };
      document.addEventListener('keydown', tecla);
      $('[data-cancelar]').onclick = () => fim(null);
      async function buscar(atualizar) {
        const termo = $('[data-busca]').value.trim(), box = $('[data-lista]');
        if (!termo) { box.innerHTML = '<div class="cfo-msg">digite parte do nome ou o CPF/CNPJ</div>'; return; }
        box.innerHTML = '<div class="cfo-msg">buscando nos cadastros do Omie…</div>';
        const j = await post({acao: 'buscar', termo, atualizar: atualizar ? 1 : 0});
        if (!j.ok) { box.innerHTML = '<div class="cfo-msg">' + esc(j.erro) + '</div>'; return; }
        if (!j.lista.length) { box.innerHTML = '<div class="cfo-msg">nenhum cadastro parecido no Omie — confira a grafia ou cadastre abaixo</div>'; $('[data-novo]').open = true; return; }
        box.innerHTML = '<table><thead><tr><th>Cadastro no Omie</th><th>CPF/CNPJ</th><th>Cód.</th><th></th></tr></thead><tbody>'
          + j.lista.map((c, i) => '<tr><td>' + esc(c.nome) + (c.fantasia && c.fantasia !== c.nome ? '<br><small>' + esc(c.fantasia) + '</small>' : '') + (c.inativo ? ' <small style="color:#b4512f">(inativo)</small>' : '')
            + '</td><td>' + (c.doc ? esc(c.doc) : '<small>sem documento</small>') + '</td><td>' + c.codigo + '</td><td><button type="button" data-usar="' + i + '"' + (c.inativo ? ' disabled' : '') + '>usar este</button></td></tr>').join('') + '</tbody></table>';
        box.querySelectorAll('[data-usar]').forEach(b => b.onclick = () => { const c = j.lista[+b.dataset.usar]; fim({codigo: c.codigo, nome: c.nome, doc: c.doc}); });
      }
      $('[data-buscar]').onclick = () => buscar(false);
      $('[data-atualizar]').onclick = () => buscar(true);
      $('[data-busca]').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); buscar(false); } });
      $('[data-cadastrar]').onclick = async () => {
        const nome = $('[data-nome]').value.trim(), dig = $('[data-doc]').value.replace(/\D/g, ''), email = $('[data-email]').value.trim(), msg = $('[data-msg]');
        if (!nome) { msg.textContent = 'informe o nome'; return; }
        if (dig.length !== 11 && dig.length !== 14) { msg.textContent = 'CPF/CNPJ deve ter 11 ou 14 dígitos'; return; }
        if (!confirm('Cadastrar no Omie da ' + (o.empresaNome || o.empresa) + ':\n\n' + nome + '\n' + fmtDoc(dig) + (email ? '\n' + email : ''))) return;
        $('[data-cadastrar]').disabled = true; msg.textContent = 'cadastrando no Omie…';
        const j = await post({acao: 'cadastrar', nome, doc: dig, email});
        $('[data-cadastrar]').disabled = false;
        if (!j.ok) { msg.textContent = j.erro; return; }
        fim(j.cadastro);
      };
      buscar(false);
      setTimeout(() => $('[data-busca]').focus(), 50);
    });
  }
  /** tenta identificar sozinho pelo nome: {certo: cadastro|null, lista} — "certo" só quando UM cadastro bate com todas as palavras do nome */
  async function identificar(o) {
    const csrf = o.csrf || (document.querySelector('meta[name=csrf]') || {}).content || (window.CV && CV.csrf) || '';
    try {
      const r = await fetch('/capa-financeira/omie-cadastro.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({acao: 'buscar', termo: o.termo, csrf, empresa: o.empresa})});
      const j = await r.json();
      if (!j.ok) return {certo: null, lista: [], erro: j.erro};
      const cheios = j.lista.filter(c => !c.inativo && c.de > 0 && c.pontos === c.de);
      return {certo: cheios.length === 1 ? {codigo: cheios[0].codigo, nome: cheios[0].nome, doc: cheios[0].doc} : null, lista: j.lista};
    } catch (e) { return {certo: null, lista: [], erro: 'sem conexão'}; }
  }
  window.CFOmie = {escolher, identificar};
})();
