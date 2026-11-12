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
      bn.addEventListener('click', () => salvarNota(det, bn, d.id));
      const bd = det.querySelector('[data-despar]');
      if (bd) bd.addEventListener('click', () => post('desparear', {id: d.id}).then(() => location.reload()));
    }).catch(() => { det.firstChild.textContent = 'Não foi possível carregar os detalhes. Tente de novo.'; });
  }
  // anotação: se a busca aberta tem outros lançamentos com exatamente o mesmo nome, pergunta se repete neles
  const filtrosDaTela = () => { const q = new URLSearchParams(location.search); q.delete('p'); return q.toString(); };
  function mostrarNota(ids, nota) {
    ids.forEach(i => document.querySelectorAll('.bb-lin[data-id="' + i + '"]').forEach(tr => {
      const td = tr.children[2]; let s = td.querySelector('.bb-nota-lin');
      if (!nota) { if (s) { const br = s.previousElementSibling; if (br && br.tagName === 'BR') br.remove(); s.remove(); } return; }
      if (!s) { td.appendChild(document.createElement('br')); s = document.createElement('span'); s.className = 'bb-nota-lin'; td.appendChild(s); }
      s.textContent = '✎ ' + nota;
    }));
  }
  function salvarNota(det, bn, id) {
    const nota = det.querySelector('textarea').value, acoes = bn.parentNode, filtros = filtrosDaTela();
    const gravar = todos => post('nota', {id: id, nota: nota, filtros: filtros, todos: todos ? 1 : ''}).then(r => {
      if (!r || !r.ok) throw new Error();
      mostrarNota(r.ids, r.nota);
      const p = acoes.querySelector('.bb-nota-pergunta'); if (p) p.remove();
      bn.style.display = ''; bn.textContent = r.ids.length > 1 ? 'Anotação salva em ' + r.ids.length + ' lançamentos' : 'Anotação salva';
      setTimeout(() => bn.textContent = 'Salvar anotação', 2500);
    }).catch(() => { bn.style.display = ''; alert('Não consegui salvar. Recarregue a página e tente de novo.'); });
    bn.disabled = true;
    post('nota_iguais', {id: id, filtros: filtros}).then(r => {
      bn.disabled = false;
      if (!r || !r.ok || !r.n) return gravar(false);
      bn.style.display = 'none';
      const p = document.createElement('div'); p.className = 'bb-nota-pergunta';
      p.innerHTML = '<p>Há mais <b>' + r.n + '</b> lançamento' + (r.n > 1 ? 's' : '') + ' nesta busca com o nome exato <b>' + esc(r.nome) + '</b>. Repetir a mesma anotação ' + (r.n > 1 ? 'neles' : 'nele') + '?' +
        (r.com_nota ? ' <small>(' + r.com_nota + (r.com_nota > 1 ? ' deles já têm outra anotação, que será substituída' : ' deles já tem outra anotação, que será substituída') + ')</small>' : '') + '</p>' +
        '<button type="button" class="bb-btn" data-todos>Sim, em todos os ' + (r.n + 1) + '</button> <button type="button" class="bb-btn sec" data-so>Só neste</button>';
      acoes.prepend(p);
      p.querySelector('[data-todos]').addEventListener('click', () => gravar(true));
      p.querySelector('[data-so]').addEventListener('click', () => gravar(false));
    }).catch(() => { bn.disabled = false; gravar(false); });
  }

  document.querySelectorAll('.bb-lin').forEach(tr => {
    tr.addEventListener('click', e => { if (e.target.closest('input,a,button,textarea')) return; detalhe(tr); });
    tr.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === tr) { e.preventDefault(); detalhe(tr); } });
    const c = tr.querySelector('.bb-confpill');
    if (c) c.addEventListener('click', () => {
      const on = !c.classList.contains('on'); c.disabled = true;
      post('conferir', {id: tr.dataset.id, v: on ? 1 : ''}).then(d => {
        if (!d || !d.ok) throw new Error();
        c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); c.textContent = on ? '✓ Conferido' : 'Conferir';
        c.title = on ? 'Conferido por ' + d.por + ' em ' + d.em + '. Clique para desmarcar.' : 'Clique quando tiver checado este lançamento (bateu com a planilha, o Omie ou o comprovante).';
      }).catch(() => alert('Não consegui salvar. Recarregue a página e tente de novo.')).finally(() => { c.disabled = false; });
    });
  });

  // marcar/desmarcar TODOS os resultados da busca
  document.querySelectorAll('[data-conf-todos]').forEach(b => b.addEventListener('click', () => {
    const box = b.closest('.bb-conf-acoes'), on = b.dataset.confTodos === '1', total = +box.dataset.total;
    const msg = on ? 'Marcar como CONFERIDOS todos os ' + total.toLocaleString('pt-BR') + ' lançamentos desta busca (não só os da página)?'
                   : 'DESMARCAR a conferência de todos os lançamentos desta busca que estão marcados?';
    if (!confirm(msg + (total > 300 ? '\n\nAtenção: são muitos lançamentos. Confira os filtros antes.' : ''))) return;
    b.disabled = true;
    post('conferir_todos', {filtros: box.dataset.filtros, esperado: total, v: on ? 1 : ''}).then(d => {
      if (d.erro) { alert(d.erro); b.disabled = false; return; }
      location.reload();
    }).catch(() => { alert('Não consegui salvar. Recarregue a página e tente de novo.'); b.disabled = false; });
  }));

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
