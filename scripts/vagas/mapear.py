#!/usr/bin/env python3
"""Descobre onde cada empresa publica vagas. Roda à mão quando entram empresas novas no cadastro.

Para cada empresa do cadastro (empresas.json) sem sistema de vagas conhecido, tenta:
  1. Gupy com variações do nome (prio.gupy.io, bravaenergia.gupy.io...)
  2. InHire e Sólides com as mesmas variações
  3. O site da empresa: procura a página de carreiras e, nela, links de sistemas conhecidos
Grava o que achou de volta no cadastro, com a data da tentativa.

Uso: python3 scripts/vagas/mapear.py [--todas]"""
import concurrent.futures as cf, json, re, sys, unicodedata, urllib.parse, urllib.request
from datetime import date
from pathlib import Path

CADASTRO = Path(__file__).parent / 'empresas.json'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'
RUIDO = r'\b(ltda|s\.?a\.?|sa|do brasil|brasil|brazil|group|grupo|holding|energia|energy|offshore|services?|servi[cç]os|engenharia|do|da|de|e|and|&|the|international|inc|as|asa|plc|ltd|limited|corp|corporation|company|co)\b'

def get(url, data=None, headers=None, timeout=20):
    h = {'User-Agent': UA, 'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8'}
    h.update(headers or {})
    with urllib.request.urlopen(urllib.request.Request(url, data=data, headers=h), timeout=timeout) as r:
        return r.read().decode('utf-8', 'ignore'), r.geturl()

def slugs(nome, site=''):
    base = unicodedata.normalize('NFKD', nome).encode('ascii', 'ignore').decode().lower()
    base = re.sub(r'\(.*?\)', ' ', base)
    curto = re.sub(r'\s+', ' ', re.sub(RUIDO, ' ', re.sub(r'[^a-z0-9& ]', ' ', base))).strip()
    cand = [re.sub(r'[^a-z0-9]', '', base), re.sub(r'[^a-z0-9]', '', curto), re.sub(r'[^a-z0-9]+', '-', curto).strip('-')]
    if curto:
        cand.append(curto.split()[0])
    if site:
        host = urllib.parse.urlparse(site if '//' in site else '//' + site).hostname or ''
        partes = [p for p in host.split('.') if p not in ('www', 'com', 'br', 'net', 'org', 'co', 'io')]
        if partes:
            cand.append(partes[0])
    vistos = []
    for c in cand:
        if c and len(c) > 2 and c not in vistos:
            vistos.append(c)
    return vistos[:5]

def tenta_gupy(s):
    try:
        html, _ = get(f'https://{s}.gupy.io/')
        m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', html, re.S)
        pp = json.loads(m.group(1))['props']['pageProps']
        if pp.get('jobs') is not None:
            return {'tipo': 'gupy', 'cfg': s, 'vagas': len(pp['jobs']), 'nome_no_sistema': (pp.get('careerPage') or {}).get('name', '')}
    except Exception:
        pass

def tenta_inhire(s):
    try:
        cfg, _ = get(f'https://api.inhire.app/tenants/public/config/resolve/{s}')
        nome = json.loads(cfg).get('tenant', {}).get('name')
        if not nome:
            return None
        d, _ = get('https://api.inhire.app/job-posts/public/pages/lean', headers={'X-Tenant': s})
        return {'tipo': 'inhire', 'cfg': s, 'vagas': len(json.loads(d)), 'nome_no_sistema': nome}
    except Exception:
        pass

def tenta_solides(s):
    try:
        d, _ = get(f'https://apigw.solides.com.br/jobs/v3/companies/{s}/vacancies?take=50&page=1')
        d = json.loads(d)
        if d.get('count'):
            nome = (d.get('data') or [{}])[0].get('companyName', '')
            return {'tipo': 'solides', 'cfg': s, 'vagas': d['count'], 'nome_no_sistema': nome}
    except Exception:
        pass

