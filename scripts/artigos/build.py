#!/usr/bin/env python3
"""Gera a área de artigos (/artigos/) a partir dos textos em scripts/artigos/*.md.

Cada texto começa com um cabeçalho entre linhas "---":
    titulo, descricao, slug, data (AAAA-MM-DD), atualizado (opcional), rascunho (sim/não), tema, palavras
O corpo usa uma marcação simples:
    ## Título de seção / ### Subtítulo
    - item de lista / 1. item numerado
    **negrito**, [texto](link), > citação
    Cabeçalho opcional foto: /caminho/no/site.jpg ou link, para a imagem de compartilhamento (sem ela usa a capa do 1º vídeo)
    {{video:ID_DO_YOUTUBE|Título do vídeo}}  vídeo do canal que abre no clique
    {{vagas:regex::Texto}}                   lista as vagas abertas cujo título bate com a regex
    {{vagas:regex!!excluir::Texto}}          o mesmo, tirando os títulos que batem com a segunda regex
    [[pergunta para o Matheus]]              lacuna do rascunho, aparece destacada e nunca vai ao ar

Rascunho vai para /artigos/rascunho-<slug>/ com noindex e fica fora da lista e do sitemap.
Artigo pronto com data futura fica agendado: prévia no mesmo endereço até o dia, e publica sozinho na data.
Também regenera o sitemap.xml do site. Uso: python3 scripts/artigos/build.py"""
import html, json, re
from datetime import date
from pathlib import Path
import capa

RAIZ = Path(__file__).resolve().parents[2]
FONTE = Path(__file__).parent
SITE = 'https://mrgroup.com.br'
MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']

PAGINAS_FIXAS = [('', 'weekly', '1.0'), ('vagas/', 'daily', '0.9'), ('vagas/embarcado/', 'daily', '0.8'),
                 ('vagas/sem-experiencia/', 'daily', '0.8'), ('vagas/macae/', 'daily', '0.8'), ('vagas/rio-de-janeiro/', 'daily', '0.8'),
                 ('artigos/', 'weekly', '0.8'), ('radar/', 'weekly', '0.8'), ('guia/', 'monthly', '0.8'), ('sobre/', 'monthly', '0.6'),
                 ('vagas/divulgar/', 'monthly', '0.5'), ('diagnostico/', 'monthly', '0.7'), ('curriculo/', 'monthly', '0.7'), ('privacidade/', 'yearly', '0.2'), ('termos/', 'yearly', '0.2'), ('contato/', 'yearly', '0.4')]

def e(x):
    return html.escape(str(x or ''), quote=True)

def data_br(d):
    a, m, dia = d.split('-')
    return f'{int(dia)} de {MESES[int(m) - 1]} de {a}'

def ler(arq):
    txt = arq.read_text()
    m = re.match(r'---\n(.*?)\n---\n(.*)', txt, re.S)
    meta = {}
    for linha in m.group(1).splitlines():
        if ':' in linha:
            k, v = linha.split(':', 1)
            meta[k.strip()] = v.strip()
    meta['revisao'] = meta.get('rascunho', 'não').lower() in ('sim', 'true', '1')
    # Pronto, mas com data futura: fica como prévia e vai ao ar sozinho no dia (o robô diário roda este script)
    meta['agendado'] = not meta['revisao'] and meta.get('data', '') > date.today().isoformat()
    meta['rascunho'] = meta['revisao'] or meta['agendado']
    meta['corpo'] = m.group(2).strip()
    return meta

VAGAS = None
def vagas_abertas():
    global VAGAS
    if VAGAS is None:
        try:
            VAGAS = json.loads((RAIZ / 'vagas' / 'automaticas.json').read_text())['vagas']
        except Exception:
            VAGAS = []
    return VAGAS

