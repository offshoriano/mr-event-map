"""Gera páginas fixas de vagas para buscas específicas (Macaé, Rio de Janeiro, sem experiência, embarcado).

O Google lê melhor uma página com a lista já escrita no HTML do que uma lista montada pelo navegador.
Cada página lista as vagas do recorte e leva para /vagas/#id, onde ficam os vídeos e o pop-up de candidatura.
Roda dentro do fetch_jobs.py sempre que as vagas mudam."""
import html, json, re
from datetime import date
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
SITE = 'https://mrgroup.com.br'

RECORTES = [
    dict(slug='macae', titulo='Vagas offshore em Macaé', h1='Vagas offshore e de óleo e gás em Macaé',
         filtro=lambda v: re.search(r'maca[eé]', v['local'], re.I),
         intro='Macaé é a capital do petróleo e onde ficam as bases de apoio das operadoras e prestadoras da Bacia de Campos. '
               'Aqui estão as vagas abertas hoje na cidade, embarcadas e em terra, puxadas direto do site de cada empresa.'),
    dict(slug='rio-de-janeiro', titulo='Vagas offshore no Rio de Janeiro', h1='Vagas offshore e de óleo e gás no Rio de Janeiro',
         filtro=lambda v: re.search(r'rio de janeiro', v['local'], re.I),
         intro='As sedes das operadoras, das donas de FPSO e das grandes prestadoras de serviço ficam no Rio. '
               'Aqui estão as vagas abertas hoje na capital, para quem quer embarcar e para quem quer trabalhar em terra.'),
    dict(slug='sem-experiencia', titulo='Vagas offshore sem experiência', h1='Vagas offshore para quem ainda não tem experiência',
         filtro=lambda v: re.search(r'trainee|est[aá]gi|aprendiz|apprentice|\bintern\b|jovem|cadet|ajudante|homem de [aá]rea|sem experi|auxiliar de (plataforma|opera)', v['titulo'], re.I),
         intro='Trainee, estágio, jovem aprendiz, ajudante e homem de área: as portas de entrada do setor. '
               'Para a primeira vaga embarcada, o caminho costuma ser curso básico (CBSP e T-HUET), currículo alinhado e uma dessas funções.'),
    dict(slug='embarcado', titulo='Vagas embarcado (offshore)', h1='Vagas para trabalhar embarcado',
         filtro=lambda v: v.get('regime') == 'Offshore',
         intro='Vagas a bordo de plataformas, FPSOs, sondas e embarcações de apoio, em escala de embarque. '
               'Cada vaga tem vídeos do canal Carreira Offshore para você se preparar antes de se candidatar.'),
]

CSS = """:root{--black:#090C10;--deep:#0D1117;--card:#111820;--border:#1C2733;--border2:#243040;--gold:#C9933A;--gold-l:#E8B86D;--white:#EEE9E2;--muted:#7A8FA6;--muted2:#A0B2C4;}
*{margin:0;padding:0;box-sizing:border-box;}body{font-family:'DM Sans',system-ui,sans-serif;background:var(--deep);color:var(--white);}
a{color:var(--gold-l);}nav{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 24px;border-bottom:1px solid var(--border);background:var(--black);}
nav img{height:28px;display:block;}nav .l a{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted2);text-decoration:none;margin-left:16px;}
main{max-width:900px;margin:0 auto;padding:40px 20px 70px;}.eyebrow{font-size:11px;letter-spacing:.25em;text-transform:uppercase;color:var(--gold);}
h1{font-size:34px;line-height:1.15;margin:10px 0 14px;}.intro{color:var(--muted2);font-size:16px;line-height:1.7;max-width:720px;}
.meta{font-size:13px;color:var(--muted);margin:14px 0 24px;}.cta{display:inline-block;background:var(--gold);color:var(--black);font-weight:700;font-size:13px;letter-spacing:.14em;text-transform:uppercase;text-decoration:none;padding:13px 22px;border-radius:2px;}
ul.v{list-style:none;margin-top:10px;}ul.v li{background:var(--card);border:1px solid var(--border);border-radius:2px;margin-bottom:8px;}
ul.v a{display:block;padding:14px 16px;text-decoration:none;color:var(--white);}ul.v a:hover{border-color:var(--gold);}
ul.v b{display:block;font-size:15px;}ul.v span{font-size:13px;color:var(--muted2);}ul.v i{font-style:normal;font-size:11px;color:var(--gold-l);margin-left:6px;}
h2{font-size:20px;margin:36px 0 10px;}.outras a{display:inline-block;margin:0 8px 8px 0;font-size:14px;border:1px solid var(--border2);padding:8px 12px;border-radius:2px;text-decoration:none;color:var(--muted2);}
.guia{margin-top:30px;background:var(--card);border:1px solid rgba(201,147,58,.4);padding:20px;border-radius:2px;line-height:1.6;color:var(--muted2);}
footer{text-align:center;font-size:12px;color:var(--muted);padding:30px 20px;border-top:1px solid var(--border);}
@media(max-width:600px){h1{font-size:26px;}nav .l a{margin:0 12px 0 0;}}"""

