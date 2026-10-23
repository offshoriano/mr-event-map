#!/usr/bin/env python3
"""Busca vagas no Brasil direto nos sites de carreira das empresas de óleo e gás
e grava vagas/automaticas.json. Roda toda semana pelo GitHub Actions."""
import html, json, re, sys, time, urllib.parse, urllib.request, urllib.error, zlib
from datetime import date, datetime, timezone
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
SAIDA = RAIZ / 'vagas' / 'automaticas.json'
VIDEOS = json.loads((Path(__file__).parent / 'videos.json').read_text())
sys.path.insert(0, str(Path(__file__).parent))
import videos as escolha_videos  # noqa: E402
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'

# Cadastro de empresas acompanhadas (empresas.json). Para incluir uma empresa, adicione-a lá;
# scripts/vagas/mapear.py descobre sozinho o sistema de vagas de quem ainda não tem.
CADASTRO = Path(__file__).parent / 'empresas.json'

def carregar_empresas():
    """(tipo, nome, cfg, somente óleo e gás) das empresas com sistema de vagas que sabemos ler."""
    out = []
    for e in json.loads(CADASTRO.read_text()):
        ats = e.get('ats') or {}
        if ats.get('tipo') in LEITORES and not e.get('pausada'):
            cfg = tuple(ats['cfg']) if isinstance(ats['cfg'], list) else ats['cfg']
            out.append((ats['tipo'], e['nome'], cfg, bool(e.get('somente_og'))))
    return out

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
            if e.code == 429:  # pediu para esperar
                time.sleep(20 * (i + 1))
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
    # cfg = (host, site) busca "Brazil"; (host, site, None) varre todas as vagas e filtra o Brasil
    host, site, palavra = (cfg + ('"Brazil"',))[:3]
    filtro = f',keyword={urllib.parse.quote(palavra)}' if palavra else ''
    for off in range(0, 2000, 200):
        url = (f'https://{host}/hcmRestApi/resources/latest/recruitingCEJobRequisitions?onlyData=true&expand=requisitionList'
               f'&finder=findReqs;siteNumber={site},limit=200,offset={off},sortBy=POSTING_DATES_DESC{filtro}')
        lista = json.loads(get(url, headers={'Accept': 'application/json'}))['items'][0]['requisitionList']
        for j in lista:
            if j.get('PrimaryLocationCountry') != 'BR' and not re.search(r'bra[sz]il', j.get('PrimaryLocation') or '', re.I):
                continue
            yield dict(titulo=j['Title'], local=re.sub(r', Bra[sz]il$', '', j['PrimaryLocation']) + ('' if re.search(r'bra[sz]il', j['PrimaryLocation'], re.I) else ', Brasil'),
                       link=f'https://{host}/hcmUI/CandidateExperience/en/sites/{site}/job/{j["Id"]}', data=j.get('PostedDate') or '')
        if len(lista) < 200:
            break

# Código GeoNames do Brasil, usado como filtro de país nos sites da Radancy (Halliburton)
BRASIL_GEONAMES = 3469034

def radancy(host):
    vistos = set()
    for pagina in range(1, 6):
        q = urllib.parse.urlencode({
            'ActiveFacetID': BRASIL_GEONAMES, 'CurrentPage': pagina, 'RecordsPerPage': 100, 'SearchType': 6, 'OrganizationIds': 543,
            'FacetFilters[0].ID': BRASIL_GEONAMES, 'FacetFilters[0].FacetType': 2, 'FacetFilters[0].Display': 'Brazil',
            'FacetFilters[0].IsApplied': 'true', 'SearchResultsModuleName': 'Search Results', 'SearchFiltersModuleName': 'Search Filters'})
        s = json.loads(get(f'https://{host}/search-jobs/results?{q}', headers={'X-Requested-With': 'XMLHttpRequest'}))['results']
        achou = 0
        for href, tit, loc in re.findall(r'<a href="(/job/[^"]+/\d+/\d+)"[^>]*>\s*<h2>(.*?)</h2>\s*<span class="job-location">(.*?)</span>', s, re.S):
            if href in vistos:
                continue
            vistos.add(href); achou += 1
            local = limpa(re.sub('<[^>]+>', '', loc))
            partes = [x.strip() for x in local.split(',') if x.strip()]
            uf = {'Rio de Janeiro': 'RJ', 'Rio Grande do Norte': 'RN', 'Bahia': 'BA', 'Espirito Santo': 'ES', 'Espírito Santo': 'ES', 'Sergipe': 'SE', 'São Paulo': 'SP'}
            cidade = partes[0] if partes else 'Brasil'
            estado = next((uf[x] for x in partes if x in uf), '')
            titulo = re.sub(r'^(Brazil|Brasil|BR)\s*[-–]\s*((RJ|RN|BA|ES|SE|SP)\s*[-–]\s*)?([A-Za-zÀ-ú ]+?\s*[-–:]\s*)?', '', limpa(tit)).strip() or limpa(tit)
            yield dict(titulo=titulo, local=f'{cidade}, {estado}' if estado else cidade + ', Brasil', link=f'https://{host}{href}')
        if achou < 100:
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

