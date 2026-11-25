#!/usr/bin/env python3
"""Remonta os dados da página GBRAC (Monteluce Residence) da Área do Construtor.

Fontes (baixadas pela rotina antes de rodar este script):
  --planilha  FLUXO FINANCEIRO GBRAC exportada em .xlsx (uma aba por unidade)
  --tabela    Tabela de Vendas mais recente do Monteluce (.xlsx)
  --base      JSON atual da página (GET construtor/api-importar.php?p=gbrac)
  --cub       (opcional) JSON {"YYYY-MM": valor, ...} com meses novos do CUB SC
  --extras    (opcional) JSON com dados de contrato de vendas novas:
              {"<unidade>|<Primeiro nome>": {"preco":..,"entrada":..,"saldo":..,
               "contrato":"10 de novembro de 2026","cub_pct":50,"comissao":17640}}
Saída: --out novo JSON + relatório no stdout. Linhas "PENDENTE:" pedem ação.

Nenhum dado de cliente fica neste repositório: tudo entra e sai por arquivo.
"""
import argparse, datetime as dt, json, re, sys, unicodedata
import openpyxl

ENTREGA = '2028-12-01'
MESES = {'janeiro':1,'fevereiro':2,'março':3,'marco':3,'abril':4,'maio':5,'junho':6,'julho':7,'agosto':8,'setembro':9,'outubro':10,'novembro':11,'dezembro':12}

def norm(s):
    s = unicodedata.normalize('NFD', str(s or '')).encode('ascii', 'ignore').decode().upper()
    return re.sub(r'\s+', ' ', s).strip()

def addm(iso, k):
    y, m, d = map(int, iso.split('-')); m += k; y += (m - 1) // 12; m = (m - 1) % 12 + 1
    return f'{y:04d}-{m:02d}-{min(d, 28):02d}'

def isodate(v):
    if isinstance(v, (dt.datetime, dt.date)): return v.strftime('%Y-%m-%d')
    return None

def money(txt):
    return float(txt.replace('.', '').replace(',', '.'))

def bonus_de(txt):
    t = norm(txt)
    m = re.search(r'R?\$?\s*([\d\.]+(?:,\d+)?)\s*(?:MIL\s*)?DE\s*(?:BONUS|BONIFICACAO)', t)
    if not m: return 0.0
    v = m.group(1)
    if 'MIL DE' in t[m.start():m.end()]: return float(v) * 1000
    return money(v) if ',' in v or '.' in v else float(v)

def ler_planilha(path):
    wb = openpyxl.load_workbook(path, data_only=True)
    out = []
    for ws in wb.worksheets:
        m = re.match(r'\s*(\d{3})', ws.title)
        if not m: continue
        u = int(m.group(1)); rows = list(ws.iter_rows(values_only=True))
        meta, notes, hdr = {}, [], None
        for i, r in enumerate(rows):
            a = r[0] if r else None
            if isinstance(a, str) and a.strip().upper().startswith('DATA DA PARCELA'): hdr = i; break
            if isinstance(a, str):
                k = a.strip().rstrip(':').upper()
                if k in ('VENDEDOR', 'COMPRADOR', 'IMÓVEL', 'UNIDADE', 'DATA DA VENDA', 'CORRETOR', 'DATA DA ENTREGA'): meta[k] = r[1]
                elif a.strip(): notes.append(a.strip())
        if hdr is None: continue
        parc, sec = [], 'principal'
        for r in rows[hdr + 1:]:
            if len(r) > 2 and r[2] == 'TOTAL:': break
            a = r[0]
            if isinstance(a, str) and a.strip().upper() == 'ADITIVO': sec = 'aditivo'; continue
            if len(r) < 3 or not isinstance(r[2], (int, float)): continue
            extra = ' '.join(str(c) for c in r[8:] if c) if len(r) > 8 else ''
            obs = r[6] if len(r) > 6 else None
            obs = obs.strftime('%d/%m/%Y') if isinstance(obs, (dt.datetime, dt.date)) else (str(obs) if obs else '')
            parc.append(dict(raw=a, sec=sec, v=float(r[2]), ok=str(r[3] or '').strip().upper() if len(r) > 3 else '',
                             cm=r[4] if len(r) > 4 and isinstance(r[4], (int, float)) else None,
                             gb=r[5] if len(r) > 5 and isinstance(r[5], (int, float)) else None,
                             obs=(obs + (' ' + extra if extra else '')).strip()))
        if not parc: continue
        cli = re.sub(r'\s+', ' ', str(meta.get('COMPRADOR') or '').replace('\n', ' ')).strip().rstrip(',')
        venda = isodate(meta.get('DATA DA VENDA'))
        first = next((isodate(p['raw']) for p in parc if isodate(p['raw'])), None)
        sem_controle = not any(p['ok'] for p in parc) and not any(p['cm'] for p in parc)
        P = []
        for p in parc:
            d, tipo = isodate(p['raw']), ('aditivo' if p['sec'] == 'aditivo' else 'parcela')
            raw = norm(p['raw'])
            if not d:
                if 'ASSINATURA' in raw and 'FINANC' not in raw: d, tipo = venda or (addm(first, -1) if first else ENTREGA), 'sinal'
                else: d, tipo = ENTREGA, 'chaves'
            cm = float(p['cm'] or 0); gb = float(p['gb']) if p['gb'] is not None else p['v'] - cm
            pg = 'nd' if sem_controle else ('sim' if p['ok'] == 'OK' else ('parcial' if p['ok'] == 'PARCIAL' else 'nao'))
            P.append([d, round(p['v'], 2), round(cm, 2), round(gb, 2), pg, tipo, p['obs']])
        P.sort(key=lambda z: z[0])
        com = next((n for n in notes if norm(n).startswith('COMISS')), '')
        out.append(dict(u=u, cli=cli.title(), cor=str(meta.get('CORRETOR') or '').strip().title(), venda=venda,
                        st='distrato' if 'DISTRATO' in norm(ws.title) else 'vendida', com=com,
                        nota=' | '.join(n for n in notes if not norm(n).startswith('COMISS')), P=P, bonus=bonus_de(com)))
    return out

