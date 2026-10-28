# mrgroup.com.br

Site da MR Group (MR AGENCY LTDA, CNPJ 45.091.203/0001-79, Cordeiro/RJ), empresa de Matheus Rangel: mídia, conteúdo e educação para o setor de óleo, gás e energia. Portal com agenda de eventos, vagas, Radar Offshore (dados da ANP), artigos, Guia 7 Passos, Diagnóstico de eventos e Currículo Sob Medida.

Matheus não programa. Explique em português simples, sem jargão, e diga o que ele precisa fazer clicando, nunca rodando comando.

## Como publicar (importante)

- A Hostinger publica o ramo `claude/event-map-platform-db2gk`. Ninguém mexe nele direto: `.github/workflows/deploy.yml` copia o `main` para ele a cada push no `main`, sem force.
- **Todo pull request vai para o `main`.** Nunca abra PR com base no ramo de publicação: o `main` fica para trás, a cópia automática passa a ser recusada e o robô diário para de chegar ao site (aconteceu em outubro de 2026 e foi corrigido no PR #69).
- PR de um ramo `claude/*` entra sozinho (`auto-merge.yml`, autorizado pelo Matheus). Se o workflow não disparar, pode fazer o merge pela API. Não peça para o Matheus clicar em merge.
- Depois do merge, confira no ar (curl na página ou no arquivo com `?v=` aleatório) e diga a ele que "já está no site".
- Antes de começar, traga o `main` atualizado: o robô faz commit nele todo dia.
- Mensagem de commit em inglês, com as linhas de autoria pedidas pelo ambiente. Textos do site em português.

## Robôs (GitHub Actions)

- `vagas-refresh.yml`, todo dia por volta das 10h UTC (costuma atrasar): `mapear.py`, `fetch_jobs.py` (vagas + páginas de busca via `paginas.py`) e `build.py` (artigos e sitemap). Faz commit no `main`.
- `anp-refresh.yml`, toda segunda: dados do Radar Offshore (`scripts/anp/`).
- Post do dia: o `vagas-refresh.yml` roda `scripts/divulgar/post.py` (vagas novas, vaga comentada, curso abrindo ou fechando, artigo do dia e, às segundas, eventos da semana) e grava `vagas/post-do-dia.json`. Depois chama `api/divulgar.php` (`publicar`, uma vez por dia), que posta no canal do Telegram e manda o texto do WhatsApp para o e-mail do Matheus, que copia e cola no canal (link em `CANAL_WHATSAPP`, em `divulgar.php` e `post.py`; o botão "Seguir o canal" fica em `vagas/index.html` e nos artigos via `build.py`). O WhatsApp não aceita postagem automática; não use serviço não oficial. Reserva: o primeiro clique em vaga depois das 9h também chama `publicar`.

## Onde fica cada coisa

- `index.html`: página inicial e agenda. Eventos em `assets/eventos.js` (`window.CONFERENCES`, com `parallel` para os eventos noturnos; campos `day`, `dateIso`, `name`, `desc`, `access` convite/aberto/inscricao/restrito, `link`). Ao mudar a agenda, troque o `?v=` do `<script src="/assets/eventos.js?v=...">` em `index.html` e `diagnostico/index.html`.
- `vagas/`: página de vagas. `automaticas.json` é gerado pelo robô; `manuais.json` (vagas enviadas e aprovadas no painel); `qualificacao.json` (cursos e programas com inscrição aberta). `vagas.json` são as vagas comentadas (resumo e vídeos); com `destaque: true` a vaga sobe para a seção "Em destaque" no topo. A seção "para quem está começando" usa a regex `ENTRADA` no título, repetida em `vagas/index.html`, `scripts/vagas/paginas.py` e `api/alertas.php`: mude nas três.
- `scripts/vagas/empresas.json`: cadastro das empresas acompanhadas. Leitores em `fetch_jobs.py` (`LEITORES`: gupy, rmk, workday, inhire, halliburton, motaengil...). Campos úteis: `local_padrao`, `manter_local` (empresas de vários setores), `somente_og`, `pausada`. Edite o JSON por linha, sem `json.dump` (reformata o arquivo todo).
- `scripts/artigos/*.md` → `build.py` gera `artigos/`. Front matter: titulo, descricao, slug, data, rascunho, tema, palavras. Data futura = prévia noindex em `/artigos/rascunho-<slug>/`, publicada pelo robô no dia. Atalhos: `{{video:ID|Título}}`, `{{vagas:regex!!excluir::Título}}`, `[[pergunta]]` (lacuna para o Matheus). `capa.py` gera a imagem de compartilhamento. Todo artigo leva vídeo do canal, link do Guia e vagas relacionadas.
- `diagnostico/`: diagnóstico de eventos para empresas (`perfil.js` tem o perfil pesquisado de cada evento). Gratuito na fase de validação.
- `curriculo/`: Currículo Sob Medida. Lista de espera; vira formulário de pedido quando o produto é liberado no painel. `curriculo/entrega/` é a página que o cliente recebe.
- `scripts/curriculo/regras.md`: regras do gerador de currículo (método do Matheus + padrão de 315 currículos dele). É o prompt de sistema do gerador.
- `leads/`: painel protegido por senha. Abas: Contatos, Vagas enviadas, Ranking, Empresas, Diagnósticos, Mensagens, Alertas de vagas, Currículo, Pagamentos, Telegram e WhatsApp.
- `api/` (PHP): `_base.php` (dados, limite por IP, `avisar()`, `resendEnviar()`, `EMAIL_SITE`), `_stripe.php` + `pagamento.php` + `stripe-webhook.php`, `_claude.php` + `_docs.php` + `_pedidos.php` + `curriculo.php` (gerador), `alertas.php` (alerta semanal), `divulgar.php` (post do dia), `contato.php`, `leads.php`, `diagnosticos.php`, `events.php`, `vagas-enviadas.php`, `cliques.php`.
- Dados ficam fora da pasta pública (`../mr-dados`); `data/` é o reserva e é bloqueado. `.htaccess` bloqueia `.md`, `.py`, `.yml` e afins.

## Chaves e e-mails

- Chaves (Stripe, Resend, Anthropic, bot do Telegram) são coladas pelo Matheus no painel e ficam só no servidor. Nunca no código, no GitHub ou neste arquivo. Se ele mandar uma chave no chat, avise e peça para colar no painel.
- Todo e-mail do site sai de **administracao@mrgroup.com.br** (constante `EMAIL_SITE`). É a única caixa criada na Hostinger.
- Os avisos internos vão para **matheus.rangel@mrgroup.com.br** (com "th"). É também o contato público.
- Resend: domínio mrgroup.com.br verificado. Stripe: conta MR AGENCY LTDA, ainda em modo teste. Produtos com `lookup_key` `curriculo` (R$ 97) e `diagnostico` (R$ 147); o painel cria os produtos sozinho quando recebe uma chave nova. Nada fica à venda até ser marcado na aba Pagamentos.
- Gerador de currículo: modelo `claude-opus-5-5`, saída em JSON estruturado, chamada direta por curl (a hospedagem não instala bibliotecas). Todo currículo passa por revisão humana no painel antes da entrega.

## Decisões já tomadas

- Diagnóstico: grátis até validar com empresas; depois R$ 147.
- Currículo Sob Medida: R$ 97 por vaga, entrega em até 24h úteis. Reembolso integral só antes da entrega, sem revisão grátis depois. Nunca inventar experiência, curso ou certificado. Arquivos apagados 30 dias após a entrega.
- Guia 7 Passos: R$ 97 na Hotmart, 7 dias de garantia.
- Offshore 360 (mentoria): página em espera até o Matheus mandar formato, preço e conteúdo.
- Simuladores para alunos (Reação Offshore) ficam fora do site, em conversa própria.

## Voz e texto

- Português do Brasil, natural e direto. Sem travessão (—) e sem emoji.
- Escreva sempre **T-HUET**, nunca só HUET.
- Textos institucionais na voz da empresa: "empresa nova, com 14 anos de experiência offshore". Artigos e e-mails de carreira podem ser na voz do Matheus ("eu").
- Evite: jornada, essência, incrível, fascinante, alavancar, impulsionar, fomentar, além disso, portanto, ou seja, em suma, aprofundar, promover, maximizar, estrategicamente, "é importante notar".
- Evite as estruturas "não é X, é Y", título com dois pontos e pergunta retórica seguida de resposta curta.
- Dados do Matheus para conteúdo: 14 anos embarcado (NOV, SBM Offshore, Subsea7), mais de 15 países, canal Carreira Offshore (YouTube @carreiraoffshore), Instagram @matheus_rangel, LinkedIn /in/omatheusrangel.