# Consultorias de recrutamento
BRUNEL_CHAVE = 'eb0dfbddc7564004b2da4d064f2c54e2'  # chave pública do próprio site da Brunel

def brunel(_):
    for pagina in range(1, 6):
        corpo = json.dumps({'page': pagina, 'pageSize': 50, 'language': 'en', 'countryPreset': ['BRA']}).encode()
        d = json.loads(get('https://api.brunel.net/publicationsearch/api/PublicationsSearch/Get', corpo,
                           {'Content-Type': 'application/json', 'Ocp-Apim-Subscription-Key': BRUNEL_CHAVE}))
        for j in d.get('publications') or []:
            slug_t = re.sub(r'[^a-z0-9]+', '-', limpa(j['title']).lower()).strip('-')
            yield dict(titulo=limpa(j['title']), local=(j.get('city') or 'Brasil') + ', Brasil',
                       link=f"https://www.brunel.net/en/jobs/{slug_t}-{j['publicationId'].lower()}")
        if pagina * 50 >= (d.get('totalCount') or 0):
            break

def airswift(_):
    vistos = set()
    for pagina in range(1, 6):
        s = get(f'https://www.airswift.com/jobs?location=Brazil&page_num={pagina}')
        cards = re.findall(r'<article[^>]*c-card-job-item[^>]*>(.*?)</article>', s, re.S)
        for c in cards:
            m = re.search(r'c-card-job-item__title[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>', c, re.S)
            loc = re.search(r'c-card-job-item__location[^>]*>(.*?)</p>', c, re.S)
            local = limpa(re.sub('<[^>]+>', '', loc.group(1))) if loc else ''
            if not m or m.group(1) in vistos or not re.search(r'bra[sz]il', local, re.I) or re.search(r'texas|houston', local, re.I):
                continue
            vistos.add(m.group(1))
            partes = [x.strip() for x in local.split(',')]
            yield dict(titulo=limpa(re.sub('<[^>]+>', '', m.group(2))), local=partes[0] + ', Brasil', link=urllib.parse.urljoin('https://www.airswift.com', m.group(1)))
        if not cards:
            break

def atlas(_):
    # Atlas Professionals agora é Atlas NextWave; 4 é o código do Brasil no site
    d = json.loads(get('https://atlasnextwave.com/wp-json/wp/v2/job?country=4&per_page=100&_fields=id,title,link'))
    for j in d:
        yield dict(titulo=limpa(j['title']['rendered']), local='Brasil', link=j['link'])

def mde(_):
    d = json.loads(get('https://api.hubapi.com/cms/v3/hubdb/tables/5743423/rows?portalId=8479246&limit=1000'))
    hoje = date.today().isoformat()
    for r in d.get('results') or []:
        v = r.get('values') or {}
        fim = str(v.get('end_date') or '')[:10]
        if v.get('ikke_publisert') or (fim and fim < hoje) or str(v.get('country_id_recman')) != '47':
            continue
        yield dict(titulo=limpa(v.get('name') or v.get('tittel')), local=limpa(str(v.get('parea') or 'Brasil')),
                   link=f"https://www.mde-group.com/pt-br/procurando-emprego/{r.get('path')}")

def beehire(conta):
    d = json.loads(get(f'https://app.beehire.com/users/getPublicCampaigns/{conta}'))
    for c in d.get('campaigns') or []:
        loc = c.get('location') or {}
        if not re.search(r'bra[sz]il|br[eé]sil', str(loc.get('country') or loc.get('name') or ''), re.I):
            continue
        t = c.get('title')
        titulo = limpa(next(iter(t.values())) if isinstance(t, dict) and t else str(t or ''))
        local = ', '.join(x for x in (loc.get('city'), loc.get('state')) if x) or 'Brasil'
        yield dict(titulo=titulo, local=local, link=c.get('inviteLink') or f'https://app.beehire.com/{conta}')

