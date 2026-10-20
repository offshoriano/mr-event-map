"""Baixa os dados dos painéis públicos da ANP e grava anp/data/*.json.

Uso: python3 scripts/anp/fetch.py
"""
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
from pbi import Report  # noqa: E402

OUT = os.path.join(os.path.dirname(__file__), "..", "..", "anp", "data")
BBL_PER_M3 = 6.2898


def ym(ms):
    if ms is None:
        return None
    d = dt.datetime(1970, 1, 1) + dt.timedelta(milliseconds=int(ms))
    return f"{d.year:04d}-{d.month:02d}"


def day(ms):
    if ms is None or ms == "":
        return None
    if isinstance(ms, str):
        return ms
    d = dt.datetime(1970, 1, 1) + dt.timedelta(milliseconds=int(ms))
    return d.strftime("%Y-%m-%d")


def num(v, nd=1):
    if v in (None, ""):
        return None
    try:
        return round(float(v), nd)
    except ValueError:
        return None


def txt(v):
    return (v or "").strip() if isinstance(v, str) else v


def save(name, obj):
    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, f"{name}.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, separators=(",", ":"))
    print(f"  {path}: {os.path.getsize(path) // 1024} KB")


def producao():
    print("Produção (Boletim Mensal)")
    r = Report("producao")
    years = list(range(2010, dt.date.today().year + 1))

    # Série nacional mensal
    f = r.report_filters + r.page_filters("Extração - Relatório Executivo")
    m = [("DM_SDP_BMP", "DATA", None)] + [(e, c, "measure") for e, c in [
        ("DM_SDP_BMP_MESCLADO", "Vazao_petroleo_generica_kBBL"),
        ("DM_SDP_BMP_MESCLADO", "vazao_petroleo_kbbl_terra_2"),
        ("DM_SDP_BMP_MESCLADO", "Vazao_petroleo_generica_kBBL mar"),
        ("VSDP_BMP_ZONA_PRODUTORA_POCO", "Prod_MPetroleo/D bbl - pre-sal"),
        ("DM_SDP_BMP_MESCLADO", "Vazao_gas_generica_M"),
        ("DM_SDP_BMP_MESCLADO", "Vazao_gas_generica_M_mar"),
        ("VSDP_BMP_ZONA_PRODUTORA_POCO", "Prod_Gas/D MMm3 - pre-sal"),
        ("DM_SDP_BMP_MESCLADO", "Vazao_queima_generica_M"),
        ("DM_SDP_BMP_MESCLADO", "Aproveitamento"),
    ]]
    serie = []
    for chunk in (years[i:i + 6] for i in range(0, len(years), 6)):
        for row in r.query(m, filters=f, where=[("DM_SDP_BMP", "ANO", chunk)]):
            serie.append({
                "m": ym(row[0]), "oil": num(row[1]), "oilT": num(row[2]), "oilM": num(row[3]),
                "pre": num(row[4]), "gas": num(row[5], 2), "gasM": num(row[6], 2),
                "gasPre": num(row[7], 2), "queima": num(row[8], 2), "aprov": num(row[9], 4),
            })
    serie = sorted({s["m"]: s for s in serie if s["m"] and s["oil"]}.values(), key=lambda s: s["m"])
    last = serie[-1]["m"]
    ly, lm = int(last[:4]), int(last[5:])

    # Produção por campo, últimos 25 meses (permite comparar com o ano anterior).
    # Usa o nome agrupado da ANP: Búzios + Búzios_ECO viram "Búzios", como no ranking oficial.
    f = r.report_filters + r.page_filters("Extração MME - Produção de petróleo e gás por campo")
    rows = r.query([
        ("DM_MID_CAMPO", "NOM_CAMPO_AGRUPADO_CERTO", None),
        ("DM_MID_CAMPO_MESCLADO", "NOM_BACIA", None),
        ("DM_MID_CAMPO_MESCLADO", "NOM_FANTASIA_OPERADOR", None),
        ("DM_MID_CAMPO_MESCLADO", "ESTADO", None),
        ("DM_MID_CAMPO_MESCLADO", "DSC_TERRA_OU_MAR", None),
        ("DM_SDP_BMP", "DATA", None),
        ("DM_SDP_BMP_MESCLADO", "VAZAO_PROD_PETROLEO", "sum"),
        ("DM_SDP_BMP_MESCLADO", "VAZAO_PROD_GAS", "sum"),
    ], filters=f, where=[("DM_SDP_BMP", "ANO", [ly - 2, ly - 1, ly])])
    months = sorted({ym(x[5]) for x in rows})[-25:]
    idx = {k: i for i, k in enumerate(months)}
    campos = {}
    for nome, bacia, op, uf, amb, d, oil, gas in rows:
        k = ym(d)
        if k not in idx or not nome:
            continue
        c = campos.setdefault(nome, {"n": nome, "b": txt(bacia), "op": txt(op), "uf": txt(uf),
                                     "amb": txt(amb), "oil": [0] * len(months), "gas": [0] * len(months)})
        if op and not c["op"]:
            c["op"] = txt(op)
        # m³/d -> bbl/d ; mil m³/d
        c["oil"][idx[k]] += round(float(oil or 0) * BBL_PER_M3)
        c["gas"][idx[k]] = round(c["gas"][idx[k]] + float(gas or 0), 1)
    campos = [c for c in campos.values() if any(c["oil"]) or any(c["gas"])]

    # Produção por instalação (UEP marítima), mesmos meses
    f = r.report_filters + r.page_filters("Extração MME - Queima por UEP")
    rows = r.query([
        ("INSTALACOES DPP + SIGEP (T)", "NOM_INSTALACAO", None),
        ("INSTALACOES DPP + SIGEP (T)", "DSC_CLASSIFICACAO_UPSTREAM", None),
        ("Calendario", "Date", None),
        ("FT_SDP_BMP_INSTALACOES_MESCLADO", "VAZAO PETROLEO UEP", "measure"),
        ("FT_SDP_BMP_INSTALACOES_MESCLADO", "VAZAO GAS UEP", "measure"),
        ("FT_SDP_BMP_INSTALACOES_MESCLADO", "Vazao_queima_gas_instalacao", "measure"),
    ], filters=f, where=[("DM_SDP_BMP", "ANO", [ly - 2, ly - 1, ly])])
    ueps = {}
    for nome, tipo, d, oil, gas, queima in rows:
        k = ym(d)
        if k not in idx or not nome:
            continue
        u = ueps.setdefault(nome, {"n": nome, "t": txt(tipo), "oil": [0] * len(months),
                                   "gas": [0] * len(months), "q": [0] * len(months)})
        u["oil"][idx[k]] = round(float(oil or 0) * BBL_PER_M3)
        u["gas"][idx[k]] = round(float(gas or 0), 1)
        u["q"][idx[k]] = round(float(queima or 0), 1)
    ueps = [u for u in ueps.values() if any(u["oil"]) or any(u["gas"])]

    save("producao", {"atualizado": r.last_refresh, "ultimoMes": last, "serie": serie,
                      "meses": months, "campos": campos, "ueps": ueps})


