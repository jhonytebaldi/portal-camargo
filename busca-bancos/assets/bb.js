/* Busca Bancos — interações da tela (sem dependências). */
(function () {
  const csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  const post = (a, dados) => fetch('/busca-bancos/acao.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(Object.assign({a: a, csrf: csrf}, dados))}).then(r => r.json());

  // filtros que aplicam na hora
  const form = document.getElementById('bb-form');
  if (form) {
    form.querySelectorAll('[data-auto]').forEach(el => el.addEventListener('change', () => {
      if (el.name === 'per' && el.value === 'custom') { form.querySelector('.bb-datas').hidden = false; form.querySelector('[name=de]').focus(); return; }
      form.submit();
    }));
    form.addEventListener('submit', () => {
      const per = form.querySelector('[name=per]');
      if (per && per.value !== 'custom') form.querySelectorAll('[name=de],[name=ate]').forEach(i => i.disabled = true);
      form.querySelectorAll('input[type=text],input[type=number],input[type=date],input[type=search]').forEach(i => { if (!i.value) i.disabled = true; });
    });
  }

  // expandir a linha com todos os detalhes
  const lista = (titulo, itens) => !itens.length ? '' : '<h3>' + titulo + '</h3><ul>' + itens.map(v =>
    '<li><span>' + esc(v.data) + ' · ' + esc(v.texto || '(sem descrição)') + ' <small>(' + esc(v.conta) + ')</small></span><b class="' + (v.valor >= 0 ? 'ent' : 'sai') + '">' + esc(v.valor_fmt) + '</b></li>').join('') + '</ul>';
  function detalhe(tr) {
    const aberto = tr.getAttribute('aria-expanded') === 'true';
    document.querySelectorAll('.bb-det').forEach(d => d.remove());
    document.querySelectorAll('.bb-lin[aria-expanded=true]').forEach(l => l.setAttribute('aria-expanded', 'false'));
    if (aberto) return;
    tr.setAttribute('aria-expanded', 'true');
    const det = document.createElement('tr'); det.className = 'bb-det';
    det.innerHTML = '<td colspan="' + tr.children.length + '">Carregando…</td>';
    tr.after(det);
    fetch('/busca-bancos/acao.php?a=detalhe&id=' + tr.dataset.id).then(r => r.json()).then(d => {
      if (d.erro) { det.firstChild.textContent = d.erro; return; }
      const o = d.origem;
      const par = d.par ? '<h3>' + (d.par.tipo === 'interna' ? 'Outro lado da transferência interna' : 'Lançamento que anula este') + '</h3><ul><li><span>' + esc(d.par.data) + ' · ' + esc(d.par.texto) +
        ' <small>(' + esc(d.par.conta) + ')</small></span><b class="' + (d.par.valor >= 0 ? 'ent' : 'sai') + '">' + esc(d.par.valor_fmt) + '</b></li></ul>' +
        '<button type="button" class="bb-btn sec" data-despar>' + (d.par.tipo === 'interna' ? 'Não é transferência interna' : 'Não se anulam') + '</button>' : '';
      det.firstChild.innerHTML = '<div class="bb-det-grade"><div><h3>Lançamento</h3><dl>' + d.campos.map(c => '<dt>' + esc(c[0]) + '</dt><dd>' + esc(c[1]) + '</dd>').join('') + '</dl>' +
        '<h3>Origem da informação</h3><dl><dt>Como entrou</dt><dd>' + esc(o.tipo) + '</dd><dt>Arquivo</dt><dd>' + esc(o.arquivo) + (o.link ? ' · <a href="' + esc(o.link) + '" target="_blank" rel="noopener">abrir no Drive</a>' : '') + '</dd>' +
        (o.caminho ? '<dt>Pasta</dt><dd>' + esc(o.caminho) + '</dd>' : '') + '<dt>Período do arquivo</dt><dd>' + esc(o.periodo) + '</dd><dt>Enviado</dt><dd>' + esc(o.em) + (o.por ? ' por ' + esc(o.por) : '') + '</dd></dl></div>' +
        '<div>' + par + lista('Outros lançamentos desta conta no mesmo dia', d.vizinhos) +
        '<h3>Anotação</h3><textarea placeholder="Ex.: comissão da venda do apto 302, conferido com a planilha">' + esc(d.nota) + '</textarea>' +
        '<div class="bb-det-acoes"><button type="button" class="bb-btn sec" data-nota>Salvar anotação</button>' +
        (d.pessoa.n > 1 ? '<a class="bb-btn sec" href="/busca-bancos/?q=' + encodeURIComponent(d.pessoa.busca) + '">Ver os ' + d.pessoa.n + ' lançamentos desta pessoa</a>' : '') +
        (d.conferido ? '<small>Conferido' + (d.conferido_por ? ' por ' + esc(d.conferido_por) : '') + (d.conferido_em ? ' em ' + esc(d.conferido_em) : '') + '</small>' : '') + '</div></div></div>';
      const bn = det.querySelector('[data-nota]');
      bn.addEventListener('click', () => post('nota', {id: d.id, nota: det.querySelector('textarea').value}).then(() => { bn.textContent = 'Anotação salva'; setTimeout(() => bn.textContent = 'Salvar anotação', 1800); }));
      const bd = det.querySelector('[data-despar]');
      if (bd) bd.addEventListener('click', () => post('desparear', {id: d.id}).then(() => location.reload()));
    }).catch(() => { det.firstChild.textContent = 'Não foi possível carregar os detalhes. Tente de novo.'; });
  }
  document.querySelectorAll('.bb-lin').forEach(tr => {
    tr.addEventListener('click', e => { if (e.target.closest('input,a,button,textarea')) return; detalhe(tr); });
    tr.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === tr) { e.preventDefault(); detalhe(tr); } });
    const c = tr.querySelector('.bb-conf');
    if (c) c.addEventListener('change', () => post('conferir', {id: tr.dataset.id, v: c.checked ? 1 : ''}).catch(() => { c.checked = !c.checked; }));
  });

  // área de soltar arquivos
  document.querySelectorAll('.bb-solta').forEach(z => {
    const inp = z.querySelector('input[type=file]');
    ['dragenter', 'dragover'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add('sobre'); }));
    ['dragleave', 'drop'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove('sobre'); }));
    z.addEventListener('drop', e => { if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; inp.dispatchEvent(new Event('change')); } });
    inp.addEventListener('change', () => { const s = z.querySelector('[data-nomes]'); if (s) s.textContent = inp.files.length + ' arquivo(s) selecionado(s)'; });
  });
  document.querySelectorAll('[data-copiar]').forEach(b => b.addEventListener('click', () => {
    navigator.clipboard.writeText(document.querySelector(b.dataset.copiar).textContent).then(() => { const t = b.textContent; b.textContent = 'Copiado'; setTimeout(() => b.textContent = t, 1800); });
  }));
})();