def solides(slug):
    for pagina in range(1, 11):
        d = json.loads(get(f'https://apigw.solides.com.br/jobs/v3/companies/{slug}/vacancies?take=25&page={pagina}'))
        for v in d.get('data') or []:
            cidade, uf = (v.get('city') or {}).get('name'), (v.get('state') or {}).get('code')
            local = 'Remoto' if v.get('homeOffice') else ', '.join(x for x in (cidade, uf) if x) or 'Brasil'
            yield dict(titulo=limpa(v.get('title')), local=local, link=f'https://{slug}.vagas.solides.com.br/vaga/{v["id"]}')
        if pagina >= (d.get('totalPages') or 1):
            break

def recruitee(conta):
    d = json.loads(get(f'https://{conta}.recruitee.com/api/offers/'))
    for o in d.get('offers') or []:
        if not re.search(r'bra[sz]il', f"{o.get('country')} {o.get('location')}", re.I):
            continue
        yield dict(titulo=limpa(o.get('title')), local=limpa(o.get('city') or o.get('location') or 'Brasil'), link=o.get('careers_url') or o.get('url'))

def pandape(contas):
    # Algumas empresas têm mais de um portal na Pandapé (InfoJobs); cfg aceita "a,b"
    for conta in contas.split(','):
        vistos = set()
        for pagina in range(1, 11):
            s = get(f'https://{conta}.pandape.infojobs.com.br/?PageNumber={pagina}')
            cards = re.findall(r'<a[^>]*card-vacancy[^>]*href="(/Detail/\d+)"[^>]*>(.*?)</a>', s, re.S)
            novos = 0
            for href, c in cards:
                if href in vistos:
                    continue
                vistos.add(href); novos += 1
                t = re.search(r'<h3[^>]*title="([^"]+)"', c)
                loc = re.search(r'icon-location-pin-1.*?</div>\s*([^<]+?)\s*</div>', c, re.S)
                local = limpa(loc.group(1)) if loc else 'Brasil'
                yield dict(titulo=limpa(t.group(1)) if t else 'Vaga', local='Brasil' if local == 'Todo Brasil' else local,
                           link=f'https://{conta}.pandape.infojobs.com.br{href}')
            if not novos:
                break

def workable(conta):
    corpo = {'query': '', 'location': [], 'department': [], 'worktype': [], 'remote': []}
    for _ in range(10):
        d = json.loads(get(f'https://apply.workable.com/api/v3/accounts/{conta}/jobs', json.dumps(corpo).encode(), {'Content-Type': 'application/json'}))
        for j in d.get('results') or []:
            locs = j.get('locations') or [j.get('location') or {}]
            br = next((l for l in locs if l.get('countryCode') == 'BR'), None)
            if br:
                yield dict(titulo=limpa(j['title']), local=br.get('city') or 'Brasil', link=f"https://apply.workable.com/{conta}/j/{j['shortcode']}/")
        if not d.get('nextPage'):
            break
        corpo['token'] = d['nextPage']

def teamtailor(host):
    # Feed RSS público do Teamtailor (career.empresa.com/jobs.rss)
    s = get(f'https://{host}/jobs.rss')
    for it in re.findall(r'<item>(.*?)</item>', s, re.S):
        pais = re.search(r'<tt:country>([^<]*)', it)
        if not pais or not re.search(r'bra[sz]il', pais.group(1), re.I):
            continue
        titulo = re.search(r'<title>(.*?)</title>', it, re.S).group(1)
        link = re.search(r'<link>(.*?)</link>', it, re.S).group(1)
        cidade = re.search(r'<tt:city>([^<]*)', it)
        yield dict(titulo=limpa(titulo), local=(cidade.group(1) if cidade else 'Brasil'), link=limpa(link))

# CEP que a Halliburton usa para cada base (o bairro e o estado do cadastro deles às vezes vêm trocados)
HAL_CEP = {'27930': 'Macaé, RJ', '48110': 'Catu, BA', '59633': 'Mossoró, RN', '56600': 'Mossoró, RN', '20011': 'Rio de Janeiro, RJ', '21941': 'Rio de Janeiro, RJ'}

