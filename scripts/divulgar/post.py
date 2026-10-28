"""Monta o post do dia para o canal do Telegram e o canal do WhatsApp.

Junta o que entrou no site hoje: vagas novas (as de quem está começando primeiro), vaga comentada,
curso com inscrição abrindo ou fechando, artigo publicado e, às segundas, os eventos da semana.
Grava vagas/post-do-dia.json. O site (api/divulgar.php) publica no Telegram e manda o texto
para o e-mail do Matheus com o botão do WhatsApp. Roda no robô diário, depois do build.py."""
import html, json, re, sys
from datetime import date, timedelta
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / 'scripts' / 'vagas'))
from paginas import RECORTES  # noqa: E402

SITE = 'https://mrgroup.com.br'
CANAL_WHATSAPP = 'https://whatsapp.com/channel/0029VbEcI5T6hENgxY9hl10L'
CANAL_TELEGRAM = 'https://t.me/mrgroupvagas'
MAX_VAGAS = 15
ENTRADA = next(r['filtro'] for r in RECORTES if r['slug'] == 'sem-experiencia')
MESES = {m: i + 1 for i, m in enumerate(['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
                                         'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'])}
DIAS = ['segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo']


def ler(caminho, padrao):
    try:
        return json.loads((RAIZ / caminho).read_text())
    except Exception:
        return padrao


def artigos_de(dia):
    """Artigos com data de hoje e já fora de rascunho."""
    achados = []
    for md in sorted((RAIZ / 'scripts' / 'artigos').glob('*.md')):
        topo = md.read_text().split('\n---', 1)[0]
        f = dict(re.findall(r'^(\w+):\s*(.*)$', topo, re.M))
        if f.get('data') == dia.isoformat() and f.get('rascunho', 'não').strip().lower() in ('não', 'nao', 'false', 'no'):
            achados.append((f.get('titulo', ''), f"{SITE}/artigos/{f.get('slug', md.stem)}/"))
    return achados


def eventos_da_semana(dia):
    """Conferências e eventos paralelos de hoje até domingo, lidos do assets/eventos.js."""
    js = (RAIZ / 'assets' / 'eventos.js').read_text()
    fim = dia + timedelta(days=6 - dia.weekday())
    itens = []
    for bloco in re.split(r'\n  \{\n', js)[1:]:
        cabeca = bloco.split('parallel:')[0]
        campo = lambda k: (re.search(rf"\b{k}:'((?:[^'\\]|\\.)*)'", cabeca) or [None, ''])[1]
        ano = re.search(r'year:(\d{4})', cabeca)
        num = re.match(r'\s*(\d{1,2})', campo('dates'))
        mes = MESES.get(campo('month').lower())
        if ano and num and mes:
            try:
                ini = date(int(ano[1]), mes, int(num[1]))
            except ValueError:
                ini = None
            if ini and dia <= ini <= fim:
                itens.append((ini, campo('name'), f"{campo('dates').replace(' – ', ' a ')} de {campo('month').lower()}, {campo('location').split(' — ')[0]}", campo('link')))
        for p in re.finditer(r"\{[^{}]*dateIso:'(\d{4}-\d\d-\d\d)'[^{}]*\}", bloco):
            d = date.fromisoformat(p[1])
            if dia <= d <= fim:
                g = lambda k: (re.search(rf"{k}:'((?:[^'\\]|\\.)*)'", p[0]) or [None, ''])[1]
                itens.append((d, g('name'), g('day'), g('link')))
    # A agenda usa travessão nos nomes; no post sai vírgula
    return [(d, n.replace(' — ', ', '), q, l) for d, n, q, l in sorted(itens)]


# Ordem do post: embarcado, porta de entrada, área técnica do setor, perto das bases (RJ, ES, RN, BA). TI e escritório no fim.
REGIAO = re.compile(r'maca[eé]|rio das ostras|rio de janeiro|niter[oó]i|a[cç]u|campos|esp[ií]rito santo|vit[oó]ria|, (RJ|ES|RN|BA)$', re.I)
ESCRITORIO = re.compile(r'devops|software|desenvolvedor|developer|dados|data |observabilidade|\bti\b|fiscal|cont[aá]bil|auditoria|jur[ií]dic|marketing|comercial|vendas|sales|sourcing|procurement|compras|recursos humanos|\brh\b', re.I)
def peso(v):
    return (4 * (v.get('regime') == 'Offshore') + 3 * bool(ENTRADA(v)) + 2 * (v.get('area') not in ('Outras', None))
            + 1 * bool(REGIAO.search(v.get('local', ''))) - 4 * bool(ESCRITORIO.search(v['titulo'])))


