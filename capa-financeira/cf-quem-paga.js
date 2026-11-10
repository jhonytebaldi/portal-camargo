/* =====================================================================
   capa-financeira/cf-quem-paga.js — pergunta "Quem pagou as parcelas?" de uma capa enviada (.xlsx).
     const r = await CFQuemPaga.abrir(ctx, csrf)   // ctx = cf_qp_contexto() do PHP
       → resposta do servidor ({ok, alteradas, pos_exportacao, pendencias, quem_paga}) ou null se cancelou
   ===================================================================== */
(() => {
  if (window.CFQuemPaga) return;
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  const brl = v => 'R$ ' + (+v || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const dbr = d => d ? d.split('-').reverse().join('/') : '—';
  const st = document.createElement('style');
  st.textContent = `.cfq-fundo{position:fixed;inset:0;background:rgba(20,30,25,.45);display:flex;align-items:center;justify-content:center;z-index:800}
.cfq-caixa{background:#fff;border-radius:14px;width:min(720px,94vw);max-height:90vh;overflow:auto;box-shadow:0 18px 60px rgba(0,0,0,.3);padding:20px 22px;font-size:14px}
.cfq-caixa h2{margin:0 0 6px;font-size:19px}.cfq-sug{background:#e6f4ea;color:#1e5b34;border-radius:6px;padding:8px 12px;margin:8px 0}
.cfq-op{display:flex;gap:8px;align-items:flex-start;border:2px solid #d9d6cc;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:600;margin:6px 0}
.cfq-op.on{border-color:#2f6b4f;background:#f1f7f3}.cfq-op small{font-weight:400;color:#667;display:block}.cfq-op input{margin-top:3px}
.cfq-bloco{border:1px solid #e3e0d6;border-radius:8px;padding:10px 12px;margin:8px 0}
.cfq-caixa table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}.cfq-caixa td,.cfq-caixa th{padding:4px 6px;border-bottom:1px solid #eee;text-align:left}
.cfq-caixa button{font:inherit;font-size:13.5px;font-weight:600;padding:8px 16px;border-radius:7px;border:1px solid #2f6b4f;background:#fff;color:#2f6b4f;cursor:pointer}
.cfq-caixa button.pri{background:#2f6b4f;color:#fff}.cfq-dica{font-size:12.5px;color:#667}.cfq-rod{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}`;
  document.head.appendChild(st);

  function abrir(ctx, csrf) {
    return new Promise(res => {
      const base = ctx.atual || ctx.anterior;
      let modo = base ? base.modo : (ctx.sugestao && ctx.sugestao.modo !== 'varia' ? ctx.sugestao.modo : '');
      let cad = (base && base.cad) || (ctx.sugestao && ctx.sugestao.cadastro) || null, cadAuto = false;
      const sinal = new Set(ctx.atual && ctx.atual.modo === 'construtora' ? ctx.atual.sinal_ids : (ctx.sugestao && ctx.sugestao.sinal === 'construtora' ? [] : ctx.linhas.filter(l => l.sinal_padrao).map(l => l.id)));
      const noOmie = ctx.linhas.filter(l => l.status === 'exportado').length;
      const f = document.createElement('div'); f.className = 'cfq-fundo';
      const fim = v => { f.remove(); res(v); };
      function desenha() {
        f.innerHTML = '<div class="cfq-caixa"><h2>Quem pagou as parcelas?</h2><div class="cfq-dica">Capa ' + esc(ctx.cliente) + ' × ' + esc(ctx.construtora || '(sem construtora)') + ' — ' + ctx.linhas.length + ' conta(s) a receber.</div>'
          + (ctx.sugestao ? '<div class="cfq-sug">⭐ <b>Sugestão:</b> ' + esc(ctx.sugestao.texto) + '</div>' : (ctx.anterior ? '<div class="cfq-sug">Na versão anterior desta capa a resposta foi: <b>' + (ctx.anterior.modo === 'construtora' ? 'construtora' : 'cliente') + '</b>.</div>' : '<p class="cfq-dica">Sem histórico desta construtora — a resposta passa a ser a sugestão das próximas capas dela.</p>'))
          + '<label class="cfq-op' + (modo === 'cliente' ? ' on' : '') + '"><input type="radio" name="cfq" value="cliente"' + (modo === 'cliente' ? ' checked' : '') + '><span>O cliente pagou direto<small>contas a receber no nome do comprador (' + esc(ctx.cliente) + ')</small></span></label>'
          + '<label class="cfq-op' + (modo === 'construtora' ? ' on' : '') + '"><input type="radio" name="cfq" value="construtora"' + (modo === 'construtora' ? ' checked' : '') + '><span>A construtora recebeu e repassou<small>contas a receber no nome da construtora</small></span></label>'
          + (modo === 'construtora' ? '<div class="cfq-bloco"><b>Cadastro da construtora no Omie:</b> ' + (cad ? esc(cad.nome) + ' · ' + esc(cad.doc || 'sem documento') + (cadAuto ? ' <span class="cfq-dica">(identificado pelo nome — confira)</span>' : '') : '<span style="color:#b4512f">não escolhido</span>')
              + ' <button type="button" data-cad>' + (cad ? 'trocar' : '🔍 escolher / cadastrar') + '</button>'
              + '<p style="margin:10px 0 2px"><b>Quais destas vieram direto do cliente (sinal/arras)?</b> <span class="cfq-dica">as marcadas continuam no nome do comprador</span></p>'
              + '<table><thead><tr><th></th><th>Vencimento</th><th>Valor</th><th>Situação</th></tr></thead><tbody>'
              + ctx.linhas.map(l => '<tr><td><input type="checkbox" data-sinal="' + l.id + '"' + (sinal.has(l.id) ? ' checked' : '') + '></td><td>' + dbr(l.data) + '</td><td>' + brl(l.valor) + '</td><td>' + (l.status === 'exportado' ? 'no Omie ' + esc(l.codigo || '') : l.status === 'confirmado' ? 'confirmada' : 'em revisão') + '</td></tr>').join('')
              + '</tbody></table></div>' : '')
          + (noOmie ? '<p class="cfq-dica">' + noOmie + ' conta(s) desta capa já estão no Omie: as que mudarem de cliente entram em <b>Exportar p/ Omie → Alterações depois da exportação</b>, para aplicar pela API (as já baixadas ficam para ajuste manual).</p>' : '')
          + '<div class="cfq-rod"><button type="button" data-x>Cancelar</button><button type="button" class="pri" data-ok>Confirmar</button></div></div>';
        f.querySelectorAll('input[name=cfq]').forEach(r => r.onchange = () => { modo = r.value; desenha(); if (modo === 'construtora') auto(); });
        f.querySelectorAll('[data-sinal]').forEach(c => c.onchange = () => c.checked ? sinal.add(+c.dataset.sinal) : sinal.delete(+c.dataset.sinal));
        const bc = f.querySelector('[data-cad]'); if (bc) bc.onclick = async () => { const c = await CFOmie.escolher({empresa: ctx.empresa, empresaNome: 'Vertical / Camargo', termo: cad ? cad.nome : ctx.construtora, titulo: 'Cadastro da construtora no Omie', csrf}); if (c) { cad = c; cadAuto = false; desenha(); } };
        f.querySelector('[data-x]').onclick = () => fim(null);
        f.querySelector('[data-ok]').onclick = async () => {
          if (!modo) { alert('Escolha quem pagou as parcelas.'); return; }
          if (modo === 'construtora' && !cad) { alert('Escolha o cadastro da construtora no Omie.'); return; }
          f.querySelector('[data-ok]').disabled = true;
          let j;
          try { const r = await fetch('/capa-financeira/acao.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': csrf}, body: JSON.stringify({csrf, capa_id: ctx.capa_id, acao: 'quem_paga', modo, sinal_ids: [...sinal], cad})}); j = await r.json(); }
          catch (e) { j = {ok: false, erro: 'sem conexão com o portal'}; }
          if (!j.ok) { f.querySelector('[data-ok]').disabled = false; alert(j.erro || 'erro'); return; }
          fim(j);
        };
      }
      async function auto() { if (cad || !ctx.construtora) return; const r = await CFOmie.identificar({empresa: ctx.empresa, termo: ctx.construtora, csrf}); if (r.certo && !cad && document.body.contains(f)) { cad = r.certo; cadAuto = true; desenha(); } }
      desenha(); document.body.appendChild(f); if (modo === 'construtora') auto();
    });
  }
  window.CFQuemPaga = {abrir};
})();