def halliburton(host):
    # jobs.halliburton.com é a origem das vagas; o careers.halliburton.com chega a ficar dias atrás
    for i in rmk(host):
        if not re.search(r',\s*BR\b', i['local']):
            continue  # a busca por "Brazil" também traz vaga de fora que só cita o país no texto
        cep = re.search(r'\b(\d{5})-?\d{3}\b', i['local'])
        if cep and cep.group(1) in HAL_CEP:
            i['local'] = HAL_CEP[cep.group(1)]
        else:
            i['local'] = re.sub(r',\s*BR\b.*$', '', i['local']).strip() or 'Brasil'
            if i['local'] == 'RJ':
                i['local'] = 'Rio de Janeiro, RJ'
        t = i['titulo']
        # "Brasil - RJ - Macaé: Cargo", "BR-Macaé-Cargo", "Brazil - Remote: Cargo" viram só "Cargo"
        t2 = re.sub(r'^(Brasil|Brazil|BR)\b[^:]{0,40}:\s*', '', t)
        if t2 == t:
            t2 = re.sub(r'^(Brasil|Brazil|BR)\s*-\s*(?:[A-Z]{2}\s*-\s*)?[A-Za-zÀ-ú ]+?\s*-\s*', '', t)
        i['titulo'] = t2 or t
        yield i

LEITORES = dict(halliburton=halliburton, teamtailor=teamtailor, pandape=pandape, workable=workable, solides=solides, recruitee=recruitee, brunel=brunel, airswift=airswift, atlas=atlas, mde=mde, beehire=beehire, inhire=inhire, gupy=gupy, rmk=rmk, workday=workday, oracle=oracle, radancy=radancy)

def classifica(titulo):
    t = titulo.lower()
    for chave, termos in VIDEOS['regras']:
        if re.search(termos, t):
            return chave
    return 'geral'

# Funções que só existem a bordo, mesmo quando o título não diz "offshore"
FUNCOES_A_BORDO = {'rov', 'conves', 'maquinas', 'nautica', 'radio', 'hotelaria'}
# Empresas que só têm sonda em terra: sondador delas trabalha onshore
SONDA_EM_TERRA = re.compile(r'petroreconcavo|perbras|tecnosonda|halliburton|origem|carmo|braserv|ebs\b|conterp', re.I)

def regime(titulo, local, empresa=''):
    """Offshore (embarcado), Onshore (em terra) ou Remoto."""
    t = (titulo + ' ' + local).lower()
    # Cargo de escritório continua em terra mesmo quando o título cita a operação offshore
    if re.search(r'\b(analista|assistente|auxiliar administrativo|business partner|hrbp|recursos humanos|\brh\b|financeir|cont[aá]bil|fiscal|jur[ií]dic|advogad|comercial|vendas|sales|compras|procurement|buyer|marketing|comunica[cç]|recrutamento|talent|est[aá]gi|intern\b|aprendiz)', t) and 'embarcad' not in t:
        return 'Onshore'
    if re.search(r'onshore|em terra|\bsph\b|terrestre|base de apoio|oficina|f[aá]brica', t):
        return 'Onshore'
    if re.search(r'offshore|embarc|plataforma|fpso|rig\b|vessel|navio|\brov\b|marinh|conv[eé]s|taifeir|oficial de m|escalador|embarca[cç]|plsv|psv\b|ahts', t):
        return 'Offshore'
    if 'remoto' in t or 'remote' in t:
        return 'Remoto'
    if re.search(r'roustabout|roughneck|plataformista|torrista|sondador|\bmpd\b|toolpusher|driller', t) and not SONDA_EM_TERRA.search(empresa):
        return 'Offshore'
    funcao = escolha_videos.escolher(titulo, empresa)[1]
    if funcao in FUNCOES_A_BORDO or (funcao == 'sonda' and not SONDA_EM_TERRA.search(empresa)):
        return 'Offshore'
    return 'Onshore'

