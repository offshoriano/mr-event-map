"""Escolhe, para cada vaga, os vídeos do canal Carreira Offshore que mais têm a ver com ela.

Olha o título da vaga (e a descrição, quando existe), descobre a função e procura no catálogo
do canal os vídeos que falam daquela função. Vídeo sobre a própria empresa também entra.
O catálogo (canal.json) é uma foto do canal com título, começo da descrição e visualizações."""
import json, re, unicodedata
from pathlib import Path

CANAL = json.loads((Path(__file__).parent / 'canal.json').read_text())

# (chave, rótulo, termos no título da vaga, termos no vídeo). A ordem importa: a primeira que bater vence.
FUNCOES = [
    ('pcd', 'vagas para PcD', r'\bpcd\b|pessoas? com defici|disabilit', r'\bpcd\b|defici'),
    ('rov', 'piloto e técnico de ROV', r'\brov\b', r'\brov\b'),
    ('sonda', 'sondador e equipe de perfuração', r'sondador|torrista|plataformista|encarregado de sonda|opera[cç][aã]o de sonda|driller|derrick|roughneck|roustabout|toolpusher|perfura[cç]', r'sondador|torrista|plataformista|perfura[cç]|\bsondas?\b|drilling'),
    ('guindaste', 'guindasteiro e movimentação de carga', r'guindast|crane|sinaleiro|rigger|movimenta[cç][aã]o de carga', r'guindast|movimenta[cç][aã]o de carga|sinaleiro|rigger'),
    ('radio', 'rádio operador', r'r[aá]dio ?operad|radio operator|operador de r[aá]dio', r'r[aá]dio'),
    ('aviacao', 'aviação offshore', r'aviation|helic|aw139|aeron[aá]ut|\bpilot\b|cadet', r'helic[oó]pter|avia[cç][aã]o'),
    ('nautica', 'oficial de náutica e DP', r'oficial de n[aá]utica|\boqn\b|imediato|comandante|\bmaster\b|captain|posicionamento din[aâ]mico|\bdp\b|marinha|\bmarine\b', r'n[aá]utica|comandante|imediato|posicionamento din|\bdp\b|efomm|marinha mercante'),
    ('maquinas', 'oficial e marinheiro de máquinas', r'\bm[aá]quinas|\boqm\b|maquinista|3rd engineer|chief engineer', r'\bm[aá]quinas|maquinista|efomm|marinha mercante|aquavi'),
    ('conves', 'operador de convés e marinheiro', r'conv[eé]s|\bdeck\b|marinheir|contramestre|mo[cç]o de|homem de [aá]rea|auxiliar de plataforma|able seaman|\bab\b', r'conv[eé]s|homem de [aá]rea|marinheir|contramestre'),
    ('solda', 'soldador, caldeireiro e acesso por corda', r'solda|welder|caldeir|escalador|irata|pintor|hidrojat|montador|andaim|scaffold', r'solda|caldeir|irata|escalad|acesso por corda|pintor|andaime'),
    ('instrumentacao', 'instrumentação e automação', r'instrument|automa[cç]|e&i\b|\bcontrols?\b|ot network|ot systems', r'instrument|automa[cç]'),
    ('eletrica', 'eletricista', r'eletricist|el[eé]tric|electric|eletroeletr|eletrot[eé]cn', r'eletric|el[eé]tric'),
    ('mecanica', 'mecânico e manutenção', r'mec[aâ]nic|mechanic|ajustador|fitter|torneiro|manuten[cç]|maintenance|hidr[aá]ulic|tooling|workshop|service technician', r'mec[aâ]nic|manuten[cç]|hidr[aá]ulic'),
    ('seguranca', 'técnico de segurança', r'seguran[cç]a|\bhse\b|\bsms\b|hsse|safety|bombeiro|brigad|resposta a emerg', r't[eé]cnico de seguran|seguran[cç]a do trabalho|\bhse\b|\bsms\b|bombeiro|resposta a emerg'),
    ('saude', 'enfermagem offshore', r'enferm|nurse|m[eé]dic[oa]\b|medicina|socorr|sa[uú]de', r'enferm|sa[uú]de'),
    ('hotelaria', 'hotelaria e taifa', r'taifeir|cozinh|\bcook\b|camareir|hotelaria|saloneir|padeir|catering', r'taifeir|saloneir|cozinh|hotelaria|camareir'),
    ('producao', 'operador de produção', r'operador(a)? de produ|production operator|operador(a)? de processo|sala de controle|control room|field operator|operador(a)? de utilidades|bombeador', r'operador(a)? de produ|sala de controle|fpso'),
    ('almoxarife', 'almoxarife e logística', r'almoxarif|storekeeper|materials? handler|log[ií]stic|suprimento|supply|inventory|estoque|compras|procurement|buyer', r'almoxarif|log[ií]stic|suprimento'),
    ('inspecao', 'inspeção e qualidade', r'inspetor|inspector|inspe[cç]|qualidade|quality|\bend\b|\bndt\b', r'inspe[cç]|inspetor|qualidade|ensaios? n[aã]o destrut'),
    ('mergulho', 'mergulho', r'mergulh|\bdiver\b|diving', r'mergulh'),
    ('trainee', 'estágio, trainee e primeiro emprego', r'est[aá]gi|\bintern\b|trainee|aprendiz|apprentice|aspire', r'trainee|est[aá]gi|aprendiz|sem experi[eê]ncia'),
    ('engenharia', 'engenharia', r'engenheir|engineer|engenharia', r'engenheir'),
]
OFFSHORE = re.compile(r'offshore|embarc|plataforma|fpso|navio|subsea|sonda', re.I)