def instalacoes():
    print("Instalações marítimas em operação")
    r = Report("instalacoes")
    f = r.report_filters + r.page_filters("Instalações em Operação")
    T = "INSTALACOES DPP + SIGEP (T)"
    rows = r.query([
        (T, "SIG_INSTALACAO", None), (T, "NOM_INSTALACAO", None), (T, "CAMPOS", "measure"),
        ("VSDP_BMP_PRODUCAO_POCO", "BACIA2", "measure"), (T, "NOM_OPERADOR_CONCESSAO", None),
        (T, "DSC_CLASSIFICACAO_UPSTREAM", None), (T, "MED_LAMINA_DAGUA", "sum"),
        (T, "DSC_SISTEMA_ESCOAMENTO", None), (T, "NUM_ANO_INICIO_OPERACAO", "sum"),
        (T, "ANO_TERMINO_VIDA_UTIL", "sum"), (T, "MED_LATITUDE", "sum"), (T, "MED_LONGITUDE", "sum"),
        ("DM_SSM_WBS_FUNCAO", "CAP PROCESSAMENTO PETROLEO", "measure"),
        ("DM_SSM_WBS_FUNCAO", "CAP PROCESSAMENTO GAS", "measure"),
    ], filters=f)
    out = []
    for sig, nome, campos, bacia, op, tipo, lam, esc, ini, fim, lat, lon, capo, capg in rows:
        out.append({"sig": txt(sig), "n": txt(nome), "campos": txt(campos), "b": txt(bacia),
                    "op": txt(op), "t": txt(tipo), "lam": num(lam, 0), "esc": txt(esc),
                    "ini": int(float(ini)) if ini else None, "fim": int(float(fim)) if fim else None,
                    "lat": num(lat, 4), "lon": num(lon, 4),
                    "capO": num(capo, 0), "capG": num(capg, 0)})
    save("instalacoes", {"atualizado": r.last_refresh, "itens": out})


