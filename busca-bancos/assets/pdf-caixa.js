/* Busca Bancos — lê o "Extrato por período" da Caixa quando só existe em PDF.
   O PDF da Caixa é uma FOTO da tela (não tem texto), então o navegador lê a imagem (OCR, local: nada sai do computador)
   e só deixa importar se a conta fechar: saldo anterior ± cada lançamento = saldo impresso em cada linha. */
(function () {
  const sec = document.getElementById('bb-pdf'); if (!sec) return;
  const inp = sec.querySelector('input[type=file]'), saida = sec.querySelector('[data-saida]'), base = '/busca-bancos/assets/ocr/';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  const num = s => { const m = String(s || '').match(/(\d{1,3}(?:\.\d{3})*|\d+),(\d{2})/); return m ? parseFloat(m[1].replace(/\./g, '') + '.' + m[2]) : null; };
  const brl = v => (v < 0 ? '−' : '') + 'R$ ' + Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const iso = d => d.slice(6, 10) + '-' + d.slice(3, 5) + '-' + d.slice(0, 2);
  let worker = null;

  // as imagens JPEG embutidas no PDF (uma por página)
  function imagens(bytes) {
    const out = [], acha = (seq, de) => { outer: for (let i = de; i <= bytes.length - seq.length; i++) { for (let j = 0; j < seq.length; j++) if (bytes[i + j] !== seq[j]) continue outer; return i; } return -1; };
    const fim = [101, 110, 100, 115, 116, 114, 101, 97, 109];   // "endstream"
    let p = 0;
    while ((p = acha([0xFF, 0xD8, 0xFF], p)) !== -1) {
      const e = acha(fim, p); if (e === -1) break;
      let q = e; while (q > p && !(bytes[q - 2] === 0xFF && bytes[q - 1] === 0xD9)) q--;
      if (q > p + 1000) out.push(new Blob([bytes.slice(p, q)], {type: 'image/jpeg'}));
      p = e;
    }
    return out;
  }

  // uma página lida → cabeçalho + linhas, separando as colunas pela posição dos títulos
  function interpretar(linhas, ext) {
    let col = null, grupo = null;
    for (const ln of linhas) {
      const t = ln.text.replace(/\s+/g, ' ').trim(); let m;
      if (!ext.conta && (m = t.match(/Conta\s+(\d{3,5})\s*\/\s*([\d.\-]{6,})/i))) { ext.agencia = m[1]; ext.conta = m[2]; }
      if (!ext.de && (m = t.match(/(\d{2}\/\d{2}\/\d{4})\s+at[ée]\s+(\d{2}\/\d{2}\/\d{4})/i))) { ext.de = iso(m[1]); ext.ate = iso(m[2]); }
      if (ext.saldo_anterior == null && /SALDO ANTERIOR/i.test(t)) { ext.saldo_anterior = num(t); if (/\bD\s*$/.test(t)) ext.saldo_anterior *= -1; }
      if (!ext.cliente && (m = t.match(/^Cliente\s+(.+)$/i))) ext.cliente = m[1];
      if (/Hist[óo]rico/i.test(t) && /Valor/i.test(t) && /Saldo/i.test(t)) {
        const x = re => { const w = ln.words.find(w => re.test(w.text)); return w ? w.bbox.x0 : null; };
        col = {hist: x(/^Hist/i), fav: x(/^Favorec/i), doc: x(/^CPF/i), valor: x(/^Valor/i), saldo: x(/^Saldo/i)};
        continue;
      }
      if (/CAIXA\s*:|Ouvidoria\s*:|defici.ncia\s*:/i.test(t)) { col = null; continue; }   // rodapé: acabou a tabela desta página
      if (!col || col.valor == null || col.saldo == null) continue;
      const cel = {ini: [], hist: [], fav: [], doc: [], valor: [], saldo: []};
      for (const w of ln.words) { const c = (w.bbox.x0 + w.bbox.x1) / 2, x0 = w.bbox.x0 + 6;
        (x0 >= col.saldo ? cel.saldo : x0 >= col.valor ? cel.valor : col.doc != null && x0 >= col.doc ? cel.doc : col.fav != null && x0 >= col.fav ? cel.fav : col.hist != null && x0 >= col.hist ? cel.hist : cel.ini).push(w.text); }
      const ini = cel.ini.join(' '), dm = ini.match(/(\d{2}\/\d{2}\/\d{4})\s*-?\s*(\d{2}:\d{2}:\d{2})?/);
      if (!dm) {   // continuação de uma célula que quebrou de linha
        const ult = ext.lanc[ext.lanc.length - 1];
        if (ult && !cel.valor.length && !cel.saldo.length && (cel.hist.length || cel.fav.length) ) { if (cel.hist.length) ult.memo += ' ' + cel.hist.join(' '); if (cel.fav.length) ult.nome += ' ' + cel.fav.join(' '); }
        continue;
      }
      const v = num(cel.valor.join(' ')), s = num(cel.saldo.join(' ')), hist = cel.hist.join(' ').trim();
      if (v == null || s == null) { ext.problemas.push('Não consegui ler valor/saldo na linha: "' + t + '"'); continue; }
      const sNeg = /D\s*$/.test(cel.saldo.join(' ').trim());
      if (/^SALDO DIA$/i.test(hist)) { grupo = iso(dm[1]); continue; }
      const dataReal = iso(dm[1]), nrdoc = (ini.replace(dm[0], '').match(/\d{3,}/) || [''])[0];
      // a Caixa mostra a hora do processamento (madrugada seguinte); a data do movimento é a do "SALDO DIA" do grupo
      const data = grupo && (new Date(dataReal) - new Date(grupo)) / 864e5 >= 0 && (new Date(dataReal) - new Date(grupo)) / 864e5 <= 1 ? grupo : dataReal;
      ext.lanc.push({data: data, quando: dataReal + ' ' + (dm[2] || '00:00:00'), abs: v, saldo: sNeg ? -s : s, dc: (cel.valor.join(' ').match(/([A-Z€])\s*$/) || ['', ''])[1],
        memo: hist, nome: cel.fav.join(' ').trim(), doc: cel.doc.join(' ').replace(/[^\d.\/\-]/g, ''), checknum: /^0*$/.test(nrdoc) ? '' : nrdoc});
    }
  }

  // confere a conta e define o sinal de cada lançamento pelo saldo impresso
  function fechar(ext) {
    ext.lanc.sort((a, b) => a.quando < b.quando ? -1 : a.quando > b.quando ? 1 : 0);
    let s = ext.saldo_anterior;
    for (const l of ext.lanc) {
      if (Math.abs(s + l.abs - l.saldo) < 0.005) l.valor = l.abs;
      else if (Math.abs(s - l.abs - l.saldo) < 0.005) l.valor = -l.abs;
      else { l.erro = 'não fecha: saldo antes ' + brl(s) + ', valor ' + brl(l.abs) + ', saldo impresso ' + brl(l.saldo); ext.problemas.push(l.quando.slice(0, 10).split('-').reverse().join('/') + ' ' + l.memo + ': ' + l.erro); l.valor = l.dc === 'D' ? -l.abs : l.abs; }
      s = l.saldo;
    }
    ext.saldo = s;
  }

  async function ler(arq) {
    const bytes = new Uint8Array(await arq.arrayBuffer()), imgs = imagens(bytes);
    const ext = {arquivo: arq.name, agencia: '', conta: '', de: '', ate: '', saldo_anterior: null, cliente: '', lanc: [], problemas: []};
    if (!imgs.length) { ext.problemas.push('Este PDF não tem a imagem do extrato (pode ser outro tipo de PDF). Me envie o arquivo para converter.'); return ext; }
    if (!worker) worker = await Tesseract.createWorker('por', 1, {workerPath: base + 'worker.min.js', corePath: base, langPath: base, gzip: true});
    for (const img of imgs) { const r = await worker.recognize(img); interpretar(r.data.lines, ext); }
    if (!ext.conta) ext.problemas.push('Não achei o número da conta no cabeçalho.');
    if (!ext.de) ext.problemas.push('Não achei o período dos lançamentos no cabeçalho.');
    if (ext.saldo_anterior == null) ext.problemas.push('Não achei o saldo anterior: sem ele não dá para conferir a conta.');
    else fechar(ext);
    return ext;
  }

  function mostrar(exts) {
    const bons = exts.filter(e => !e.problemas.length);
    saida.innerHTML = exts.map(e => '<div class="bb-alerta ' + (e.problemas.length ? 'erro' : 'ok') + '"><b>' + esc(e.arquivo) + '</b>: ' +
      (e.conta ? 'Caixa ag. ' + esc(e.agencia) + ' conta ' + esc(e.conta) + (e.cliente ? ' (' + esc(e.cliente) + ')' : '') + ' · ' : '') + (e.de ? esc(e.de.split('-').reverse().join('/')) + ' a ' + esc(e.ate.split('-').reverse().join('/')) + ' · ' : '') +
      e.lanc.length + ' lançamento(s)' + (e.problemas.length ? '. <b>Não vou importar este arquivo:</b><ul>' + e.problemas.map(p => '<li>' + esc(p) + '</li>').join('') + '</ul>'
        : '. A conta fecha: saldo anterior ' + brl(e.saldo_anterior) + ' → saldo final ' + brl(e.saldo) + '.') + '</div>' +
      (e.lanc.length ? '<div class="bb-tab-wrap" style="margin-bottom:10px"><table class="bb-tab"><thead><tr><th>Data</th><th>Histórico</th><th>Favorecido</th><th>CPF/CNPJ</th><th>Nº doc</th><th class="num">Valor</th><th class="num">Saldo</th></tr></thead><tbody>' +
        e.lanc.map(l => '<tr' + (l.erro ? ' style="background:#fdecea"' : '') + '><td class="mono">' + esc(l.data.split('-').reverse().join('/')) + '</td><td>' + esc(l.memo) + '</td><td>' + esc(l.nome) + '</td><td class="mono">' + esc(l.doc) + '</td><td class="mono">' + esc(l.checknum) +
          '</td><td class="num ' + (l.valor >= 0 ? 'ent' : 'sai') + '">' + brl(l.valor) + '</td><td class="num">' + brl(l.saldo) + '</td></tr>').join('') + '</tbody></table></div>' : '')).join('') +
      (bons.length ? '<p>Confira os nomes acima (a leitura de imagem pode trocar uma letra; os valores estão garantidos pelo saldo).</p><button type="button" class="bb-btn" data-enviar>Importar ' + bons.length + ' extrato(s)</button>' : '');
    const b = saida.querySelector('[data-enviar]');
    if (b) b.addEventListener('click', () => { const f = sec.querySelector('form[data-envio]'); f.querySelector('[name=dados]').value = JSON.stringify(bons); f.submit(); });
  }

  inp.addEventListener('change', async () => {
    if (!inp.files.length) return;
    saida.innerHTML = '<div class="bb-nota">Lendo ' + inp.files.length + ' arquivo(s)… a primeira leitura demora alguns segundos (carrega o leitor de imagens).</div>';
    try { const exts = []; for (const f of inp.files) exts.push(await ler(f)); mostrar(exts); }
    catch (e) { saida.innerHTML = '<div class="bb-alerta erro">Não consegui ler o PDF neste navegador (' + esc(e && e.message || e) + '). Tente no Chrome atualizado ou me envie o arquivo para converter.</div>'; }
  });
})();