def inline(t):
    t = e(t)
    t = re.sub(r'\[\[(.+?)\]\]', r'<mark class="lacuna">\1</mark>', t)
    t = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', t)
    t = re.sub(r'\*(.+?)\*', r'<em>\1</em>', t)
    t = re.sub(r'\[([^\]]+)\]\(([^)\s]+)\)', lambda m: f'<a href="{m.group(2)}"' + (' target="_blank" rel="noopener"' if m.group(2).startswith('http') else '') + f'>{m.group(1)}</a>', t)
    return t

def bloco_video(vid, titulo):
    return (f'<button class="video" data-id="{e(vid)}" aria-label="Assistir: {e(titulo)}"><span class="thumb">'
            f'<img src="https://i.ytimg.com/vi/{e(vid)}/hqdefault.jpg" alt="" loading="lazy" width="480" height="360"><i></i></span>'
            f'<span class="vt">{e(titulo)}</span><span class="vs">Vídeo do canal Carreira Offshore</span></button>')

def bloco_vagas(rx, texto):
    # "incluir!!excluir": a segunda regex tira do bloco os títulos que não servem (ex.: supervisor, sênior)
    rx, _, ex = rx.partition('!!')
    try:
        lista = [v for v in vagas_abertas() if re.search(rx, v['titulo'], re.I) and not (ex and re.search(ex, v['titulo'], re.I))]
    except re.error:
        lista = []
    # Embarcadas primeiro, que é o que o leitor dos artigos procura
    lista = sorted(lista, key=lambda v: v.get('regime') != 'Offshore')[:8]
    if not lista:
        return f'<p class="vagas-vazio">Nenhuma vaga aberta agora para esse perfil. <a href="/vagas/">Veja todas as vagas</a>.</p>'
    itens = ''.join(f'<li><a href="/vagas/#{e(v["id"])}"><b>{e(v["titulo"])}</b><span>{e(v["empresa"])} · {e(v["local"])}</span></a></li>' for v in lista)
    return f'<div class="vagas-box"><h3>{e(texto)}</h3><ul>{itens}</ul><a class="mais" href="/vagas/">Ver todas as vagas</a></div>'

def converter(corpo):
    out, lista, tipo_lista, par = [], [], None, []
    def fecha_par():
        if par:
            out.append(f'<p>{inline(" ".join(par))}</p>'); par.clear()
    def fecha_lista():
        nonlocal tipo_lista
        if lista:
            out.append(f'<{tipo_lista}>' + ''.join(f'<li>{inline(i)}</li>' for i in lista) + f'</{tipo_lista}>'); lista.clear()
        tipo_lista = None
    indice = []
    for linha in corpo.splitlines() + ['']:
        s = linha.strip()
        v = re.fullmatch(r'\{\{video:([\w-]+)\|(.+?)\}\}', s)
        g = re.fullmatch(r'\{\{vagas:(.+?)::(.+?)\}\}', s)
        if not s:
            fecha_par(); fecha_lista(); continue
        if v or g:
            fecha_par(); fecha_lista()
            out.append(bloco_video(v.group(1), v.group(2)) if v else bloco_vagas(g.group(1), g.group(2))); continue
        h = re.match(r'(#{2,3})\s+(.*)', s)
        if h:
            fecha_par(); fecha_lista()
            nivel = len(h.group(1)); ancora = re.sub(r'[^a-z0-9]+', '-', h.group(2).lower().encode('ascii', 'ignore').decode()).strip('-')
            if nivel == 2:
                indice.append((ancora, h.group(2)))
            out.append(f'<h{nivel} id="{ancora}">{inline(h.group(2))}</h{nivel}>'); continue
        if s.startswith('> '):
            fecha_par(); fecha_lista(); out.append(f'<blockquote>{inline(s[2:])}</blockquote>'); continue
        m = re.match(r'(-|\d+\.)\s+(.*)', s)
        if m:
            fecha_par()
            t = 'ol' if m.group(1)[0].isdigit() else 'ul'
            if tipo_lista and tipo_lista != t:
                fecha_lista()
            tipo_lista = t; lista.append(m.group(2)); continue
        fecha_lista(); par.append(s)
    return '\n'.join(out), indice