MARCAS = [
    ('workday', r'([a-z0-9-]+)\.wd(\d+)\.myworkdayjobs\.com/(?:[a-z]{2}-[A-Z]{2}/)?([A-Za-z0-9_-]+)'),
    ('gupy', r'([a-z0-9-]+)\.gupy\.io'),
    ('inhire', r'([a-z0-9-]+)\.inhire\.(?:app|com\.br)'),
    ('solides', r'([a-z0-9-]+)\.vagas\.solides\.com\.br'),
    ('oracle', r'([a-z0-9.-]+\.oraclecloud\.com)/hcmUI/CandidateExperience/[a-z]{2}/sites/([A-Za-z0-9_]+)'),
    ('successfactors', r'(career\d*\.successfactors\.(?:com|eu))/career\?company=([A-Za-z0-9]+)'),
    ('pandape', r'([a-z0-9-]+)\.pandape\.infojobs\.com\.br'),
    ('workable', r'apply\.workable\.com/([a-z0-9-]+)'),
    ('smartrecruiters', r'(?:jobs|careers)\.smartrecruiters\.com/([A-Za-z0-9-]+)'),
    ('greenhouse', r'boards\.greenhouse\.io/([a-z0-9-]+)'),
    ('lever', r'jobs\.lever\.co/([a-z0-9-]+)'),
    ('teamtailor', r'([a-z0-9-]+)\.teamtailor\.com'),
    ('recruitee', r'([a-z0-9-]+)\.recruitee\.com'),
    ('vagascom', r'vagas\.com\.br/(?:vagas-de-)?([a-z0-9-]+)'),
    ('kenoby', r'jobs\.kenoby\.com/([a-z0-9-]+)'),
    ('linkedin', r'linkedin\.com/company/([A-Za-z0-9_-]+)'),
]
CARREIRA = re.compile(r'href="([^"#]+)"[^>]*>([^<]{0,60}(?:carreira|trabalhe|vagas|career|jobs|join us|oportunidades|faça parte)[^<]{0,40})<', re.I)

def tenta_site(site):
    if not site:
        return None, None
    url = site if site.startswith('http') else 'https://' + site
    achados, linkedin = {}, None
    try:
        html, final = get(url)
    except Exception:
        return None, None
    paginas = [html]
    for href, _ in CARREIRA.findall(html)[:3]:
        try:
            paginas.append(get(urllib.parse.urljoin(final, href))[0])
        except Exception:
            pass
    for pag in paginas:
        for tipo, rx in MARCAS:
            m = re.search(rx, pag)
            if m and tipo not in achados:
                achados[tipo] = m.groups()
    if 'linkedin' in achados:
        linkedin = 'https://www.linkedin.com/company/' + achados.pop('linkedin')[0]
    for tipo, g in achados.items():
        if tipo == 'workday':
            return {'tipo': 'workday', 'cfg': [g[0], g[2]], 'wd': int(g[1])}, linkedin
        if tipo == 'oracle':
            return {'tipo': 'oracle', 'cfg': [g[0], g[1], None]}, linkedin
        if tipo == 'successfactors':
            return {'tipo': 'successfactors', 'cfg': [g[0], g[1]]}, linkedin
        return {'tipo': tipo, 'cfg': g[0]}, linkedin
    return None, linkedin

def parece(nome, nome_no_sistema):
    """O nome no sistema de vagas bate com a empresa? Evita pegar o "Guanabara" supermercado no lugar da Guanabara Offshore."""
    def tokens(x):
        x = unicodedata.normalize('NFKD', x or '').encode('ascii', 'ignore').decode().lower()
        return {t for t in re.sub(r'[^a-z0-9 ]', ' ', re.sub(RUIDO, ' ', x)).split() if len(t) > 2}
    a, b = tokens(nome), tokens(nome_no_sistema)
    return bool(a and b) and len(a & b) / len(a) >= 0.5

def mapear(e):
    # Achado pelo site da própria empresa é confiável e entra direto. Achado só pelo nome (Gupy, InHire,
    # Sólides) pode ser homônimo, como o supermercado Guanabara: fica como sugestão para conferir no painel.
    site_ats, linkedin = tenta_site(e.get('site', ''))
    sugerido = None
    if not site_ats:
        for s in slugs(e['nome'], e.get('site', '')):
            sugerido = tenta_gupy(s) or tenta_inhire(s) or tenta_solides(s)
            if sugerido:
                break
    e['mapeado_em'] = date.today().isoformat()
    if linkedin and not e.get('linkedin'):
        e['linkedin'] = linkedin
    descartado = (e.get('ats_descartado') or {}).get('cfg')
    if site_ats and not e.get('ats') and site_ats.get('cfg') != descartado:
        e['ats'] = site_ats
        e['novo'] = True
    elif sugerido and not e.get('ats') and sugerido.get('cfg') != descartado and parece(e['nome'], sugerido.get('nome_no_sistema', '')):
        e['ats_sugerido'] = sugerido
    return e

def main():
    todas = '--todas' in sys.argv
    cad = json.loads(CADASTRO.read_text())
    alvo = [e for e in cad if (todas and not e.get('ats')) or (not e.get('ats') and not e.get('mapeado_em'))]
    print(f'mapeando {len(alvo)} de {len(cad)} empresas', file=sys.stderr)
    with cf.ThreadPoolExecutor(10) as ex:
        for e in ex.map(mapear, alvo):
            a = e.get('ats')
            print(f"{e['nome'][:40]:40} {a['tipo'] + ' ' + json.dumps(a['cfg']) if a else '-'}", file=sys.stderr)
    CADASTRO.write_text(json.dumps(cad, ensure_ascii=False, indent=1) + '\n')

if __name__ == '__main__':
    main()