def e(x):
    return html.escape(str(x or ''), quote=True)

def pagina(r, lista, hoje, todos):
    n = len(lista)
    titulo = f"{r['titulo']}: {n} vaga{'s' if n != 1 else ''} hoje · MR Group"
    desc = f"{n} vagas abertas hoje. {r['intro'][:110].rsplit(' ', 1)[0]}. Atualizado todo dia."
    url = f"{SITE}/vagas/{r['slug']}/"
    itens = ''.join(
        f"<li><a href=\"../#{e(v['id'])}\"><b>{e(v['titulo'])}</b><span>{e(v['empresa'])} · {e(v['local'])}"
        f"<i>{'Offshore' if v.get('regime') == 'Offshore' else 'Onshore'}</i></span></a></li>" for v in lista)
    outras = ''.join(f"<a href=\"../{o['slug']}/\">{e(o['titulo'])}</a>" for o in todos if o['slug'] != r['slug'])
    lista_ld = json.dumps({'@context': 'https://schema.org', '@type': 'ItemList', 'name': r['h1'], 'numberOfItems': n,
                           'itemListElement': [{'@type': 'ListItem', 'position': i + 1, 'name': f"{v['titulo']} · {v['empresa']}",
                                                'url': f"{SITE}/vagas/#{v['id']}"} for i, v in enumerate(lista[:100])]}, ensure_ascii=False)
    return f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{e(titulo)}</title>
<link rel="canonical" href="{url}">
<meta name="description" content="{e(desc)}">
<link rel="icon" href="/favicon.ico" sizes="any">
<meta property="og:type" content="website"><meta property="og:site_name" content="MR Group"><meta property="og:url" content="{url}">
<meta property="og:title" content="{e(titulo)}"><meta property="og:description" content="{e(desc)}">
<meta property="og:image" content="{SITE}/assets/og/vagas.jpg"><meta name="twitter:card" content="summary_large_image">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BBGCSZ37C4"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments);}}try{{window.mrCk=localStorage.getItem('mr-cookies')}}catch(e){{}}gtag('consent','default',{{analytics_storage:window.mrCk==='sim'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'}});gtag('js',new Date());</script><script src="/assets/consentimento.js" defer></script><script>gtag('config','G-BBGCSZ37C4');gtag('config','G-0NWBT6GP5Q');</script>
<script type="application/ld+json">{lista_ld}</script>
<style>{CSS}</style>
</head>
<body>
<nav><a href="../../"><img src="../../assets/mr-solutions-branco.png" alt="MR Group" width="34" height="28"></a>
<div class="l"><a href="../">Todas as vagas</a><a href="../../guia/">Guia 7 Passos</a></div></nav>
<main>
<div class="eyebrow">Atualizado em {hoje.strftime('%d/%m/%Y')}</div>
<h1>{e(r['h1'])}</h1>
<p class="intro">{e(r['intro'])}</p>
<p class="meta">{n} vaga{'s' if n != 1 else ''} abertas hoje. Clique na vaga para ver os vídeos de preparação e se candidatar no site da empresa.</p>
<a class="cta" href="../">Ver todas as vagas com filtros</a>
<ul class="v">{itens or '<li><a href="../">Nenhuma vaga neste recorte hoje. Veja todas as vagas.</a></li>'}</ul>
<div class="guia"><b style="color:var(--white)">Ainda não embarcou?</b> O Guia 7 Passos mostra o caminho na ordem certa: cursos, currículo, LinkedIn e entrevista. <a href="../../guia/">Conhecer o guia</a></div>
<h2>Outras buscas</h2><div class="outras">{outras}</div>
</main>
<footer>MR Group · Vagas puxadas direto dos sites das empresas. A candidatura é feita com a empresa, sem custo.<p style="margin-top:8px;font-size:12px"><a href="/privacidade/" style="color:inherit">Privacidade</a> · <a href="/termos/" style="color:inherit">Termos de uso</a></p></footer>
</body>
</html>
"""

def gerar(vagas, manuais=()):
    hoje = date.today()
    todas = list(manuais) + list(vagas)
    for r in RECORTES:
        lista = [v for v in todas if r['filtro'](v)]
        destino = RAIZ / 'vagas' / r['slug'] / 'index.html'
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(pagina(r, lista, hoje, RECORTES))
    return [f"{SITE}/vagas/{r['slug']}/" for r in RECORTES]

if __name__ == '__main__':
    d = json.loads((RAIZ / 'vagas' / 'automaticas.json').read_text())
    try:
        m = json.loads((RAIZ / 'vagas' / 'manuais.json').read_text())
    except Exception:
        m = []
    print(gerar(d['vagas'], [x for x in m if not x.get('inscricoesAte') or x['inscricoesAte'] >= date.today().isoformat()]))
