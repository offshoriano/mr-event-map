"""Cliente mínimo para os painéis públicos (Publish to Web) da ANP no Power BI.

Lê o modelo de cada relatório e executa consultas semânticas na mesma API
que o próprio visualizador do Power BI usa. Não precisa de login.
"""
import datetime
import json
import urllib.request

API = "https://wabi-brazil-south-api.analysis.windows.net/public/reports"

# Chaves públicas dos painéis da ANP (parte "k" do link app.powerbi.com/view?r=...)
REPORTS = {
    "exploracao": "a13ce9c2-7640-4581-a1b9-850db4f2a623",
    "instalacoes": "44316b9b-d2dd-4795-9770-cff89719c354",
    "producao": "97124311-5d8a-474e-b779-006a3e44c23f",
    "pocos": "9c468842-ffd5-451a-aad1-dc04b42853fa",
}


def _post(url, key, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method="POST" if data else "GET")
    req.add_header("X-PowerBI-ResourceKey", key)
    req.add_header("Content-Type", "application/json;charset=UTF-8")
    req.add_header("Accept", "application/json")
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read().decode("utf-8"))


class Report:
    def __init__(self, name):
        self.key = REPORTS[name]
        meta = _post(f"{API}/{self.key}/modelsAndExploration?preferReadOnlySession=true", self.key)
        model = meta["models"][0]
        self.model_id = model["id"]
        self.dataset_id = model["dbName"]
        self.report_id = meta["exploration"]["report"]["objectId"]
        self.last_refresh = model.get("LastRefreshTime")
        cfg = json.loads(meta["exploration"].get("config") or "{}")
        self.report_filters = json.loads(meta["exploration"].get("filters") or "[]")
        self.sections = meta["exploration"]["sections"]
        self._cfg = cfg

    def page_filters(self, page):
        for s in self.sections:
            if s["displayName"] == page:
                return json.loads(s.get("filters") or "[]")
        raise KeyError(page)

    def query(self, select, where=None, filters=(), order_by=None, top=30000):
        """select: lista de ("Entidade", "Coluna", agg) com agg em
        None (coluna), "sum", "count", "min", "max", "avg", "countnn" ou "measure".
        where: lista de (Entidade, Coluna, [valores]) para filtros IN.
        filters: filtros no formato do layout do Power BI (relatório/página).
        """
        aliases, frm = {}, []

        def src(ent):
            if ent not in aliases:
                aliases[ent] = f"t{len(aliases)}"
                frm.append({"Name": aliases[ent], "Entity": ent, "Type": 0})
            return {"SourceRef": {"Source": aliases[ent]}}

        aggfn = {"sum": 0, "avg": 1, "count": 2, "min": 3, "max": 4, "countnn": 5}
        sel = []
        for i, (ent, col, agg) in enumerate(select):
            if agg == "measure":
                e = {"Measure": {"Expression": src(ent), "Property": col}}
            else:
                e = {"Column": {"Expression": src(ent), "Property": col}}
                if agg:
                    e = {"Aggregation": {"Expression": e, "Function": aggfn[agg]}}
            e["Name"] = f"c{i}"
            sel.append(e)

        conds = []
        for f in filters:
            fq = f.get("filter")
            if not fq or not fq.get("Where"):
                continue
            remap = {}
            for fr in fq["From"]:
                src(fr["Entity"])
                remap[fr["Name"]] = aliases[fr["Entity"]]
            s = json.dumps(fq["Where"])
            for old, new in remap.items():
                s = s.replace(f'"Source": "{old}"', f'"Source": "{new}"')
            conds.extend(json.loads(s))
        for ent, col, values in where or []:
            lit = [[{"Literal": {"Value": _lit(v)}}] for v in values]
            conds.append({"Condition": {"In": {
                "Expressions": [{"Column": {"Expression": src(ent), "Property": col}}],
                "Values": lit}}})

        q = {"Version": 2, "From": frm, "Select": sel}
        if conds:
            q["Where"] = conds
        if order_by is not None:
            idx, desc = order_by
            q["OrderBy"] = [{"Direction": 2 if desc else 1,
                             "Expression": {k: v for k, v in sel[idx].items() if k != "Name"}}]
        body = {
            "version": "1.0.0",
            "queries": [{
                "Query": {"Commands": [{"SemanticQueryDataShapeCommand": {
                    "Query": q,
                    "Binding": {
                        "Primary": {"Groupings": [{"Projections": list(range(len(sel)))}]},
                        "DataReduction": {"DataVolume": 4, "Primary": {"Window": {"Count": top}}},
                        "Version": 1,
                    },
                    "ExecutionMetricsKind": 1,
                }}]},
                "QueryId": "",
                "ApplicationContext": {"DatasetId": self.dataset_id,
                                        "Sources": [{"ReportId": self.report_id, "VisualId": "mr"}]},
            }],
            "cancelQueries": [],
            "modelId": self.model_id,
        }
        res = _post(f"{API}/querydata?synchronous=true", self.key, body)
        return _decode(res, len(sel))


def _lit(v):
    if isinstance(v, datetime.datetime):
        return f"datetime'{v.strftime('%Y-%m-%dT%H:%M:%S')}'"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, int):
        return f"{v}L"
    if isinstance(v, float):
        return f"{v}D"
    return "'" + str(v).replace("'", "''") + "'"


def _decode(res, ncols):
    data = res["results"][0]["result"]["data"]
    if "dsr" not in data:
        raise RuntimeError(json.dumps(data)[:2000])
    dsr = data["dsr"]
    if "DataShapes" in dsr and dsr["DataShapes"] and "odata.error" in dsr["DataShapes"][0]:
        raise RuntimeError(json.dumps(dsr["DataShapes"][0]["odata.error"])[:2000])
    ds = dsr["DS"][0]
    dicts = ds.get("ValueDicts", {})
    # A ordem das colunas na resposta não segue o Select: mapeia G0/M0 -> c{i}
    pos = {d["Value"]: int(d["Name"][1:]) for d in data["descriptor"]["Select"]}
    rows_raw = ds["PH"][0].get("DM0", [])
    schema, rows, prev = None, [], [None] * ncols
    for r in rows_raw:
        if "S" in r:
            schema = r["S"]
        vals = list(r.get("C", []))
        rep = r.get("R", 0)
        nul = r.get("Ø", 0)
        row = []
        for i in range(len(schema)):
            if rep & (1 << i):
                row.append(prev[i])
            elif nul & (1 << i):
                row.append(None)
            else:
                v = vals.pop(0)
                dn = schema[i].get("DN")
                if dn is not None and isinstance(v, int):
                    v = dicts[dn][v]
                row.append(v)
        prev = row
        out = [None] * ncols
        for i, col in enumerate(schema):
            out[pos[col["N"]]] = row[i]
        rows.append(out)
    return rows
