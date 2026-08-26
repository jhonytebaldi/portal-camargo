#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Rotina do Plano de Ação Diário — Imobiliária Camargo.

Uso (pela tarefa agendada do Claude):
  python3 rotina.py preparar   # coleta incremental + triagem + lotes p/ análise
  python3 rotina.py publicar   # merge (análises novas + carry-forward) + import

Credenciais via variáveis de ambiente (NUNCA no repositório):
  PA_TOKEN     token de serviço do módulo plano-acao (portal)
  ROBUST_KEY   X-API-Key do Robust        ROBUST_NICK  X-Nickname (Imobcamargo)
  GHL_TOKEN    Private Integration Token  GHL_LOCATION locationId

Estado entre execuções mora no MySQL do portal (api-estado/api-importar).
Arquivos de trabalho (./trabalho): contexto.json, lotes/, analise/.
"""
import json, os, re, sys, time
from datetime import datetime, timezone, timedelta

TZ = timezone(timedelta(hours=-3))
PORTAL = "https://portal.imobcamargo.com.br"
RB = "https://api.robustcrm.io/v1"
GB = "https://services.leadconnectorhq.com"
DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "trabalho")
os.makedirs(DIR, exist_ok=True)

import requests

def env(k):
    v = os.environ.get(k, "")
    if not v: sys.exit(f"ERRO: variável {k} não definida")
    return v

PA = {"X-Portal-Token": env("PA_TOKEN"), "Content-Type": "application/json"}
RH = {"X-Nickname": os.environ.get("ROBUST_NICK", "Imobcamargo"),
      "X-API-Key": env("ROBUST_KEY"), "Accept": "application/json"}
GH = {"Authorization": "Bearer " + env("GHL_TOKEN"), "Version": "2021-07-28",
      "Content-Type": "application/json", "Accept": "application/json"}
LOC = os.environ.get("GHL_LOCATION", "9o1WOaGvZNxhcdSgqAaG")

def rget(url, tries=5):
    for a in range(tries):
        try:
            r = requests.get(url, headers=RH, timeout=60)
            if r.status_code == 200: return r.json()
        except Exception: pass
        time.sleep(2 * (a + 1))
    return None

def norm_phone(t):
    d = re.sub(r"\D", "", t or "")
    if not d: return None
    if d.startswith("55") and len(d) >= 12: return "+" + d
    if len(d) in (10, 11): return "+55" + d
    return "+" + d

def parse_iso(x):
    if not x: return None
    try: return datetime.fromisoformat(x)
    except Exception: return None

MSG_ACOES = {"responder cliente", "follow-up", "reativar", "verificar visita",
             "enviar opções de imóvel", "propor agendamento", "confirmar visita", "pós-visita"}

# =====================================================================
def preparar():
    agora = datetime.now(TZ)
    log = lambda m: print(f"[{datetime.now(TZ):%H:%M:%S}] {m}", flush=True)

    estado = requests.get(f"{PORTAL}/plano-acao/api-estado.php", headers=PA, timeout=60).json()
    if not estado.get("ok"): sys.exit("ERRO ao ler api-estado")
    brokers = estado["brokers"]
    ghl2nome = {b["broker_id"]: b["nome"] for b in brokers}
    rob2broker = {int(b["robust_user_id"]): b["broker_id"] for b in brokers if b.get("robust_user_id")}
    cache = {int(c["atendimento_id"]): c for c in estado["clientes"]}
    itens_ant = {}
    for it in estado.get("itens_dia", []): itens_ant[int(it["atendimento_id"])] = it
    planos_ant = {int(p["robust_atendente"]): p for p in estado.get("planos_dia", [])}

    escopo = json.load(open(os.path.join(os.path.dirname(DIR), "escopo.json")))["robust_ids"]
    escopo = [r for r in escopo if r in rob2broker]
    nomes_rob = {}
    ru = rget(f"{RB}/usuarios?per_page=200")
    for u in (ru or {}).get("data", []): nomes_rob[u["id"]] = u.get("nome") or f"id {u['id']}"

    # ---- 1. atendimentos ativos (stage 0-4) por corretor ----
    cli = {}
    for rid in escopo:
        page, pages = 1, None
        while pages is None or page <= pages:
            j = rget(f"{RB}/atendimentos?ativo=true&atendente={rid}&per_page=500&page={page}")
            if j is None: sys.exit(f"ERRO Robust atendimentos ({rid})")
            for r in j["data"]:
                if r.get("stage") in (0, 1, 2, 3, 4):
                    cli[r["id"]] = {"atendimento_id": r["id"], "cliente_id": r.get("cliente"),
                        "lead": r.get("lead"), "stage": r["stage"], "origin": r.get("origin"),
                        "obs": (r.get("obs") or "")[:600], "criado": r.get("created_at"),
                        "last_update": r.get("last_update"), "robust_atendente": rid,
                        "corretor": nomes_rob.get(rid, str(rid))}
            pages = j["meta"]["pages"]; page += 1; time.sleep(0.8)
    log(f"ativos 0-4 no escopo: {len(cli)}")

    # ---- 2. nome/telefones: cache primeiro, Robust p/ novos ----
    falta_p = []
    for aid, c in cli.items():
        cc = cache.get(aid)
        if cc and cc.get("nome") and cc.get("telefones"):
            c["nome"] = cc["nome"]
            c["tels"] = [norm_phone(t) for t in cc["telefones"].split(",") if norm_phone(t)]
            c["ghl_contact"] = cc.get("ghl_contact_id"); c["ghl_conv"] = cc.get("ghl_conv_id")
            c["last_analise"] = cc.get("last_analise_at"); c["stage_cache"] = cc.get("stage")
        else:
            c["nome"] = None; c["tels"] = []; c["ghl_contact"] = None; c["ghl_conv"] = None
            c["last_analise"] = None; c["stage_cache"] = None
            falta_p.append(aid)
    ids_p = sorted({cli[a]["cliente_id"] for a in falta_p if cli[a]["cliente_id"]})
    for i in range(0, len(ids_p), 80):
        j = rget(f"{RB}/pessoas?per_page=100&ids=" + ",".join(map(str, ids_p[i:i+80])))
        pm = {p["id"]: p for p in (j or {}).get("data", [])}
        for aid in falta_p:
            p = pm.get(cli[aid]["cliente_id"])
            if p:
                cli[aid]["nome"] = cli[aid]["nome"] or p.get("nome")
                tels = sorted({t for t in (norm_phone(p.get(f"tel_{k}")) for k in (1,2,3)) if t})
                if tels: cli[aid]["tels"] = tels
        time.sleep(0.8)
    lids = sorted({cli[a]["lead"] for a in falta_p if not cli[a]["tels"] and cli[a].get("lead")})
    for i in range(0, len(lids), 80):
        j = rget(f"{RB}/leads?per_page=100&ids=" + ",".join(map(str, lids[i:i+80])))
        lm = {l["id"]: l for l in (j or {}).get("data", [])}
        for aid in falta_p:
            l = lm.get(cli[aid].get("lead"))
            if l:
                if not cli[aid]["nome"]: cli[aid]["nome"] = l.get("name")
                t = norm_phone(l.get("phone"))
                if t and not cli[aid]["tels"]: cli[aid]["tels"] = [t]
        time.sleep(0.8)
    log(f"novos resolvidos: {len(falta_p)}")

    # ---- 3. GHL: contato p/ quem não tem; conversa (dono/lastMsg) p/ todos ----
    n = 0
    for aid, c in cli.items():
        if not c["ghl_contact"]:
            for tel in c["tels"][:2]:
                try:
                    r = requests.post(f"{GB}/contacts/search", headers=GH, timeout=40,
                        json={"locationId": LOC, "pageLimit": 1,
                              "filters": [{"field": "phone", "operator": "eq", "value": tel}]})
                    if r.status_code == 429: time.sleep(11); continue
                    cs = r.json().get("contacts", []) if r.status_code == 200 else []
                except Exception: cs = []
                if cs: c["ghl_contact"] = cs[0]["id"]; break
                time.sleep(0.1)
        c["assigned"] = None; c["last_msg"] = None; c["unread"] = 0
        if c["ghl_contact"]:
            try:
                r = requests.get(f"{GB}/conversations/search?locationId={LOC}&contactId={c['ghl_contact']}",
                                 headers=GH, timeout=40)
                if r.status_code == 429: time.sleep(11); r = requests.get(
                    f"{GB}/conversations/search?locationId={LOC}&contactId={c['ghl_contact']}", headers=GH, timeout=40)
                conv = (r.json().get("conversations") or []) if r.status_code == 200 else []
            except Exception: conv = []
            if conv:
                c["ghl_conv"] = conv[0]["id"]; c["assigned"] = conv[0].get("assignedTo")
                c["last_msg"] = conv[0].get("lastMessageDate"); c["unread"] = conv[0].get("unreadCount", 0)
        n += 1
        if n % 100 == 0: log(f"ghl {n}/{len(cli)}")
        time.sleep(0.1)

    # ---- 4. triagem: quem precisa de re-análise ----
    rean = []
    for aid, c in cli.items():
        la = parse_iso(c.get("last_analise"))
        lm = datetime.fromtimestamp(c["last_msg"]/1000, TZ) if c.get("last_msg") else None
        lu = parse_iso(c.get("last_update"))
        precisa = (la is None or aid not in itens_ant
                   or (lm and lm > la) or (lu and lu > la)
                   or (c.get("stage_cache") is not None and int(c["stage_cache"]) != c["stage"]))
        if precisa: rean.append(aid)
    log(f"re-análise: {len(rean)} de {len(cli)}")

    # ---- 5. andamentos + mensagens só p/ re-análise ----
    for k, aid in enumerate(rean):
        c = cli[aid]
        j = rget(f"{RB}/andamentos?atendimento_id={aid}&per_page=100")
        ands = sorted((j or {}).get("data", []), key=lambda a: a.get("created_at") or "")[-6:]
        c["andamentos"] = [{"tipo": a.get("tipo"), "acao": a.get("acao"),
            "descricao": (a.get("descricao") or "")[:400], "stage_current": a.get("stage_current"),
            "date_init": a.get("date_init"), "created_at": a.get("created_at")} for a in ands]
        c["msgs"] = []
        if c.get("ghl_conv"):
            try:
                r = requests.get(f"{GB}/conversations/{c['ghl_conv']}/messages?limit=40", headers=GH, timeout=40)
                arr = (r.json().get("messages", {}) or {}).get("messages", []) if r.status_code == 200 else []
            except Exception: arr = []
            msgs = [{"dir": m.get("direction"), "src": m.get("source"), "date": m.get("dateAdded"),
                     "body": (m.get("body") or "")[:280]}
                    for m in arr if not (m.get("messageType", "").startswith("TYPE_ACTIVITY")
                                         or m.get("messageType") == "TYPE_INTERNAL_COMMENT")]
            c["msgs"] = list(reversed(msgs))[-25:]
        if k % 50 == 0: log(f"detalhe {k}/{len(rean)}")
        time.sleep(0.4)

    # ---- 6. pré-score dos re-analisados ----
    BASE = {0: 30, 1: 35, 2: 45, 3: 55, 4: 65}
    for aid in rean:
        c = cli[aid]; pre = BASE.get(c["stage"], 30); flags = []
        msgs = c.get("msgs", [])
        datas = [d for d in (parse_iso(c.get("last_update")), parse_iso(c.get("criado"))) if d]
        for a in c.get("andamentos", []):
            d = parse_iso(a.get("created_at"));  datas += [d] if d else []
        if c.get("last_msg"): datas.append(datetime.fromtimestamp(c["last_msg"]/1000, TZ))
        dias = (agora - max(datas)).days if datas else 999
        c["dias_parado"] = dias
        if msgs and msgs[-1]["dir"] == "inbound":
            h = 0
            d = parse_iso(msgs[-1].get("date"))
            if d: h = max(0, (agora - d).total_seconds()/3600)
            pre += 25 + min(10, int(h/12)); flags.append(f"cliente_esperando_{int(h)}h")
        criado = parse_iso(c.get("criado"))
        manual = any(m["dir"] == "outbound" and m.get("src") in ("app", "api") for m in msgs)
        if c["stage"] == 0 and criado and (agora - criado).days <= 2 and not manual:
            pre += 20; flags.append("lead_novo_sem_contato")
        for a in c.get("andamentos", []):
            di = parse_iso(a.get("date_init"))
            if di and di > agora and (di - agora).days <= 3:
                pre += 15; flags.append("compromisso_" + di.strftime("%d/%m %H:%M")); break
        if c.get("unread"): pre += 5; flags.append("nao_lidas")
        pre -= min(30, max(0, dias - 2))
        c["pre_score"] = max(5, min(95, pre)); c["flags"] = flags

    # ---- 7. auto-checks (tarefas do plano anterior cumpridas) ----
    auto = []
    data_ant = estado.get("ultimo_plano")
    for aid, it in itens_ant.items():
        if int(it.get("feito") or 0): continue
        c = cli.get(aid)
        pl = planos_ant.get(int(cache.get(aid, {}).get("robust_atendente") or 0)) or {}
        criado_pl = parse_iso((pl.get("criado_em") or "").replace(" ", "T"))
        if c is None:
            auto.append({"data": data_ant, "atendimento_id": aid, "motivo": "saiu do funil ativo"})
            continue
        if it["acao"] in MSG_ACOES and criado_pl:
            for m in c.get("msgs", []):
                dm = parse_iso(m.get("date"))
                if m["dir"] == "outbound" and m.get("src") in ("app", "api") and dm and dm > criado_pl:
                    auto.append({"data": data_ant, "atendimento_id": aid, "motivo": "mensagem enviada"}); break
        elif c.get("stage_cache") is not None and c["stage"] > int(c["stage_cache"]):
            auto.append({"data": data_ant, "atendimento_id": aid, "motivo": "avançou de estágio"})
    log(f"auto-checks: {len(auto)}")

    # ---- 8. grava contexto + lotes ----
    ctx = {"gerado_em": agora.isoformat(), "data_plano": agora.strftime("%Y-%m-%d"),
           "ultimo_plano": data_ant, "escopo": escopo,
           "rob2broker": {str(k): v for k, v in rob2broker.items()},
           "ghl2nome": ghl2nome, "auto_checks": auto,
           "itens_ant": {str(k): v for k, v in itens_ant.items()},
           "clientes": {str(k): v for k, v in cli.items()}, "rean": rean}
    json.dump(ctx, open(os.path.join(DIR, "contexto.json"), "w"), ensure_ascii=False)
    os.makedirs(os.path.join(DIR, "lotes"), exist_ok=True)
    for f in os.listdir(os.path.join(DIR, "lotes")): os.remove(os.path.join(DIR, "lotes", f))
    os.makedirs(os.path.join(DIR, "analise"), exist_ok=True)
    for f in os.listdir(os.path.join(DIR, "analise")): os.remove(os.path.join(DIR, "analise", f))
    sel = sorted(rean)
    TAM = 40
    for i in range(0, len(sel), TAM):
        lote = []
        for aid in sel[i:i+TAM]:
            c = cli[aid]
            lote.append({"atendimento_id": aid, "corretor": c["corretor"],
                "nome": c.get("nome") or "(sem nome)", "tels": c.get("tels", []),
                "stage": c["stage"], "origin": c.get("origin"), "criado": c.get("criado"),
                "dias_parado": c.get("dias_parado"), "pre_score": c.get("pre_score", 30),
                "flags": c.get("flags", []), "obs": c.get("obs") or "",
                "andamentos": c.get("andamentos", []),
                "tem_conversa": bool(c.get("ghl_conv")), "msgs": c.get("msgs", [])})
        json.dump(lote, open(os.path.join(DIR, "lotes", f"lote_{i//TAM:02d}.json"), "w"), ensure_ascii=False)
    print(json.dumps({"ok": True, "clientes": len(cli), "re_analise": len(rean),
                      "lotes": (len(sel)+TAM-1)//TAM, "auto_checks": len(auto),
                      "data_plano": ctx["data_plano"]}, ensure_ascii=False))

# =====================================================================
def publicar():
    ctx = json.load(open(os.path.join(DIR, "contexto.json")))
    cli = {int(k): v for k, v in ctx["clientes"].items()}
    itens_ant = {int(k): v for k, v in ctx["itens_ant"].items()}
    rob2broker = {int(k): v for k, v in ctx["rob2broker"].items()}
    ghl2nome = ctx["ghl2nome"]
    DATA = ctx["data_plano"]

    ACOES = {"responder cliente","follow-up","enviar opções de imóvel","propor agendamento",
             "confirmar visita","verificar visita","pós-visita","avançar proposta",
             "reativar","encerrar","alinhar titularidade"}
    ana = {}
    ldir = os.path.join(DIR, "analise")
    for f in sorted(os.listdir(ldir)):
        if f.endswith(".json"):
            for o in json.load(open(os.path.join(ldir, f))):
                if o.get("acao") not in ACOES: o["acao"] = "follow-up"
                ana[int(o["atendimento_id"])] = o
    faltam = [a for a in ctx["rean"] if a not in ana]
    if faltam: print(f"AVISO: {len(faltam)} re-análises não entregues — usarão carry-forward")

    def faixa(score, acao):
        if acao == "encerrar": return "branco"
        if score >= 75: return "vermelho"
        if score >= 55: return "amarelo"
        if score >= 40: return "azul"
        return "branco"

    STG = {0:"Lead",1:"Atendimento",2:"Agendamento",3:"Visita",4:"Proposta"}
    def fone_fmt(t):
        d = (t or "").lstrip("+")
        if d.startswith("55") and len(d) in (12, 13):
            return f"({d[2:4]}) {d[4:-4]}-{d[-4:]}"
        return t or ""

    por, n_transf, n_alinhar, n_carry = {}, 0, 0, 0
    for aid, c in cli.items():
        a = ana.get(aid)
        if a:  # análise nova
            score = max(5, min(98, int(c.get("pre_score", 30)) + int(a.get("ajuste_score") or 0)))
            item = {"acao": a["acao"], "titulo": (a.get("titulo") or "")[:255],
                    "justificativa": (a.get("justificativa") or "")[:1000] or None,
                    "msg_sugerida": a.get("msg_sugerida") or None,
                    "nome_sugerido": a.get("nome_detectado") or None, "score": score}
            if a["acao"] == "encerrar" and a.get("encerrar_motivo"):
                item["justificativa"] = ((item["justificativa"] or "") + " Motivo: " + a["encerrar_motivo"]).strip()
        else:  # carry-forward do plano anterior
            ant = itens_ant.get(aid)
            if not ant: continue
            n_carry += 1
            item = {"acao": ant["acao"], "titulo": ant["titulo"],
                    "justificativa": ant.get("justificativa"), "msg_sugerida": ant.get("msg_sugerida"),
                    "nome_sugerido": ant.get("nome_sugerido"), "score": int(ant.get("score") or 30)}
        # divergência de titularidade (sempre recalculada)
        assigned, esperado = c.get("assigned"), rob2broker.get(c["robust_atendente"])
        if assigned and esperado and assigned != esperado:
            outro_full = ghl2nome.get(assigned, "outro corretor")
            outro = outro_full.split()[0].capitalize()
            if c["stage"] in (0, 1):
                item.update(acao="encerrar",
                    titulo=f"Encerrar o atendimento — transferido para {outro_full} no WeSales",
                    justificativa=(f"Esse contato foi transferido pro {outro} por estar há mais de "
                                   f"10 dias sem atividade e sem avançar pro estágio agendamento+."),
                    msg_sugerida=None)
                n_transf += 1
            else:
                item.update(acao="alinhar titularidade",
                    titulo=f"Alinhar titularidade — no WeSales o cliente está com {outro_full}",
                    justificativa=((item.get("justificativa") or "") +
                        f" ⚠ Donos divergem: Robust com {c['corretor'].split()[0]}, WeSales com {outro}. "
                        f"Gestor decide quem fica e ajusta os dois sistemas.").strip(),
                    msg_sugerida=None)
                n_alinhar += 1
        fx = faixa(item["score"], item["acao"])
        it = {"atendimento_id": aid, "cliente_nome": c.get("nome") or "(sem nome)",
              "telefones": ", ".join(fone_fmt(t) for t in c.get("tels", [])[:2]),
              "stage": c["stage"], "faixa": fx,
              "origem": "conversa" if c.get("ghl_conv") else "andamentos", **item}
        por.setdefault(c["robust_atendente"], {"nome": c["corretor"], "itens": []})["itens"].append(it)

    ORD = {"vermelho":0,"amarelo":1,"azul":2,"branco":3}
    EMO = {"vermelho":"🔴","amarelo":"🟡","azul":"🔵","branco":"⚪"}
    ROT = {"vermelho":"AGORA CEDO","amarelo":"AINDA HOJE","azul":"ESTA SEMANA","branco":"MANTER / AVALIAR ENCERRAR"}
    DIAS = ['seg','ter','qua','qui','sex','sáb','dom']
    dt = datetime.strptime(DATA, "%Y-%m-%d")
    rot_data = f"{DIAS[dt.weekday()]} {dt.strftime('%d/%m')}"

    planos = []
    for rid, p in sorted(por.items(), key=lambda kv: kv[1]["nome"]):
        itens = sorted(p["itens"], key=lambda i: (ORD[i["faixa"]], -i["score"]))
        nfx = {f: sum(1 for i in itens if i["faixa"] == f) for f in ORD}
        lin = [f"*PLANO DE AÇÃO — {p['nome'].split()[0]} · {rot_data}*",
               f"Carteira ativa: {len(itens)} clientes · prioridades: 🔴{nfx['vermelho']} 🟡{nfx['amarelo']} 🔵{nfx['azul']} ⚪{nfx['branco']}", ""]
        n = 0; fx_atual = None
        for i in itens:
            if i["faixa"] in ("azul", "branco") or n >= 14: break
            if i["faixa"] != fx_atual:
                fx_atual = i["faixa"]; lin.append(f"{EMO[fx_atual]} *{ROT[fx_atual]}*")
            n += 1
            tel = f" · {i['telefones']}" if i["telefones"] else ""
            lin.append(f"{n}. *{i['cliente_nome']}*{tel} ({STG.get(i['stage'])})")
            lin.append(f"   → {i['titulo']}")
        lin += ["", f"🔵 Esta semana: {nfx['azul']} · ⚪ Manter/encerrar: {nfx['branco']}",
                "Lista completa com telefones e mensagens prontas no portal:",
                "portal.imobcamargo.com.br/plano-acao/"]
        planos.append({"robust_atendente": rid, "broker_id": rob2broker.get(rid),
                       "corretor_nome": p["nome"], "texto_whatsapp": "\n".join(lin), "itens": itens})

    agora = datetime.now(TZ).isoformat()
    clientes_payload = []
    for aid, c in cli.items():
        clientes_payload.append({"atendimento_id": aid, "cliente_id": c.get("cliente_id"),
            "nome": c.get("nome") or "", "telefones": ", ".join(fone_fmt(t) for t in c.get("tels", [])[:2]),
            "robust_atendente": c["robust_atendente"], "broker_id": rob2broker.get(c["robust_atendente"]),
            "stage": c["stage"], "ghl_contact_id": c.get("ghl_contact"), "ghl_conv_id": c.get("ghl_conv"),
            "last_msg_at": c.get("last_msg"),
            "last_analise_at": agora if aid in ana else (c.get("last_analise") or agora),
            "resumo": None})

    body = {"data": DATA, "clientes": clientes_payload, "planos": planos,
            "auto_checks": [{"data": a["data"], "atendimento_id": a["atendimento_id"]}
                             for a in ctx["auto_checks"] if a.get("data")]}
    r = requests.post(f"{PORTAL}/plano-acao/api-importar.php", headers=PA, json=body, timeout=180)
    res = {}
    try: res = r.json()
    except Exception: pass
    resumo = {"ok": bool(res.get("ok")), "http": r.status_code, "data": DATA,
              "planos": res.get("planos"), "itens": res.get("itens"),
              "auto_checks": res.get("auto_checks"), "re_analisados": len(ana),
              "carry_forward": n_carry, "encerrar_transferidos": n_transf,
              "alinhar_titularidade": n_alinhar}
    json.dump(resumo, open(os.path.join(DIR, "resumo_publicacao.json"), "w"), ensure_ascii=False)
    print(json.dumps(resumo, ensure_ascii=False))
    if not res.get("ok"): sys.exit("ERRO na importação: " + r.text[:300])

# =====================================================================
if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "preparar": preparar()
    elif cmd == "publicar": publicar()
    else: sys.exit("uso: rotina.py preparar|publicar")
