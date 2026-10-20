#!/usr/bin/env python3
"""Busca vagas no Brasil direto nos sites de carreira das empresas de óleo e gás
e grava vagas/automaticas.json. Roda toda semana pelo GitHub Actions."""
import html, json, re, sys, time, urllib.request, urllib.error, zlib
from datetime import date, datetime, timezone
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
SAIDA = RAIZ / 'vagas' / 'automaticas.json'
VIDEOS = json.loads((Path(__file__).parent / 'videos.json').read_text())
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'

# filtro: True = só vagas com palavra de óleo, gás, offshore ou marítimo
EMPRESAS = [
    # Gupy
    ('gupy', 'PRIO', 'prio', False),
    ('gupy', 'Brava Energia', 'bravaenergia', False),
    ('gupy', 'Ocyan', 'ocyan', False),
    ('gupy', 'Foresea', 'foresea', False),
    ('gupy', 'MODEC', 'modec-br', False),
    ('gupy', 'Perbras', 'perbras', False),
    ('gupy', 'Wilson Sons', 'wilsonsons', True),
    ('gupy', 'Constellation', 'theconstellation', False),
    ('gupy', 'Altera&Ocyan', 'alteraocyan', False),
    ('gupy', 'Origem Energia', 'origemenergia', False),
    ('gupy', 'Tecnosonda', 'tecnosonda', False),
    # InHire
    ('inhire', 'OceanPact', 'oceanpact', False),
    ('inhire', 'Camorim', 'camorim', False),
    ('inhire', 'PetroReconcavo', 'petroreconcavo', False),
    ('inhire', 'Wilson Sons Offshore', 'wsut', False),
    ('inhire', 'Solstad', 'solstad', False),
    # SAP SuccessFactors
    ('rmk', 'SBM Offshore', 'careers.sbmoffshore.com', False),
    ('rmk', 'TechnipFMC', 'careers.technipfmc.com', False),
    ('rmk', 'Subsea7', 'careers.subsea7.com', False),
    ('rmk', 'Yinson', 'careers.yinson.com', False),
    # Workday
    ('workday', 'Baker Hughes', ('bakerhughes', 'BakerHughes'), False),
    ('workday', 'Shell', ('shell', 'ShellCareers'), False),
    ('workday', 'Equinor', ('equinor', 'EQNR'), False),
    ('workday', 'Noble', ('noblecorp', 'noblecorp'), False),
    ('workday', 'Fugro', ('fugro', 'Careers'), False),
    ('workday', 'Repsol Sinopec', ('repsol', 'Sinopec'), False),
    # Oracle
    ('oracle', 'NOV', ('egay.fa.us6.oraclecloud.com', 'CX_2001'), False),
    ('oracle', 'Seadrill', ('fa-eykz-saasfaprod1.fa.ocs.oraclecloud.com', 'CX_1'), False),
    ('oracle', 'Valaris', ('efqq.fa.us6.oraclecloud.com', 'CX_1'), False),
    # Radancy
    ('radancy', 'Halliburton', 'careers.halliburton.com', False),
]

OG = re.compile(r'offshore|embarc|plataforma|fpso|sonda|navio|mar[ií]tim|rebocador|conv[eé]s|m[aá]quinas|'
                r'subsea|petr[oó]le|[oó]leo|g[aá]s|po[cç]o|drilling|vessel|marine|rov|estaleiro|naval', re.I)

def get(url, data=None, headers=None, tentativas=3):
    h = {'User-Agent': UA, 'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8'}
    h.update(headers or {})
    for i in range(tentativas):
        try:
            req = urllib.request.Request(url, data=data, headers=h)
            with urllib.request.urlopen(req, timeout=40) as r:
                return r.read().decode('utf-8', 'ignore')
        except urllib.error.HTTPError as e:
            if e.code in (400, 401, 403, 404, 422):
                raise
            err = e
        except Exception as e:
            err = e
        time.sleep(2 * (i + 1))
    raise err

def limpa(s):
    return re.sub(r'\s+', ' ', html.unescape(s or '')).strip()

def gupy(sub):
    s = get(f'https://{sub}.gupy.io/')
    m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', s, re.S)
    jobs = json.loads(m.group(1))['props']['pageProps'].get('jobs') or []
    for j in jobs:
        a = (j.get('workplace') or {}).get('address') or {}
        local = ', '.join(x for x in (a.get('city'), a.get('stateShortName')) if x)
        if (j.get('workplace') or {}).get('workplaceType') == 'remote':
            local = 'Remoto'
        yield dict(titulo=j['title'], local=local or 'Brasil', link=f'https://{sub}.gupy.io/jobs/{j["id"]}',
                   banco=j.get('type') == 'vacancy_type_talent_pool', area=j.get('department') or '')