def montar(hoje):
    auto = ler('vagas/automaticas.json', {}).get('vagas', [])
    comentadas = [v for v in ler('vagas/vagas.json', []) if v.get('publicada') == hoje.isoformat()]
    novas = [v for v in auto if v.get('vistaEm') == hoje.isoformat()]
    novas.sort(key=lambda v: -peso(v))
    cursos = [q for q in ler('vagas/qualificacao.json', [])
              if q.get('inscricoesDe') == hoje.isoformat() or q.get('inscricoesAte') == (hoje + timedelta(days=1)).isoformat()]
    artigos = artigos_de(hoje)
    eventos = eventos_da_semana(hoje) if hoje.weekday() == 0 else []

    # Cada bloco é uma lista de (texto em negrito, linhas normais)
    blocos = []
    for v in comentadas:
        blocos.append(('Vaga comentada no site', [f"*{v['titulo']}*", f"{v['empresa']} · {v['local']}", f"{SITE}/vagas/#{v['id']}"]))
    for q in cursos:
        quando = 'Inscrições abertas a partir de hoje' if q.get('inscricoesDe') == hoje.isoformat() else 'Inscrições terminam amanhã'
        blocos.append((quando, [f"*{q['titulo']}*", f"{q.get('organizador', '')} · {q.get('local', '')}", f"{SITE}/vagas/#{q['id']}"]))
    if novas:
        linhas = []
        for v in novas[:MAX_VAGAS]:
            tipo = 'Embarcado' if v.get('regime') == 'Offshore' else 'Em terra'
            linhas += [f"*{v['titulo'].replace(' — ', ', ').replace('—', '-')}*" + (' (para quem está começando)' if ENTRADA(v) else ''),
                       f"{v['empresa']} · {v['local']} · {tipo}", f"{SITE}/vagas/#{v['id']}", '']
        resto = len(novas) - MAX_VAGAS
        linhas.append(f"E mais {resto} vagas novas em {SITE}/vagas/" if resto > 0 else f"Todas as vagas abertas em {SITE}/vagas/")
        blocos.append((f"{len(novas)} vaga{'s' if len(novas) > 1 else ''} nova{'s' if len(novas) > 1 else ''} hoje", linhas))
    for titulo, link in artigos:
        blocos.append(('Artigo novo', [titulo, link]))
    if eventos:
        linhas = []
        for d, nome, quando, link in eventos:
            linhas += [f"*{nome}*", quando or DIAS[d.weekday()].capitalize()]
            linhas += [link, ''] if link else ['']
        linhas.append(f"Agenda completa em {SITE}/#agenda")
        blocos.append(('Eventos desta semana', linhas))

    cabeca = f"MR Group · {hoje.strftime('%d/%m')}"
    rodape = ('Cada vaga no site tem vídeo do canal Carreira Offshore para você se preparar antes de se candidatar.\n\n'
              f'Conhece alguém procurando vaga? Manda o canal.\nWhatsApp: {CANAL_WHATSAPP}\nTelegram: {CANAL_TELEGRAM}')
    zap = f"*{cabeca}*\n\n" + '\n\n'.join(f"*{t.upper()}*\n" + '\n'.join(l).rstrip() for t, l in blocos) + f"\n\n{rodape}"

    def tg(linha):
        linha = html.escape(linha, quote=False)
        return re.sub(r'\*(.+?)\*', r'<b>\1</b>', linha)
    tele = f"<b>{html.escape(cabeca)}</b>\n\n" + '\n\n'.join(f"<b>{html.escape(t.upper())}</b>\n" + '\n'.join(map(tg, l)).rstrip() for t, l in blocos) + f"\n\n{html.escape(rodape)}"
    return {'data': hoje.isoformat(), 'vazio': not blocos, 'vagas': len(novas), 'whatsapp': zap if blocos else '',
            'telegram': tele if blocos else ''}


if __name__ == '__main__':
    dia = date.fromisoformat(sys.argv[1]) if len(sys.argv) > 1 else date.today()
    post = montar(dia)
    (RAIZ / 'vagas' / 'post-do-dia.json').write_text(json.dumps(post, ensure_ascii=False, indent=1) + '\n')
    print(post['whatsapp'] or 'Nada novo hoje')