def arruma_local(loc):
    loc = limpa(loc)
    if '>' in loc:  # Workday da Bristow: "Americas > Brazil > Rio de Janeiro"
        loc = loc.split('>')[-1].strip()
    m = re.match(r'BR-([A-Z]{2})-([^-]+)', loc)
    if m:
        cidade = m.group(2).title().replace(' De ', ' de ').replace(' Do ', ' do ').replace('Macae', 'Macaé').replace('Niteroi', 'Niterói')
        return f'{cidade}, {m.group(1)}'
    loc = re.sub(r'\s+-\s+([A-Z]{2})$', r', \1', loc)  # Pandapé: "Macaé - RJ"
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
    anterior, anterior_t = {}, {}
    if SAIDA.exists():
        try:
            for v in json.loads(SAIDA.read_text())['vagas']:
                anterior[v['link']] = v.get('vistaEm', hoje)
                # Se a empresa troca o link (ou o site de vagas), a vaga não volta como "nova"
                anterior_t.setdefault((v['empresa'], v['titulo']), v.get('vistaEm', hoje))
        except Exception:
            pass
    vagas, status = [], []
    for tipo, nome, cfg, filtro in carregar_empresas():
        try:
            itens = list(LEITORES[tipo](cfg))
            if filtro:
                itens = [i for i in itens if OG.search(i['titulo'] + ' ' + i.get('area', ''))]
            for i in itens:
                if not re.search(r'bra[sz]il|,\s*(rj|sp|es|ba|se|rn|ce|am|mg|pr|sc|rs|pe|al|ma|pa)\b|^BR-|maca[eé]|rio de janeiro|niter[oó]i|vit[oó]ria|santos|remoto|itagua[ií]|aracaju|salvador|mossor[oó]|s[aã]o paulo|angra|campos|serra', arruma_local(i['local']), re.I):
                    continue
                area = classifica(i['titulo'])
                vagas.append(dict(
                    id=slug(nome + '-' + i['titulo']) + '-' + str(zlib.crc32(i["link"].encode()) % 100000),
                    titulo=i['titulo'].strip(' -|'), empresa=nome, local=arruma_local(i['local']), link=i['link'],
                    regime=regime(i['titulo'], i['local'], nome), area=VIDEOS['nomes'].get(area, 'Outras'), tema=area,
                    banco=bool(i.get('banco')) or 'banco de talentos' in i['titulo'].lower(),
                    vistaEm=anterior.get(i['link']) or anterior_t.get((nome, i['titulo'].strip(' -|')), hoje),
                    videos=escolha_videos.escolher(i['titulo'], nome)[0]))
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
            for v in velhas:
                if v['empresa'] in falhas:
                    v['videos'] = escolha_videos.escolher(v['titulo'], v['empresa'])[0]
                    v['regime'] = regime(v['titulo'], v['local'], v['empresa'])
                    vagas.append(v)
        except Exception:
            pass
    vistos, unicas = set(), []
    for v in vagas:
        if v['link'] not in vistos:
            vistos.add(v['link']); unicas.append(v)
    unicas.sort(key=lambda v: (v['banco'], v['regime'] != 'Offshore', v['empresa'], v['titulo']))
    # Só regrava (e o site só republica) quando entra, sai ou muda alguma vaga
    def assinatura(lista):
        return sorted((v['link'], v['titulo'], v['local'], v.get('regime', ''), json.dumps(v.get('videos', []))) for v in lista)
    if SAIDA.exists():
        try:
            if assinatura(json.loads(SAIDA.read_text())['vagas']) == assinatura(unicas):
                print('nenhuma vaga nova ou encerrada; site não muda', file=sys.stderr)
                return
        except Exception:
            pass
    SAIDA.write_text(json.dumps(dict(atualizado=datetime.now(timezone.utc).isoformat(timespec='seconds'),
                                     fontes=status, videos=VIDEOS['videos'],
                                     catalogo=escolha_videos.catalogo({x[0] for v in unicas for x in v.get('videos', [])}), vagas=unicas), ensure_ascii=False, indent=1) + '\n')
    print(f'{len(unicas)} vagas gravadas', file=sys.stderr)
    # Páginas fixas por busca (Macaé, Rio, sem experiência, embarcado), para o Google
    try:
        import paginas
        manuais = json.loads((RAIZ / 'vagas' / 'manuais.json').read_text()) if (RAIZ / 'vagas' / 'manuais.json').exists() else []
        paginas.gerar(unicas, [m for m in manuais if not m.get('inscricoesAte') or m['inscricoesAte'] >= hoje])
    except Exception as erro:
        print(f'páginas por busca não geradas: {erro}', file=sys.stderr)
    if not any(s['ok'] for s in status):
        sys.exit(1)

if __name__ == '__main__':
    main()
