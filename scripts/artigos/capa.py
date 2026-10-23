"""Gera a imagem de compartilhamento (1200x630) de cada artigo, a que aparece no LinkedIn e no WhatsApp.

Fundo: a foto do cabeçalho "foto" do artigo (caminho no site ou link) ou, sem ela, a capa do primeiro vídeo do artigo.
Por cima: o tema, o título e a marca. O nome do arquivo leva um código do título e da foto,
então quando o título muda sai uma imagem nova e o LinkedIn não fica com a antiga guardada."""
import hashlib, io, re, urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
FONTES = Path(__file__).parent / 'fontes'
PASTA = RAIZ / 'assets' / 'og' / 'artigos'
L, A = 1200, 630

def baixar(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    return urllib.request.urlopen(req, timeout=20).read()

def fundo(a, Image):
    foto = a.get('foto')
    if foto:
        dados = baixar(foto) if foto.startswith('http') else (RAIZ / foto.lstrip('/')).read_bytes()
        return Image.open(io.BytesIO(dados)).convert('RGB')
    m = re.search(r'\{\{video:([\w-]+)', a['corpo'])
    if m:
        for q in ('maxresdefault', 'hqdefault'):
            try:
                im = Image.open(io.BytesIO(baixar(f'https://i.ytimg.com/vi/{m.group(1)}/{q}.jpg'))).convert('RGB')
                if im.width >= 400:
                    return im
            except Exception:
                pass
    return Image.new('RGB', (L, A), (13, 17, 23))

def cobrir(im, Image):
    # Preenche 1200x630 sem distorcer, cortando o que sobra
    esc = max(L / im.width, A / im.height)
    im = im.resize((round(im.width * esc), round(im.height * esc)), Image.LANCZOS)
    x, y = (im.width - L) // 2, (im.height - A) // 2
    return im.crop((x, y, x + L, y + A))

def quebrar(draw, texto, fonte, largura):
    linhas, atual = [], ''
    for p in texto.split():
        teste = (atual + ' ' + p).strip()
        if draw.textlength(teste, font=fonte) <= largura:
            atual = teste
        else:
            linhas.append(atual); atual = p
    return linhas + [atual]

def gerar(a):
    """Devolve o caminho público da imagem (/assets/og/artigos/...) ou None se não deu para gerar."""
    try:
        from PIL import Image, ImageDraw, ImageFont
    except ImportError:
        return None
    codigo = hashlib.sha1(f"{a['titulo']}|{a.get('tema')}|{a.get('foto')}|{a['corpo'][:400]}".encode()).hexdigest()[:8]
    nome = f"{a['slug']}-{codigo}.jpg"
    destino = PASTA / nome
    if destino.exists():
        return f'/assets/og/artigos/{nome}'
    try:
        im = cobrir(fundo(a, Image), Image)
    except Exception:
        return None
    # Escurece da esquerda para a direita e de baixo para cima, para o texto ler bem sobre qualquer foto
    sombra = Image.new('L', (L, A))
    px = sombra.load()
    for x in range(L):
        for y in range(0, A, 2):
            # Esquerda quase fechada (onde fica o texto, e onde a capa do vídeo tem letreiro), abrindo até 88% da largura
            t = min(1.0, max(0.0, (x - L * 0.68) / (L * 0.2)))
            v = int(250 * (1 - t) ** 1.6 + 60 * (y / A) * (1 - t * 0.5))
            px[x, y] = min(252, v)
            if y + 1 < A:
                px[x, y + 1] = min(252, v)
    im = Image.composite(Image.new('RGB', (L, A), (9, 12, 16)), im, sombra)
    d = ImageDraw.Draw(im)
    ouro, branco, cinza = (232, 184, 109), (238, 233, 226), (160, 178, 196)
    margem, larg = 70, 650

    logo = Image.open(RAIZ / 'assets' / 'mr-solutions-branco.png').convert('RGBA')
    logo = logo.resize((round(logo.width * 52 / logo.height), 52), Image.LANCZOS)
    im.paste(logo, (margem, 56), logo)

    for tam in (66, 60, 54, 48, 44):
        f_tit = ImageFont.truetype(str(FONTES / 'Inter-ExtraBold.otf'), tam)
        linhas = quebrar(d, a['titulo'], f_tit, larg)
        if len(linhas) <= 4:
            break
    alt_linha = round(tam * 1.14)
    f_tema = ImageFont.truetype(str(FONTES / 'Inter-SemiBold.otf'), 22)
    f_rod = ImageFont.truetype(str(FONTES / 'Inter-SemiBold.otf'), 24)
    y = A - 110 - alt_linha * len(linhas)
    tema = (a.get('tema') or 'Carreira offshore').upper()
    d.rectangle((margem, y - 52, margem + 48, y - 48), fill=ouro)
    d.text((margem + 62, y - 63), ' '.join(tema), font=f_tema, fill=ouro)
    for i, linha in enumerate(linhas):
        d.text((margem, y + i * alt_linha), linha, font=f_tit, fill=branco)
    d.text((margem, A - 72), 'Matheus Rangel  ·  mrgroup.com.br/artigos', font=f_rod, fill=cinza)

    PASTA.mkdir(parents=True, exist_ok=True)
    for velho in PASTA.glob(f"{a['slug']}-*.jpg"):
        if re.fullmatch(re.escape(a['slug']) + r'-[0-9a-f]{8}\.jpg', velho.name):
            velho.unlink()
    im.save(destino, 'JPEG', quality=86, optimize=True, progressive=True)
    return f'/assets/og/artigos/{nome}'
