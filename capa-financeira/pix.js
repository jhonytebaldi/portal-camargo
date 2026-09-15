/* capa-financeira/pix.js — campo de chave Pix com máscara dinâmica e validação.
   Uso: <input class="cf-pix"> — o script detecta o tipo enquanto digita
   (CPF, CNPJ, e-mail, telefone, chave aleatória), aplica os separadores e
   marca inválido. Mesmas regras de lib/Pix.php (que valida no servidor). */
(function () {
  function cpfValido(d) {
    if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
    for (let t = 9; t < 11; t++) { let s = 0; for (let i = 0; i < t; i++) s += +d[i] * ((t + 1) - i); if (+d[t] !== ((10 * s) % 11) % 10) return false; }
    return true;
  }
  function cnpjValido(d) {
    if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) return false;
    const calc = (b, p) => { let s = 0; p.forEach((w, i) => s += +b[i] * w); const r = s % 11; return r < 2 ? 0 : 11 - r; };
    return +d[12] === calc(d.slice(0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]) && +d[13] === calc(d.slice(0, 13), [6,5,4,3,2,9,8,7,6,5,4,3,2]);
  }
  const fmtCpf = d => d.replace(/(\d{3})(\d{0,3})(\d{0,3})(\d{0,2}).*/, (m, a, b, c, e) => a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '-' + e : ''));
  const fmtCnpj = d => d.replace(/(\d{2})(\d{0,3})(\d{0,3})(\d{0,4})(\d{0,2}).*/, (m, a, b, c, e, f) => a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '/' + e : '') + (f ? '-' + f : ''));
  const fmtTel = d => { // 55 DD 9XXXX XXXX
    let n = d.startsWith('55') ? d : '55' + d; n = n.slice(0, 13);
    const dd = n.slice(2, 4), r = n.slice(4);
    return '+' + n.slice(0, 2) + (dd ? ' ' + dd : '') + (r ? ' ' + (r.length > 5 ? r.slice(0, r.length - 4) + '-' + r.slice(-4) : r) : '');
  };

  /** Analisa o texto: {tipo, texto (formatado p/ exibir), valor (normalizado), valido, erro, completo} */
  function analisar(raw) {
    const s = (raw || '').trim();
    if (!s) return { tipo: null, texto: '', valor: '', valido: true, completo: true, erro: '' };
    if (s.includes('@')) {
      const e = s.toLowerCase(); const ok = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(e);
      return { tipo: 'email', texto: e, valor: e, valido: ok, completo: ok, erro: ok ? '' : 'e-mail incompleto' };
    }
    const hex = s.replace(/-/g, '');
    if (/^[0-9a-f]{8,32}$/i.test(hex) && /[a-f]/i.test(hex)) { // tem letra → chave aleatória em digitação
      const h = hex.toLowerCase(); const parts = [h.slice(0, 8), h.slice(8, 12), h.slice(12, 16), h.slice(16, 20), h.slice(20, 32)].filter(Boolean);
      const ok = h.length === 32;
      return { tipo: 'aleatoria', texto: parts.join('-'), valor: parts.join('-'), valido: ok, completo: ok, erro: ok ? '' : 'chave aleatória incompleta (32 caracteres)' };
    }
    const mais = s.startsWith('+');
    const d = s.replace(/\D+/g, '');
    if (mais || (d.length >= 12 && d.startsWith('55'))) {
      const n = d.startsWith('55') ? d : '55' + d; const ok = n.length === 12 || n.length === 13;
      return { tipo: 'telefone', texto: fmtTel(d), valor: '+' + n.slice(0, 13), valido: ok, completo: ok, erro: ok ? '' : 'telefone incompleto (+55 DDD número)' };
    }
    if (d.length <= 11 && !/[a-z]/i.test(s)) {
      if (d.length === 11) {
        if (cpfValido(d)) return { tipo: 'cpf', texto: fmtCpf(d), valor: fmtCpf(d), valido: true, completo: true, erro: '' };
        if (/^[1-9][0-9]9[0-9]{8}$/.test(d)) return { tipo: 'telefone', texto: fmtTel(d), valor: '+55' + d, valido: true, completo: true, erro: '' };
        return { tipo: 'cpf', texto: fmtCpf(d), valor: fmtCpf(d), valido: false, completo: true, erro: 'CPF inválido (dígito verificador)' };
      }
      return { tipo: d.length > 0 ? 'cpf?' : null, texto: fmtCpf(d), valor: d, valido: true, completo: false, erro: '' };
    }
    if (d.length <= 14 && !/[a-z]/i.test(s)) {
      const ok = d.length === 14 && cnpjValido(d);
      return { tipo: 'cnpj', texto: fmtCnpj(d), valor: fmtCnpj(d), valido: d.length < 14 || ok, completo: d.length === 14, erro: d.length === 14 && !ok ? 'CNPJ inválido (dígito verificador)' : '' };
    }
    return { tipo: null, texto: s, valor: s, valido: false, completo: true, erro: 'não parece CPF, CNPJ, e-mail, telefone nem chave aleatória' };
  }

  const rotulo = { cpf: 'CPF', 'cpf?': '…', cnpj: 'CNPJ', email: 'e-mail', telefone: 'telefone', aleatoria: 'aleatória' };
  function liga(el) {
    if (el.dataset.pixOn) return; el.dataset.pixOn = '1';
    const tag = document.createElement('small'); tag.className = 'cf-pix-tipo'; el.insertAdjacentElement('afterend', tag);
    const render = (fmt) => {
      const a = analisar(el.value);
      if (fmt && a.texto !== el.value) el.value = a.texto;
      el.classList.toggle('cf-pix-erro', !a.valido);
      tag.textContent = a.tipo ? (rotulo[a.tipo] || a.tipo) + (a.erro ? ' — ' + a.erro : '') : (a.erro || '');
      tag.classList.toggle('cf-pix-erro', !a.valido);
      el.dataset.pixValor = a.valido && a.completo ? a.valor : '';
      el.dataset.pixValido = a.valido ? '1' : '0';
      return a;
    };
    el.addEventListener('input', () => render(true));
    el.addEventListener('blur', () => render(true));
    render(true);
  }
  window.cfPix = { analisar, liga, ligarTodos: () => document.querySelectorAll('input.cf-pix').forEach(liga) };
  document.addEventListener('DOMContentLoaded', window.cfPix.ligarTodos);
  if (document.readyState !== 'loading') window.cfPix.ligarTodos();
})();