def exploracao():
    print("Fase de exploração")
    r = Report("exploracao")
    B = "VSEP_BLOCOS_CONCESSAO"
    rows = r.query([(B, c, None) for c in (
        "BLOCO", "CONTRATO", "OPERADOR", "CONCESSIONÁRIOS", "BACIA", "LOCALIZAÇÃO", "BID",
        "ASSINATURA CONTRATO", "VENCIMENTO FASE EXPLORATÓRIA", "Área (mil km2)", "QTD POÇOS PERFURADOS",
        "Regime Contratual", "PRESENÇA PAD", "DATA INÍCIO SUSPENSÃO")] + [(B, "DHA_ATUALIZACAO", "max")],
        filters=r.report_filters + r.page_filters("Blocos sob contrato - tabela"))
    blocos = [{"bl": txt(x[0]), "ct": txt(x[1]), "op": txt(x[2]), "socios": txt(x[3]), "b": txt(x[4]),
               "amb": txt(x[5]), "rod": txt(x[6]), "ass": day(x[7]), "venc": day(x[8]),
               "area": num(x[9], 3), "pocos": int(float(x[10])) if x[10] not in (None, "") else 0,
               "reg": txt(x[11]), "pad": txt(x[12]), "susp": day(x[13])} for x in rows]
    atual = day(max((x[14] for x in rows if x[14]), default=None))

    P = "VSEP_PLANO_AVALIACAO"
    rows = r.query([(P, c, None) for c in (
        "PLANO DE AVALIAÇÃO", "OPERADOR", "BLOCO", "BACIA", "RODADA", "SITUAÇÃO", "INÍCIO EFETIVO",
        "TÉRMINO PREVISTO", "Localização4")], filters=r.report_filters)
    # O filtro da página usa rótulos antigos ("Ativo"); o dado atual usa "PAD Ativo" etc.
    pads = [{"pad": txt(x[0]), "op": txt(x[1]), "bl": txt(x[2]), "b": txt(x[3]), "rod": txt(x[4]),
             "sit": txt(x[5]), "ini": day(x[6]), "fim": day(x[7]), "amb": txt(x[8])}
            for x in rows if x[2] and x[6] and x[5] and x[5] != "Inativo"]

    rows = r.query([(P, c, None) for c in (
        "PLANO DE AVALIAÇÃO", "BLOCO", "BACIA", "OPERADOR", "RODADA", "DATA DECLARAÇÃO COMERCIALIDADE",
        "CAMPO", "Localização4")], filters=r.report_filters + r.page_filters("Declarações de Comercialidade"))
    dcs = [{"pad": txt(x[0]), "bl": txt(x[1]), "b": txt(x[2]), "op": txt(x[3]), "rod": txt(x[4]),
            "data": day(x[5]), "campo": txt(x[6]), "amb": txt(x[7])} for x in rows if x[5]]

    W = "VSEP_POCO"
    rows = r.query([(W, c, None) for c in (
        "POÇO ANP", "BACIA", "ESTADO", "TERRA/MAR", "OPERADOR INICIAL", "PERF_INICIO", "CONCLUSAO",
        "HOUVE_ND_SIGEP_DPP", "ATINGIU PRÉ-SAL?", "PROFUNDIDADE SONDADOR AJUSTADO")],
        filters=r.report_filters + r.page_filters("Poços"))
    pocos = [{"p": txt(x[0]), "b": txt(x[1]), "uf": txt(x[2]), "amb": txt(x[3]), "op": txt(x[4]),
              "ini": day(x[5]), "fim": day(x[6]), "desc": txt(x[7]), "presal": txt(x[8]),
              "prof": num(x[9], 0)} for x in rows]
    save("exploracao", {"atualizado": atual or r.last_refresh, "blocos": blocos, "pads": pads,
                        "declaracoes": dcs, "pocos": pocos})