CSS = (FONTE / 'artigo.css').read_text() if (FONTE / 'artigo.css').exists() else ''

def cabeca(titulo, desc, url, imagem, extra='', noindex=False):
    return f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{e(titulo)}</title>
<link rel="canonical" href="{url}">
<meta name="description" content="{e(desc)}">
{'<meta name="robots" content="noindex, nofollow">' if noindex else ''}
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png"><meta name="theme-color" content="#0D1117">
<meta property="og:type" content="article"><meta property="og:site_name" content="MR Group"><meta property="og:url" content="{url}">
<meta property="og:title" content="{e(titulo)}"><meta property="og:description" content="{e(desc)}"><meta property="og:image" content="{imagem}">{'<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' if '/og/artigos/' in imagem else ''}
<meta property="og:locale" content="pt_BR"><meta name="twitter:card" content="summary_large_image">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:opsz,wght@9..40,400..800&display=swap" rel="stylesheet">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BBGCSZ37C4"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments);}}try{{window.mrCk=localStorage.getItem('mr-cookies')}}catch(e){{}}gtag('consent','default',{{analytics_storage:window.mrCk==='sim'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'}});gtag('js',new Date());</script><script src="/assets/consentimento.js" defer></script><script>gtag('config','G-BBGCSZ37C4');gtag('config','G-0NWBT6GP5Q');</script>
{extra}
<style>{CSS}</style>
</head>
<body>
<nav><a class="logo" href="/"><img src="/assets/mr-solutions-branco.png" alt="MR Group" width="34" height="28"><span>Artigos</span></a>
<div class="links"><a href="/artigos/">Artigos</a><a href="/vagas/">Vagas</a><a href="/curriculo/">Currículo</a><a href="/#calendar-main">Agenda</a><a href="/guia/">Guia</a></div></nav>
"""

RODAPE = """<footer>MR Group · Conhecimento que conecta. <a href="/sobre/">Sobre</a> · <a href="/vagas/">Vagas</a> · <a href="/artigos/">Artigos</a><p style="margin-top:10px;font-size:12px;line-height:2"><a href="https://www.instagram.com/matheus_rangel/" target="_blank" rel="noopener" style="color:inherit">Instagram</a> · <a href="https://www.linkedin.com/in/omatheusrangel/" target="_blank" rel="noopener" style="color:inherit">LinkedIn</a> · <a href="https://www.youtube.com/@carreiraoffshore" target="_blank" rel="noopener" style="color:inherit">YouTube</a> · <a href="/contato/" style="color:inherit">Fale com a gente</a> · <a href="/privacidade/" style="color:inherit">Privacidade</a> · <a href="/termos/" style="color:inherit">Termos de uso</a></p></footer>
<script>
document.addEventListener('click', ev => {
  const b = ev.target.closest('.video'); if (!b || b.classList.contains('on')) return;
  b.classList.add('on');
  b.querySelector('.thumb').innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${b.dataset.id}?autoplay=1&rel=0" title="Vídeo" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
  if (window.gtag) gtag('event', 'artigo_video_play', {video: b.dataset.id});
});
</script>
</body>
</html>
"""

def pagina_artigo(a, publicados=()):
    corpo, indice = converter(a['corpo'])
    pasta = ('rascunho-' if a['rascunho'] else '') + a['slug']
    url = f"{SITE}/artigos/{pasta}/"
    imagem = SITE + a['capa'] if a.get('capa') else f'{SITE}/assets/og/home.jpg'
    ld = {'@context': 'https://schema.org', '@type': 'BlogPosting', 'headline': a['titulo'], 'description': a['descricao'],
          'datePublished': a['data'], 'dateModified': a.get('atualizado') or a['data'], 'inLanguage': 'pt-BR',
          'mainEntityOfPage': url, 'image': imagem, 'keywords': a.get('palavras', ''),
          'author': {'@type': 'Person', 'name': 'Matheus Rangel', 'url': f'{SITE}/sobre/',
                     'jobTitle': 'Fundador da MR Group, 14 anos de experiência offshore', 'sameAs': ['https://www.youtube.com/@carreiraoffshore', 'https://www.instagram.com/matheus_rangel/', 'https://www.linkedin.com/in/omatheusrangel/']},
          'publisher': {'@type': 'Organization', 'name': 'MR Group', 'logo': {'@type': 'ImageObject', 'url': f'{SITE}/assets/icon-512.png'}}}
    extra = f'<script type="application/ld+json">{json.dumps(ld, ensure_ascii=False)}</script>'
    aviso = (f'<div class="rascunho">Agendado para {data_br(a["data"])}. Esta é a prévia: o artigo vai ao ar sozinho nesse dia.</div>' if a['agendado'] else
             '<div class="rascunho">Rascunho para revisão do Matheus. Não aparece no Google nem na lista de artigos. '
             'Os trechos destacados em amarelo são perguntas para você responder.</div>') if a['rascunho'] else ''
    sumario = ('<div class="sumario" role="navigation" aria-label="Neste artigo"><b>Neste artigo</b>' + ''.join(f'<a href="#{ancora}">{e(t)}</a>' for ancora, t in indice) + '</div>') if len(indice) > 2 else ''
    minutos = max(2, round(len(re.sub(r'\{\{.*?\}\}', '', a['corpo']).split()) / 200))
    # Continue lendo: até 3 artigos publicados, primeiro os do mesmo tema
    outros = sorted([x for x in publicados if x['slug'] != a['slug']], key=lambda x: x.get('tema') != a.get('tema'))[:3]
    continuar = ('<section class="continuar"><h2>Continue lendo</h2><div class="cards">' + ''.join(
        f'<a class="card" href="/artigos/{e(x["slug"])}/">' + (f'<img src="{e(x["capa"])}" alt="" width="1200" height="630" loading="lazy">' if x.get('capa') else '')
        + f'<span class="tema">{e(x.get("tema", "Carreira offshore"))}</span><b>{e(x["titulo"])}</b></a>' for x in outros) + '</div></section>') if outros else ''
    atualizado = f" · atualizado em {data_br(a['atualizado'])}" if a.get('atualizado') and a['atualizado'] != a['data'] else ''
    return cabeca(f"{a['titulo']} · MR Group", a['descricao'], url, imagem, extra, noindex=a['rascunho']) + f"""
<main class="artigo">
{aviso}
<a class="voltar" href="/artigos/">Artigos</a>
<h1>{e(a['titulo'])}</h1>
<p class="lead">{e(a['descricao'])}</p>
<div class="autor"><img src="/guia/img/matheus-embarcado.jpg" alt="Matheus Rangel" width="48" height="48"><div><b>Matheus Rangel</b><span>14 anos offshore · {data_br(a['data'])}{atualizado} · {minutos} min de leitura</span></div></div>
{sumario}
<article>{corpo}</article>
<aside class="cta"><b>Quer o caminho completo, na ordem certa?</b><p>O Guia 7 Passos junta cursos, currículo, LinkedIn, inglês e entrevista num plano só, do zero ao primeiro embarque.</p>
<a class="btn" href="/guia/?origem=artigo-{e(a['slug'])}">Conhecer o guia</a> <a class="btn ghost" href="/vagas/">Ver vagas abertas</a>
<p class="canal">Vagas novas, artigos e eventos todo dia no seu celular. Siga o canal da MR Group no <a href="https://whatsapp.com/channel/0029VbEcI5T6hENgxY9hl10L" target="_blank" rel="noopener">WhatsApp</a> ou no <a class="tg" href="https://t.me/+fYuxIvFkRvY3NzAx" target="_blank" rel="noopener">Telegram</a></p></aside>
{continuar}
<div class="atalhos-fim" role="navigation" aria-label="Mais no site"><a href="/vagas/sem-experiencia/">Vagas sem experiência</a><a href="/vagas/embarcado/">Vagas embarcado</a><a href="/vagas/macae/">Vagas em Macaé</a><a href="/radar/">Radar Offshore</a><a href="/#calendar-main">Agenda de eventos</a></div>
</main>
""" + RODAPE

def pagina_lista(publicados):
    itens = ''.join(f"""<a class="card" href="/artigos/{e(a['slug'])}/">{f'<img src="{e(a["capa"])}" alt="" width="1200" height="630" loading="lazy">' if a.get('capa') else ''}<span class="tema">{e(a.get('tema', 'Carreira offshore'))}</span>
<b>{e(a['titulo'])}</b><p>{e(a['descricao'])}</p><span class="data">{data_br(a.get('atualizado') or a['data'])}</span></a>""" for a in publicados)
    ld = {'@context': 'https://schema.org', '@type': 'Blog', 'name': 'Artigos MR Group', 'url': f'{SITE}/artigos/',
          'author': {'@type': 'Person', 'name': 'Matheus Rangel'}, 'inLanguage': 'pt-BR'}
    return cabeca('Artigos sobre carreira offshore e óleo e gás · MR Group',
                  'Como trabalhar embarcado, cursos, salários, funções e o mercado de óleo e gás, escrito por quem passou 14 anos offshore.',
                  f'{SITE}/artigos/', f'{SITE}/assets/og/home.jpg', f'<script type="application/ld+json">{json.dumps(ld, ensure_ascii=False)}</script>') + f"""
<main class="lista">
<div class="eyebrow">Artigos</div>
<h1>Carreira offshore<br><span>sem enrolação</span></h1>
<p class="lead">O que eu aprendi em 14 anos embarcado e nas lives do canal, escrito para você consultar quando precisar: cursos, salários, funções e o mercado.</p>
<div class="cards">{itens or '<p class="lead">Os primeiros artigos estão chegando.</p>'}</div>
</main>
""" + RODAPE

def sitemap(publicados):
    # A lista de artigos só entra no sitemap quando tiver pelo menos um publicado
    urls = [(f'{SITE}/{p}', f, pr, None) for p, f, pr in PAGINAS_FIXAS if p != 'artigos/' or publicados]
    urls += [(f"{SITE}/artigos/{a['slug']}/", 'monthly', '0.7', a.get('atualizado') or a['data']) for a in publicados]
    linhas = ''.join(f'  <url><loc>{u}</loc>' + (f'<lastmod>{m}</lastmod>' if m else '') + f'<changefreq>{f}</changefreq><priority>{p}</priority></url>\n' for u, f, p, m in urls)
    (RAIZ / 'sitemap.xml').write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{linhas}</urlset>\n')

def main():
    artigos = [ler(p) for p in sorted(FONTE.glob('*.md'))]
    for a in artigos:
        if not a['revisao'] and '[[' in a['corpo']:
            raise SystemExit(f"{a['slug']}: ainda tem pergunta para o Matheus ([[...]]); continua como rascunho até ser respondida")
    publicados = sorted([a for a in artigos if not a['rascunho']], key=lambda a: a.get('atualizado') or a['data'], reverse=True)
    for a in artigos:
        a['capa'] = capa.gerar(a)
    for a in artigos:
        destino = RAIZ / 'artigos' / (('rascunho-' if a['rascunho'] else '') + a['slug']) / 'index.html'
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(pagina_artigo(a, publicados))
        # Artigo que saiu de rascunho: apaga a cópia de revisão
        if not a['rascunho']:
            velho = RAIZ / 'artigos' / f"rascunho-{a['slug']}" / 'index.html'
            if velho.exists():
                velho.unlink(); velho.parent.rmdir()
    (RAIZ / 'artigos' / 'index.html').write_text(pagina_lista(publicados))
    sitemap(publicados)
    agendados = sorted(a['data'] for a in artigos if a['agendado'])
    print(f"{len(publicados)} publicados, {len(agendados)} agendados {agendados}, {len(artigos) - len(publicados) - len(agendados)} rascunhos")

if __name__ == '__main__':
    main()