def _sem_acento(s):
    return unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()

def _buscar(regex, so_titulo=False):
    """Vídeos do canal que falam do assunto, do mais visto para o menos."""
    r = re.compile(regex, re.I)
    achados = []
    for v in CANAL:
        if r.search(v['t']):
            achados.append((2, v))
        elif not so_titulo and len(r.findall(v.get('d', ''))) >= 3:
            # Descrição só conta quando insiste no assunto; uma ou duas menções costumam ser lista de temas da live
            achados.append((1, v))
    achados.sort(key=lambda x: (-x[0], x[1]['tipo'] == 'shorts', -x[1]['views']))
    return [v for _, v in achados]

GENERICOS = {
    'curriculo': ('sMTU-gjxcLU', 'Ajuste o currículo para esta vaga antes de clicar em candidatar.'),
    'entrevista': ('eHqY18dRP8Q', 'As perguntas que mais aparecem na entrevista e como responder.'),
    'embarque': ('iKkxsnu9rZ8', 'O caminho mais curto para quem ainda não embarcou.'),
    'linkedin': (None, 'Como deixar o LinkedIn pronto para o recrutador que vai olhar seu perfil.'),
}
_li = _buscar(r'linkedin', so_titulo=True)
GENERICOS['linkedin'] = (_li[0]['id'] if _li else 'PWWJ4NeFftc', GENERICOS['linkedin'][1])

# Nome da empresa como aparece nos títulos do canal
APELIDOS = {'TechnipFMC': r'technip', 'Altera&Ocyan': r'altera|ocyan', 'SBM Offshore': r'\bsbm\b', 'Wilson Sons Offshore': r'wilson sons',
            'Baker Hughes': r'baker', 'Repsol Sinopec': r'repsol', 'Brava Energia': r'\bbrava\b'}

def escolher(titulo, empresa, descricao=''):
    """Até 3 vídeos [id, motivo] e a chave da função encontrada."""
    alvo = _sem_acento(titulo)
    funcao = next((f for f in FUNCOES if re.search(f[2], titulo, re.I) or re.search(f[2], alvo, re.I)), None)
    if not funcao and descricao:
        funcao = next((f for f in FUNCOES if len(re.findall(f[2], descricao, re.I)) >= 2), None)
    escolhidos = []
    def add(vid, motivo):
        if vid and vid not in [e[0] for e in escolhidos] and len(escolhidos) < 3:
            escolhidos.append([vid, motivo])
    if funcao:
        for v in _buscar(funcao[3])[:2]:
            add(v['id'], f'Fala de {funcao[1]}, a função desta vaga.')
    padrao_emp = APELIDOS.get(empresa, re.escape(empresa.split()[0]))
    for v in _buscar(padrao_emp, so_titulo=True)[:1]:
        add(v['id'], f'Mostra a {empresa} por dentro. Ajuda a chegar preparado na entrevista.')
    if not funcao and not OFFSHORE.search(titulo):
        add(*GENERICOS['linkedin'])
    add(*GENERICOS['curriculo'])
    add(*GENERICOS['entrevista'])
    add(*GENERICOS['linkedin'])
    return escolhidos, funcao[0] if funcao else None

def titulo_bonito(t):
    t = re.sub(r'(\s*#\S+)+\s*$', '', t)  # hashtags no fim saem
    t = t.replace('#', '')                 # no meio da frase, fica só a palavra
    t = re.sub(r'\s*\|\s*$', '', re.sub(r'\s+', ' ', t)).strip(' -|')
    letras = [c for c in t if c.isalpha()]
    # Título todo em caixa alta vira frase normal, mantendo siglas curtas
    if letras and sum(c.isupper() for c in letras) / len(letras) > 0.7:
        siglas = {'ROV', 'CBSP', 'HUET', 'NR', 'FPSO', 'PLSV', 'DP', 'PCD', 'RH', 'TI', 'SBM', 'DOF', 'MODEC', 'PRIO', 'IRATA', 'CV', 'EUA', 'RJ', 'ES', 'OTC', 'ANP', 'LIVE'}
        palavras = []
        for i, w in enumerate(t.lower().split()):
            limpa = re.sub(r'\W', '', w).upper()
            palavras.append(w.upper() if limpa in siglas or re.fullmatch(r'#?\d+', limpa) else (w.capitalize() if i == 0 else w))
        t = ' '.join(palavras)
    return t

def catalogo(ids):
    por_id = {v['id']: titulo_bonito(v['t']) for v in CANAL}
    return {i: por_id.get(i, '') for i in sorted(ids)}