def pocos():
    print("Poços na fase de produção")
    r = Report("pocos")
    S = "SONDA_DPP+SIGEP"
    f = r.report_filters + r.page_filters("Sondas em Operação Por Período")
    hist = r.query([("FT_SOP", "DAT_REFERENCIA_SOP", None), ("FT_SOP", "NUMERO DE SONDAS", "measure")], filters=f)
    hist = sorted([[day(d), int(float(n))] for d, n in hist if d and n], key=lambda x: x[0])
    # Os últimos dias chegam incompletos (operadoras ainda reportando): usa o
    # dia mais recente com pelo menos 90% do pico dos 30 dias anteriores.
    ultimo = None
    if hist:
        pico = max(n for _, n in hist[-30:])
        ultimo = next(d for d, n in reversed(hist) if n >= 0.9 * pico)
        hist = [h for h in hist if h[0] <= ultimo]
    sondas = []
    if ultimo:
        rows = r.query([(S, "SIG_INSTALACAO", None), (S, "NOM_INSTALACAO", None), (S, "NOM_FANTASIA", None),
                        (S, "NOM_AMBIENTE_DPP", None), ("FT_SOP", "CAMPO_CONCAT", "measure")],
                       filters=f, where=[("FT_SOP", "DAT_REFERENCIA_SOP", [dt.datetime.strptime(ultimo, "%Y-%m-%d")])])
        sondas = [{"sig": txt(a), "n": txt(b), "op": txt(c), "amb": txt(e), "campos": txt(g)}
                  for a, b, c, e, g in rows]

    f = r.report_filters + r.page_filters("Gráficos Intervenções")
    rows = r.query([("FT_SOP", "ANO", None), ("FT_SOP", "DSC_RAZAO_SOCIAL", None),
                    ("FT_SOP", "AMBIENTE", None), ("FT_SOP", "DSC_OBJETIVO_INTERVENCAO", None),
                    ("FT_SOP", "CONT INT", "measure")], filters=f)
    interv = [{"ano": txt(str(a)), "op": txt(b), "amb": txt(c), "obj": txt(d), "n": int(float(n))}
              for a, b, c, d, n in rows if n]

    f = r.report_filters + r.page_filters("Situação Poços")
    C = "VSDP_CONSULTA_POCO"
    rows = r.query([(C, "ULTIMO STATUS CARGA", None), (C, "ME_ATUAL", None), (C, "COD_TERRA_MAR", None),
                    (C, "NOM_OPERADOR", None), (C, "CONT POCO", "measure")], filters=f)
    situacao = [{"sit": txt(a), "me": txt(b), "amb": txt(c), "op": txt(d), "n": int(float(n))}
                for a, b, c, d, n in rows if n]
    save("pocos", {"atualizado": r.last_refresh, "sondasHist": hist, "sondasData": ultimo,
                   "sondas": sondas, "intervencoes": interv, "situacao": situacao})


if __name__ == "__main__":
    steps = {"producao": producao, "instalacoes": instalacoes, "exploracao": exploracao, "pocos": pocos}
    for name in (sys.argv[1:] or steps):
        steps[name]()