def ler_tabela(path):
    wb = openpyxl.load_workbook(path, data_only=True); ws = wb.active
    def num(x):
        if isinstance(x, (int, float)): return float(x)
        if isinstance(x, str) and re.search(r'\d', x): return money(re.sub(r'[^\d,\.]', '', x))
        return None
    tab = {}
    for r in ws.iter_rows(values_only=True):
        cells = list(r)
        for j, a in enumerate(cells):
            if isinstance(a, str) and re.match(r'\s*\d{3} -', a):
                u = a.strip()[:3]
                st = next((c for c in reversed(cells) if isinstance(c, str) and c.strip()), '')
                tab[u] = dict(antiga=num(cells[j + 3]) if j + 3 < len(cells) else None,
                              vigente=num(cells[j + 4]) if j + 4 < len(cells) else None, status=st.strip()[:60])
                break
    return tab

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--planilha', required=True); ap.add_argument('--tabela', required=True)
    ap.add_argument('--base', required=True); ap.add_argument('--cub'); ap.add_argument('--extras')
    ap.add_argument('--out', required=True); ap.add_argument('--hoje', default=dt.date.today().isoformat())
    a = ap.parse_args()
    base = json.load(open(a.base)); base = base.get('dados', base)
    extras = json.load(open(a.extras)) if a.extras else {}
    serie = dict(base.get('cubSerie') or {})
    if a.cub: serie.update(json.load(open(a.cub)))
    ci = dict(base.get('cubInfo') or {})
    if serie:
        ult = max(serie); ci.update(ult=ult, ultVal=serie[ult])
    rate = ci.get('rate', 0.0577)
    y, m = map(int, ci['ult'].split('-')); meses_proj = (2028 - y) * 12 + (12 - m)
    ci['proj'] = (1 + rate) ** (meses_proj / 12) - 1

    key = lambda u, cli: f"{u}|{norm(cli).split(' ')[0]}"
    old = {key(c['u'], c['cli']): c for c in base['C']}
    novos, usados, pend = [], set(), []
    for s in ler_planilha(a.planilha):
        k = key(s['u'], s['cli']); o = old.get(k)
        c = dict(u=s['u'], cli=s['cli'], cor=s['cor'] or (o or {}).get('cor', ''), venda=s['venda'] or (o or {}).get('venda'),
                 st=s['st'], com=s['com'], nota=(o or {}).get('nota') or s['nota'], P=s['P'], dis=None, entrega=ENTREGA)
        if o:
            usados.add(k)
            for f in ('preco', 'entrada', 'fin', 'contrato', 'vgv'): c[f] = o.get(f)
            c['cubPct'] = (o.get('cub') or {}).get('pct') if o.get('cub') else o.get('cubPct')
            c['cubBase'] = (o.get('cub') or {}).get('base') if o.get('cub') else o.get('cubBase')
            if o.get('st') == 'distrato': c['st'], c['dis'] = 'distrato', o.get('dis')
        elif k in extras:
            e = extras[k]; c.update(preco=e['preco'], entrada=e['entrada'], fin=e['saldo'], contrato=e['contrato'], vgv=e['preco'])
            c['cubPct'] = e.get('cub_pct')
            mm = re.match(r'(\d+) de (\w+) de (\d{4})', e['contrato']); c['cubBase'] = f"{mm.group(3)}-{MESES[mm.group(2).lower()]:02d}" if mm else None
        else:
            pend.append(f"PENDENTE: unidade {s['u']} ({s['cli']}) tem ficha na planilha mas não tem dados de contrato — preencher extras.json com '{k}'")
            continue
        if c['st'] == 'distrato' and not c['dis']:
            pend.append(f"PENDENTE: unidade {s['u']} ({s['cli']}) marcada como distrato na planilha — conferir termo de distrato e marcar no portal")
            c['dis'] = dict(data=next((p[0] for p in c['P'] if p[4] != 'sim'), a.hoje), dev=None, multa=None, quem='gbrac', parc=None)
        c['_bonus'] = s['bonus']
        novos.append(c)
    # contratos da base sem ficha na planilha (vendas novas estimadas, distratos só por termo): mantém
    for k, o in old.items():
        if k not in usados:
            novos.append(dict(o)); 
            if o.get('st') == 'vendida' and not o.get('est'):
                pend.append(f"AVISO: unidade {o['u']} ({o['cli']}) estava na base e não tem mais ficha na planilha — mantida como estava")
    # CUB
    for c in novos:
        pct = c.pop('cubPct', None) if 'cubPct' in c else (c.get('cub') or {}).get('pct')
        b = c.pop('cubBase', None) if 'cubBase' in c else (c.get('cub') or {}).get('base')
        if pct and b and b in serie and c.get('fin'):
            real = serie[ci['ult']] / serie[b] - 1; tot = (1 + real) * (1 + ci['proj']) - 1
            c['cub'] = dict(pct=pct, base=b, cubBase=serie[b], real=round(real, 5), proj=round(ci['proj'], 5), total=round(tot, 5), corr=round(c['fin'] * tot * pct / 100, 2))
        elif pct and b and b not in serie:
            pend.append(f"PENDENTE: CUB SC de {b} não está na série — incluir no --cub"); c['cub'] = None
        else:
            c['cub'] = None if not pct else c.get('cub')
    # bônus e mapa
    bonus = {str(c['u']): c.pop('_bonus') for c in novos if '_bonus' in c and c['st'] == 'vendida' and c['_bonus']}
    for c in novos: c.pop('_bonus', None)
    tab = ler_tabela(a.tabela)
    M = {}
    ativos = {c['u'] for c in novos if c['st'] == 'vendida'}
    for f in range(1, 7):
        for i in range(1, 7):
            u = f * 100 + i; st = norm((tab.get(str(u)) or {}).get('status', ''))
            s = 'vendida' if u in ativos else 'permuta' if 'PERMUT' in st else 'reservada' if 'RESERV' in st else 'disponivel'
            if s == 'disponivel' and 'VENDID' in st: pend.append(f"AVISO: unidade {u} consta vendida na Tabela de Vendas mas não tem contrato no painel")
            M[str(u)] = dict(status=s, cliente='')
    novos.sort(key=lambda c: (c['u'], c['st'] != 'distrato'))
    out = dict(base); out.update(C=novos, M=M, tabela=tab, bonus=bonus, cubSerie=serie, cubInfo=ci, gerado=a.hoje)
    json.dump(out, open(a.out, 'w'), ensure_ascii=False, separators=(',', ':'))
    at = [c for c in novos if c['st'] == 'vendida']
    print(f"OK: {len(novos)} contratos ({len(at)} ativos), VGV vendido {sum(c.get('preco') or 0 for c in at):,.0f}, CUB até {ci['ult']}")
    for p in pend: print(p)

if __name__ == '__main__':
    main()