def rmk(host):
    vistos = set()
    for start in range(0, 200, 25):
        s = get(f'https://{host}/search/?q=&locationsearch=Brazil&searchResultView=LIST&startrow={start}')
        novos = 0
        for m in re.finditer(r'<a[^>]*class="jobTitle-link[^"]*"[^>]*href="([^"]+)"[^>]*>([^<]+)<|'
                             r'<a[^>]*href="([^"]+)"[^>]*class="jobTitle-link[^"]*"[^>]*>([^<]+)<', s):
            href, tit = m.group(1) or m.group(3), m.group(2) or m.group(4)
            if href in vistos:
                continue
            vistos.add(href); novos += 1
            resto = s[m.end():m.end() + 3000]
            loc = re.search(r'class="jobLocation[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*([^<]{2,120})', resto)
            dt = re.search(r'class="jobDate[^"]*"[^>]*>\s*([^<]{4,40})', resto)
            yield dict(titulo=limpa(tit), local=limpa(loc.group(1)) if loc else 'Brasil',
                       link=f'https://{host}{href}', data=limpa(dt.group(1)) if dt else '')
        if not novos:
            break

def workday(cfg):
    tenant, site = cfg
    for n in (5, 3, 1, 2, 4, 103):
        base = f'https://{tenant}.wd{n}.myworkdayjobs.com'
        try:
            out = []
            for off in range(0, 200, 20):
                body = json.dumps({'appliedFacets': {}, 'limit': 20, 'offset': off, 'searchText': 'Brazil'}).encode()
                d = json.loads(get(f'{base}/wday/cxs/{tenant}/{site}/jobs', body, {'Content-Type': 'application/json', 'Accept': 'application/json'}, 1))
                for j in d.get('jobPostings') or []:
                    out.append(dict(titulo=j['title'], local=j.get('locationsText') or 'Brasil',
                                    link=f'{base}/{site}{j["externalPath"]}'))
                if off + 20 >= (d.get('total') or 0):
                    break
            return out
        except Exception:
            continue
    raise RuntimeError('workday sem resposta')

def oracle(cfg):
    host, site = cfg
    url = (f'https://{host}/hcmRestApi/resources/latest/recruitingCEJobRequisitions?onlyData=true&expand=requisitionList'
           f'&finder=findReqs;siteNumber={site},limit=200,offset=0,sortBy=POSTING_DATES_DESC,keyword=%22Brazil%22')
    d = json.loads(get(url, headers={'Accept': 'application/json'}))
    for j in d['items'][0]['requisitionList']:
        if j.get('PrimaryLocationCountry') != 'BR' and not re.search(r'bra[sz]il', j.get('PrimaryLocation') or '', re.I):
            continue
        yield dict(titulo=j['Title'], local=re.sub(r', Bra[sz]il$', '', j['PrimaryLocation']) + ('' if re.search(r'bra[sz]il', j['PrimaryLocation'], re.I) else ', Brasil'),
                   link=f'https://{host}/hcmUI/CandidateExperience/en/sites/{site}/job/{j["Id"]}', data=j.get('PostedDate') or '')

def radancy(host):
    vistos = set()
    for p in range(1, 6):
        s = get(f'https://{host}/search-jobs/Brazil?p={p}')
        achou = 0
        for href, tit in re.findall(r'href="(/job/[^"]+/\d+/\d+)"[^>]*>\s*(?:<h2>)?([^<]{3,150})', s):
            if href in vistos:
                continue
            vistos.add(href); achou += 1
            cidade = href.split('/')[2].replace('-', ' ').title()
            yield dict(titulo=limpa(tit), local=cidade, link=f'https://{host}{href}')
        if not achou:
            break

def inhire(tenant):
    d = json.loads(get('https://api.inhire.app/job-posts/public/pages/lean', headers={'X-Tenant': tenant, 'Accept': 'application/json'}))
    for j in d:
        titulo = limpa(j.get('displayName'))
        # A InHire não traz a cidade separada; quando vem no título ("Cargo - Macaé/RJ"), aproveita
        m = re.search(r'\s[-–|]\s*([A-Za-zÀ-ú .]+?)\s*/\s*([A-Z]{2})\s*$', titulo)
        local = f'{m.group(1).strip()}, {m.group(2)}' if m else 'Brasil'
        if m:
            titulo = titulo[:m.start()].strip()
        yield dict(titulo=titulo, local=local, link=j.get('link') or f'https://{tenant}.inhire.app/vagas/{j["jobId"]}')

LEITORES = dict(inhire=inhire, gupy=gupy, rmk=rmk, workday=workday, oracle=oracle, radancy=radancy)

def classifica(titulo):
    t = titulo.lower()
    for chave, termos in VIDEOS['regras']:
        if re.search(termos, t):
            return chave
    return 'geral'

