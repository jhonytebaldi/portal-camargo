/* =====================================================================
   capa-financeira/cf-modal.js — modal de avisos e de progresso do módulo.
     CFModal.aviso({tipo:'ok'|'erro'|'parcial'|'info', titulo, html|texto, detalhe}) → Promise (fecha no OK / Esc)
     CFModal.pergunta({tipo:'info'|'parcial'|'erro', titulo, html|texto, ok:'Sim', cancelar:'Cancelar'}) → Promise<bool> (Enter = sim, Esc = não)
     const p = CFModal.progresso({titulo, etapas:[...], atraso:ms})
       p.faixa(de, ate, texto)   anda sozinho de "de" até perto de "ate" (requisição em andamento)
       p.pct(n, texto)           vai até n%
       await p.fim()             100% e fecha · await p.resultado({...aviso})  100% e vira o aviso
       p.fechar()                fecha na hora
   Elementos com data-cf-modal="ok|erro|parcial|info" na página viram modal ao carregar.
   ===================================================================== */
(() => {
  if (window.CFModal) return;
  const css = `
.cfm-fundo{position:fixed;inset:0;background:rgba(20,30,25,.45);display:flex;align-items:center;justify-content:center;z-index:1000;opacity:0;transition:opacity .18s}
.cfm-fundo.on{opacity:1}
.cfm-caixa{background:#fff;border-radius:14px;width:min(560px,92vw);max-height:88vh;overflow:auto;box-shadow:0 18px 60px rgba(0,0,0,.3);padding:24px 26px 20px;transform:translateY(10px) scale(.98);transition:transform .2s}
.cfm-fundo.on .cfm-caixa{transform:none}
.cfm-ico{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;margin:0 auto 10px;color:#fff}
.cfm-ok .cfm-ico{background:#2f7a4b}.cfm-erro .cfm-ico{background:#b4512f}.cfm-parcial .cfm-ico{background:#c9a227}.cfm-info .cfm-ico{background:#4a6a8a}
.cfm-tit{text-align:center;font-size:19px;font-weight:700;margin:0 0 8px;color:#1f2a24}
.cfm-txt{font-size:14.5px;line-height:1.45;color:#333}.cfm-txt ul{margin:6px 0 0 18px;padding:0}.cfm-txt li{margin:3px 0}
.cfm-txt .cv-msg,.cfm-txt .cf-sucesso{background:none!important;padding:0!important;margin:0!important;border:0!important}
.cfm-det{margin-top:10px;font-size:13px}.cfm-det pre{white-space:pre-wrap;background:#f6f6f2;border-radius:6px;padding:8px;max-height:260px;overflow:auto;margin:6px 0 0}
.cfm-bt{display:flex;justify-content:center;margin-top:16px}.cfm-bt button{font:inherit;font-size:15px;font-weight:600;padding:10px 34px;border-radius:8px;border:0;background:#2f6b4f;color:#fff;cursor:pointer}
.cfm-erro .cfm-bt button{background:#b4512f}
.cfm-bt.cfm-2{gap:10px}.cfm-bt .cfm-nao{background:#eef0ea;color:#333}
.cfm-txt pre.cfm-pre{white-space:pre-wrap;background:#f6f6f2;border-radius:6px;padding:8px 10px;margin:8px 0 0;font:inherit;font-size:13.5px}
.cfm-janela{width:min(780px,94vw)}
.cfm-prog .cfm-tit{margin-bottom:14px}
.cfm-pct{text-align:center;font-size:40px;font-weight:800;color:#2f6b4f;font-variant-numeric:tabular-nums;line-height:1}
.cfm-barra{height:14px;border-radius:8px;background:#e8ebe6;overflow:hidden;margin:14px 0 10px;position:relative}
.cfm-barra i{position:absolute;inset:0 auto 0 0;width:0;border-radius:8px;overflow:hidden;background:linear-gradient(90deg,#3f8a5f,#6fbf8a)}
.cfm-barra i::after{content:"";position:absolute;inset:0;background:repeating-linear-gradient(45deg,rgba(255,255,255,.22) 0 10px,transparent 10px 20px);animation:cfm-listra 1s linear infinite}
@keyframes cfm-listra{from{background-position:0 0}to{background-position:28px 0}}
.cfm-etapa{text-align:center;font-size:14px;color:#566;min-height:20px}
.cfm-passos{list-style:none;padding:0;margin:12px 0 0;font-size:13px;color:#889}.cfm-passos li{margin:3px 0}.cfm-passos li.feito{color:#2f7a4b}.cfm-passos li.agora{color:#1f2a24;font-weight:600}
.cfm-passos li::before{content:"○ ";}.cfm-passos li.feito::before{content:"✔ ";}.cfm-passos li.agora::before{content:"● ";}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const ICO = {ok: '✔', erro: '✖', parcial: '!', info: 'i'};
  const TIT = {ok: 'Pronto!', erro: 'Não foi possível concluir', parcial: 'Atenção', info: 'Aviso'};
  let fundo = null, prog = null;

  function abrir(cls, html) {
    if (!fundo) {
      fundo = document.createElement('div'); fundo.className = 'cfm-fundo';
      document.body.appendChild(fundo);
      requestAnimationFrame(() => fundo && fundo.classList.add('on'));
    }
    fundo.innerHTML = '<div class="cfm-caixa ' + cls + '" role="dialog" aria-modal="true">' + html + '</div>';
    return fundo.firstChild;
  }
  function fechar() {
    if (prog) { prog.parar(); prog = null; }
    const f = fundo; fundo = null;
    if (f) { f.classList.remove('on'); setTimeout(() => f.remove(), 180); }
  }

  function aviso(o) {
    o = o || {};
    if (prog) { prog.parar(); prog = null; }
    const tipo = ICO[o.tipo] ? o.tipo : 'info';
    const corpo = o.html !== undefined ? o.html : esc(o.texto || '');
    const cx = abrir('cfm-' + tipo, '<div class="cfm-ico">' + ICO[tipo] + '</div><h2 class="cfm-tit">' + esc(o.titulo || TIT[tipo]) + '</h2>'
      + (corpo ? '<div class="cfm-txt">' + corpo + '</div>' : '')
      + (o.detalhe ? '<details class="cfm-det"><summary>detalhes</summary><pre>' + esc(o.detalhe) + '</pre></details>' : '')
      + '<div class="cfm-bt"><button type="button">' + esc(o.botao || 'OK') + '</button></div>');
    return new Promise(res => {
      const b = cx.querySelector('.cfm-bt button');
      const sai = () => { document.removeEventListener('keydown', tecla); fechar(); res(); };
      const tecla = e => { if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); sai(); } };
      b.onclick = sai; document.addEventListener('keydown', tecla);
      fundo.onclick = e => { if (e.target === fundo) sai(); };
      setTimeout(() => b.focus(), 50);
    });
  }

  /** pergunta sim/não com o mesmo visual do aviso; resolve true (ok) ou false (cancelar/Esc/fundo) */
  function pergunta(o) {
    o = o || {};
    if (prog) { prog.parar(); prog = null; }
    const tipo = ICO[o.tipo] ? o.tipo : 'info';
    const corpo = o.html !== undefined ? o.html : esc(o.texto || '');
    const cx = abrir('cfm-' + tipo, '<div class="cfm-ico">' + (o.icone || '?') + '</div><h2 class="cfm-tit">' + esc(o.titulo || 'Confirma?') + '</h2>'
      + (corpo ? '<div class="cfm-txt">' + corpo + '</div>' : '')
      + '<div class="cfm-bt cfm-2"><button type="button" class="cfm-nao">' + esc(o.cancelar || 'Cancelar') + '</button><button type="button" class="cfm-sim">' + esc(o.ok || 'Sim') + '</button></div>');
    return new Promise(res => {
      const sim = cx.querySelector('.cfm-sim'), nao = cx.querySelector('.cfm-nao');
      const sai = v => { document.removeEventListener('keydown', tecla); fechar(); res(v); };
      const tecla = e => { if (e.key === 'Escape') { e.preventDefault(); sai(false); } else if (e.key === 'Enter') { e.preventDefault(); sai(true); } };
      sim.onclick = () => sai(true); nao.onclick = () => sai(false); document.addEventListener('keydown', tecla);
      fundo.onclick = e => { if (e.target === fundo) sai(false); };
      setTimeout(() => sim.focus(), 50);
    });
  }

  function progresso(o) {
    o = o || {};
    if (prog) prog.parar();
    const etapas = o.etapas || [];
    let texto = '', atual = 0, alvo = 0, de = 0, ate = 90, t0 = performance.now(), raf = null, vivo = true, montado = false, cx = null;
    const montar = () => {
      if (montado || !vivo) return; montado = true;
      cx = abrir('cfm-prog', '<h2 class="cfm-tit">' + esc(o.titulo || 'Processando…') + '</h2><div class="cfm-pct">0%</div>'
        + '<div class="cfm-barra"><i></i></div><div class="cfm-etapa"></div>'
        + (etapas.length ? '<ul class="cfm-passos">' + etapas.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul>' : ''));
      tick();
    };
    const atraso = o.atraso ?? 0;
    const tm = atraso ? setTimeout(montar, atraso) : null;
    if (!atraso) montar();
    function tick() {
      if (!vivo || !cx) return;
      // faixa automática: aproxima de "ate" sem nunca chegar (o servidor ainda está trabalhando)
      const el = (performance.now() - t0) / 1000;
      const auto = de + (ate - de) * (1 - Math.exp(-el / (o.tau || 3.5)));
      const meta = Math.max(alvo, auto);
      atual += (meta - atual) * 0.12;
      if (meta - atual < 0.05) atual = meta;
      const p = Math.min(100, atual);
      cx.querySelector('.cfm-barra i').style.width = p + '%';
      cx.querySelector('.cfm-pct').textContent = Math.floor(p) + '%';
      if (etapas.length) {
        const i = Math.min(etapas.length - 1, Math.floor(p / (100 / etapas.length)));
        cx.querySelectorAll('.cfm-passos li').forEach((li, k) => li.className = p >= 100 || k < i ? 'feito' : (k === i ? 'agora' : ''));
        cx.querySelector('.cfm-etapa').textContent = texto || (p >= 100 ? 'Concluído' : etapas[i] + '…');
      } else cx.querySelector('.cfm-etapa').textContent = texto;
      raf = requestAnimationFrame(tick);
    }
    const api = {
      faixa(a, b, t) { de = Math.max(a, atual); ate = b; t0 = performance.now(); if (t !== undefined) texto = t; return api; },
      pct(n, t) { alvo = n; if (t !== undefined) texto = t; return api; },
      texto(t) { texto = t; return api; },
      parar() { vivo = false; clearTimeout(tm); if (raf) cancelAnimationFrame(raf); },
      async ate100() {
        if (!montado) { api.parar(); return false; }
        alvo = 100; de = 100; ate = 100;
        await new Promise(r => { const ver = () => (atual >= 99.5 || !vivo) ? r() : setTimeout(ver, 40); ver(); });
        await new Promise(r => setTimeout(r, 250));
        return true;
      },
      async fim() { const m = await api.ate100(); api.parar(); if (prog === api) { prog = null; if (m) fechar(); } },
      async resultado(av) { await api.ate100(); api.parar(); if (prog === api) prog = null; return aviso(av); },
      fechar() { api.parar(); if (prog === api) { prog = null; if (montado) fechar(); } },
    };
    prog = api;
    return api;
  }

  window.CFModal = {aviso, pergunta, progresso, fechar, esc};
  const auto = () => {
    const el = document.querySelector('[data-cf-modal]');
    if (el) aviso({tipo: el.dataset.cfModal, titulo: el.dataset.cfTitulo || undefined, html: el.innerHTML.replace(/^\s*[✔✖⚠]\s*/, '')});
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else setTimeout(auto, 0);
})();