def regime(titulo, local):
    t = (titulo + ' ' + local).lower()
    if re.search(r'offshore|embarc|plataforma|fpso|sonda|rig\b|vessel|navio|rov|marinh|conv[eé]s|taifeir|oficial de m|escalador|embarca[cç]', t):
        return 'Embarcado'
    if 'remoto' in t or 'remote' in t:
        return 'Remoto'
    return 'Em terra ou a confirmar'

def arruma_local(loc):
    loc = limpa(loc)
    m = re.match(r'BR-([A-Z]{2})-([^-]+)', loc)
    if m:
        cidade = m.group(2).title().replace(' De ', ' de ').replace(' Do ', ' do ').replace('Macae', 'Macaé').replace('Niteroi', 'Niterói')
        return f'{cidade}, {m.group(1)}'
    loc = re.sub(r',?\s*\d{5}-?\d{3}', '', loc)
    loc = re.sub(r',\s*(BR|Brasil|Brazil)\s*$', '', loc)
    loc = re.sub(r'Rio De Janeiro', 'Rio de Janeiro', loc).replace(' - Ventura Office', '')
    loc = loc.replace('Macae', 'Macaé').replace('Sao Joao', 'São João') or 'Brasil'
    # Cidade sem estado vira "Cidade, UF" para o filtro e a busca agruparem igual
    uf = {'Rio de Janeiro': 'RJ', 'Macaé': 'RJ', 'Niterói': 'RJ', 'Rio das Ostras': 'RJ', 'Santos': 'SP', 'São Paulo': 'SP',
          'Vitória': 'ES', 'Salvador': 'BA', 'Belo Horizonte': 'MG', 'Aracaju': 'SE', 'Mossoró': 'RN', 'Natal': 'RN'}
    return f'{loc}, {uf[loc]}' if loc in uf else loc

def slug(s):
    s = re.sub(r'[^a-z0-9]+', '-', s.lower()).strip('-')
    return s[:80]

def main():
    hoje = date.today().isoformat()
    anterior = {}
    if SAIDA.exists():
        try:
            for v in json.loads(SAIDA.read_text())['vagas']:
                anterior[v['link']] = v.get('vistaEm', hoje)
        except Exception:
            pass
    vagas, status = [], []
    for tipo, nome, cfg, filtro in EMPRESAS:
        try:
            itens = list(LEITORES[tipo](cfg))
            if filtro:
                itens = [i for i in itens if OG.search(i['titulo'] + ' ' + i.get('area', ''))]
            for i in itens:
                if not re.search(r'bra[sz]il|,\s*(rj|sp|es|ba|se|rn|ce|am|mg|pr|sc|rs|pe|al|ma|pa)\b|^BR-|maca[eé]|rio de janeiro|niter[oó]i|vit[oó]ria|santos|remoto|itagua[ií]|aracaju|salvador|mossor[oó]|s[aã]o paulo|angra|campos|serra', i['local'], re.I):
                    continue
                area = classifica(i['titulo'])
                vagas.append(dict(
                    id=slug(nome + '-' + i['titulo']) + '-' + str(zlib.crc32(i["link"].encode()) % 100000),
                    titulo=i['titulo'].strip(' -|'), empresa=nome, local=arruma_local(i['local']), link=i['link'],
                    regime=regime(i['titulo'], i['local']), area=VIDEOS['nomes'].get(area, 'Outras'), tema=area,
                    banco=bool(i.get('banco')) or 'banco de talentos' in i['titulo'].lower(),
                    vistaEm=anterior.get(i['link'], hoje)))
            status.append(dict(empresa=nome, vagas=sum(1 for v in vagas if v['empresa'] == nome), ok=True))
            print(f'{nome:15} {status[-1]["vagas"]}', file=sys.stderr)
        except Exception as e:
            status.append(dict(empresa=nome, vagas=0, ok=False))
            print(f'{nome:15} ERRO {e}', file=sys.stderr)
    # se uma empresa falhar, mantém as vagas dela da semana anterior em vez de sumir com tudo
    if SAIDA.exists():
        try:
            velhas = json.loads(SAIDA.read_text())['vagas']
            falhas = {s['empresa'] for s in status if not s['ok']}
            vagas += [v for v in velhas if v['empresa'] in falhas]
        except Exception:
            pass
    vistos, unicas = set(), []
    for v in vagas:
        if v['link'] not in vistos:
            vistos.add(v['link']); unicas.append(v)
    unicas.sort(key=lambda v: (v['banco'], v['regime'] != 'Embarcado', v['empresa'], v['titulo']))
    SAIDA.write_text(json.dumps(dict(atualizado=datetime.now(timezone.utc).isoformat(timespec='seconds'),
                                     fontes=status, videos=VIDEOS['videos'], vagas=unicas), ensure_ascii=False, indent=1) + '\n')
    print(f'{len(unicas)} vagas gravadas', file=sys.stderr)
    if not any(s['ok'] for s in status):
        sys.exit(1)

if __name__ == '__main__':
    main()
