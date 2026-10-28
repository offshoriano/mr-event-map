// Agenda de eventos do site. Usada pela home (calendário) e pelo /diagnostico/.
// Para incluir ou mudar um evento, edite só aqui.
// Cada conferência tem: id, nome, datas, mês, local, tipo de acesso, link oficial, e lista de eventos paralelos (noite).
window.CONFERENCES = [

  // ── EVENTOS PASSADOS 2026 ─────────────────────────────────────────────────
  {
    id:'guyana2026feb',
    name:'Guyana Energy Conference & Supply Chain Expo',
    dates:'17 – 19', month:'Fevereiro', monthShort:'FEV', year:2026,
    location:'Georgetown — Guiana',
    access:'public', link:'https://www.guyanaenergyconference.com/',
    past:true,
    parallel:[]
  },
  {
    id:'macae2026mar',
    name:'Macaé Energy',
    dates:'17 – 19', month:'Março', monthShort:'MAR', year:2026,
    location:'Centro de Convenções Jornalista Roberto Marinho, Macaé, RJ — Brasil',
    access:'public', link:'https://macaeenergy.com.br/',
    past:true,
    parallel:[]
  },
  {
    id:'vitoria2026apr',
    name:'Vitória PetroShow',
    dates:'14 – 16', month:'Abril', monthShort:'ABR', year:2026,
    location:'Centro de Convenções de Vitória, ES — Brasil',
    access:'public', link:'https://vitoriapetroshow.com.br/',
    past:true,
    parallel:[]
  },
  {
    id:'nnlogistica2026apr',
    name:'NN Logística — Supply Chain & Energia',
    dates:'Abr · Em andamento', month:'Abril', monthShort:'ABR', year:2026,
    location:'Brasil',
    access:'public', link:'https://nnlogistica.com.br/',
    past:false,
    parallel:[]
  },

  // ── MAIO ──────────────────────────────────────────────────────────────────
  {
    id:'otc2026',
    name:'OTC — Offshore Technology Conference',
    dates:'4 – 7', month:'Maio', monthShort:'MAI', year:2026,
    location:'NRG Park, Houston, TX — EUA',
    access:'public', link:'https://2026.otcnet.org/',
    past:false,
    parallel:[
      { day:'Sábado, 2 Mai', name:'Confraria do Vinho', desc:'Reunião informal da comunidade brasileira antes da abertura oficial da OTC. Tradição que antecede a semana maior.', access:'convite' },
      { day:'Domingo, 3 Mai', name:'Churrasco dos Brasileiros', desc:'Encontro tradicional dos profissionais brasileiros no fim de semana pré-OTC. Alta concentração de executivos e decisores.', access:'convite' },
      { day:'Segunda, 4 Mai · Noite', name:'Brasil Energy Meeting', desc:'Jantar de networking exclusivo para lideranças do setor energético brasileiro presentes na OTC.', access:'convite' },
      { day:'Terça, 5 Mai · Noite', name:'M&O Networking — M&O Partners', desc:'Evento de networking exclusivo patrocinado pelos principais parceiros do setor. A MR Solutions estará presente. Alta concentração de lideranças e decisores da cadeia offshore.', access:'convite' },
      { day:'Terça, 5 Mai · Noite', name:'LR Networking Reception', desc:'Evento noturno promovido pela Lloyd\'s Register durante a semana da OTC. Networking com profissionais técnicos e comerciais do setor de óleo e gás.', access:'convite' },
      { day:'Quarta, 6 Mai · Noite', name:'Festa ABS', desc:'Evento social promovido pela American Bureau of Shipping. Presença de lideranças técnicas e comerciais.', access:'restrito' },
      { day:'Quarta, 6 Mai · Noite', name:'Workshop DELP', desc:'Sessão técnica e networking. Foco em desenvolvimento e liderança no setor energético.', access:'restrito' },
      { day:'Quarta, 6 Mai · Noite', name:'Festa Camorim', desc:'Confraternização tradicional da comunidade brasileira na OTC. Um dos eventos mais aguardados de toda a semana.', access:'convite' },
    ]
  },
  {
    id:'fpso2026',
    name:'FPSO Expo Brasil — Epicentro Global (4ª Edição)',
    dates:'19 – 21', month:'Maio', monthShort:'MAI', year:2026,
    location:'ExpoRio Cidade Nova, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://fpsosexpor.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'bahia2026',
    name:'Bahia Oil & Gas Energy (BOGE)',
    dates:'27 – 29', month:'Maio', monthShort:'MAI', year:2026,
    location:'Centro de Convenções Salvador, BA — Brasil',
    access:'public', link:'https://www.bahiaoilgasenergy.com.br/',
    past:false,
    parallel:[]
  },

  // ── JUNHO ─────────────────────────────────────────────────────────────────
  {
    id:'seogs2026',
    name:'SEOGS — Suriname Energy, Oil & Gas Summit & Exhibition',
    dates:'23 – 26', month:'Junho', monthShort:'JUN', year:2026,
    location:'Paramaribo — Suriname',
    access:'public', link:'https://suriname-energy.com/',
    past:false,
    parallel:[]
  },
  {
    id:'sergipe2026',
    name:'SOG.e — Sergipe Oil & Gas & Energy',
    dates:'29 – 31', month:'Julho', monthShort:'JUL', year:2026,
    location:'Centro de Convenções AM Malls, Aracaju, SE — Brasil',
    access:'public', link:'https://sergipeoilgas.com.br/',
    past:false,
    parallel:[
      { day:'28 Jul · Noite', name:'Welcome Drink SOG26', desc:'Recepção de boas-vindas destinada exclusivamente a patrocinadores e parceiros do evento.', access:'convite' },
      { day:'30 Jul · Noite', name:'Celebra SOG26', desc:'Confraternização com atrações musicais e serviço all inclusive. Um dos destaques sociais do evento.', access:'convite' },
    ]
  },

  // ── AGOSTO ────────────────────────────────────────────────────────────────
  {
    id:'navalshore2026',
    name:'Navalshore — Feira da Indústria Marítima (20ª Ed.)',
    dates:'18 – 20', month:'Agosto', monthShort:'AGO', year:2026,
    location:'Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://www.navalshore.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'ons2026',
    name:'ONS — Offshore Northern Seas',
    dates:'24 – 27', month:'Agosto', monthShort:'AGO', year:2026,
    location:'Stavanger — Noruega',
    access:'public', link:'https://www.ons.no/',
    past:false,
    parallel:[]
  },

  // ── SETEMBRO ──────────────────────────────────────────────────────────────
  {
    id:'roge2026',
    name:'ROG.e (IBP)',
    dates:'21 – 24', month:'Setembro', monthShort:'SET', year:2026,
    location:'Riocentro, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://roge.energy/',
    past:false,
    parallel:[]
  },
  {
    id:'smm2026',
    name:'SMM — Shipbuilding, Machinery & Marine Technology',
    dates:'1 – 4', month:'Setembro', monthShort:'SET', year:2026,
    location:'Hamburgo — Alemanha',
    access:'public', link:'https://www.smm-hamburg.com/',
    past:false,
    parallel:[]
  },

  // ── NOVEMBRO ──────────────────────────────────────────────────────────────
  {
    id:'adipec2026',
    name:'ADIPEC — Abu Dhabi International Petroleum Exhibition',
    dates:'2 – 5', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Abu Dhabi — Emirados Árabes Unidos',
    access:'public', link:'https://www.adipec.com/',
    past:false,
    parallel:[
      { day:'Segunda, 2 Nov · 19h', dateIso:'2026-11-02', name:'The ADIPEC Backyard BBQ', desc:'Churrasco informal da comunidade norueguesa depois do primeiro dia de feira, com Norwegian Business Council, Innovation Norway, Nor-Shipping e MDE. Stock Burger, Holiday Inn Abu Dhabi, a partir das 18h30.', access:'aberto', link:'https://www.norwegianbusinesscouncil.com/event-details/the-adipec-backyard-bbq-2026' },
      { day:'Quarta, 4 Nov · 18h', dateIso:'2026-11-04', name:'ABS Reception at ADIPEC', desc:'Recepção da ABS, a classificadora americana, com Matthew Tremblay (SVP Global Offshore) e Dheeraj Sharma (VP Marine & Offshore, Oriente Médio e África). Splash Garden, Aloft Hotel, ADNEC. Confirmação até 29 de outubro com Grace Paca (gpaca@eagle.org).', access:'convite' },
      { day:'Quarta, 4 Nov · 18h30', dateIso:'2026-11-04', name:'Rystad Energy Market Briefing', desc:'Briefing de mercado da Rystad Energy, uma das principais consultorias de dados de energia do mundo, seguido de networking. Ritz-Carlton Grand Mosque, Abu Dhabi, das 18h30 às 21h30.', access:'inscricao', link:'https://pages.rystadenergy.com/Rystad-Energy-Market-Briefing-ADIPEC-2026' }
    ]
  },
  {
    id:'mossoro2026',
    name:'Mossoró Oil, Gas & Energy',
    dates:'24 – 26', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Expocenter Ufersa, Mossoró, RN — Brasil',
    access:'public', link:'https://www.mossorooilgas.com.br/',
    past:false,
    parallel:[]
  },

  // ── PRÓXIMOS: FIM DE 2026 E 2027 (base: Radar Global de Feiras QLP + sites oficiais) ──
  {
    id:'brazilwindpower2026',
    name:'Brazil Windpower 2026',
    dates:'27 – 29', month:'Outubro', monthShort:'OUT', year:2026,
    location:'São Paulo, SP — Brasil',
    access:'public', link:'https://www.brazilwindpower.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'offshoreenergy2026',
    name:'Offshore Energy Exhibition & Conference',
    dates:'24 – 25', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Amsterdam — Países Baixos',
    access:'public', link:'https://www.offshore-energy.biz/offshore-energy-exhibition-conference/',
    past:false,
    parallel:[]
  },
  {
    id:'osea2026',
    name:'OSEA 2026',
    dates:'24 – 26', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Singapura',
    access:'public', link:'https://www.osea-asia.com/',
    past:false,
    parallel:[]
  },
  {
    id:'guyana2027',
    name:'Guyana Energy Conference & Supply Chain Expo',
    dates:'16 – 19', month:'Fevereiro', monthShort:'FEV', year:2027,
    location:'Guyana Marriott, Georgetown — Guiana',
    access:'public', link:'https://www.guyanaenergyconference.com/',
    past:false,
    parallel:[]
  },
  {
    id:'ibem2027',
    name:'iBEM27 — International Brazil Energy Meeting',
    dates:'24 – 26', month:'Fevereiro', monthShort:'FEV', year:2027,
    location:'Salvador Convention Center, Salvador, BA — Brasil',
    access:'public', link:'https://www.ibemenergy.com/',
    past:false,
    parallel:[]
  },
  {
    id:'otc2027',
    name:'OTC — Offshore Technology Conference',
    dates:'3 – 5', month:'Maio', monthShort:'MAI', year:2027,
    location:'NRG Park, Houston, TX — EUA',
    access:'public', link:'https://2027.otcnet.org/',
    past:false,
    parallel:[]
  },
  {
    id:'fpsoexpo2027',
    name:'FPSO Expo Brasil — Epicentro Global (5ª Edição)',
    dates:'18 – 20', month:'Maio', monthShort:'MAI', year:2027,
    location:'ExpoRio Cidade Nova, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://fpsosexpor.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'norshipping2027',
    name:'Nor-Shipping 2027',
    dates:'7 – 11', month:'Junho', monthShort:'JUN', year:2027,
    location:'Lillestrøm, Oslo — Noruega',
    access:'public', link:'https://nor-shipping.com/',
    past:false,
    parallel:[]
  },
  {
    id:'sog2027',
    name:'SOG.e — Sergipe Oil & Gas & Energy',
    dates:'28 – 30', month:'Julho', monthShort:'JUL', year:2027,
    location:'AM Malls Convention Center, Aracaju, SE — Brasil',
    access:'public', link:'https://sergipeoilgas.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'navalshore2027',
    name:'Navalshore 2027',
    dates:'Ago · Data a confirmar', month:'Agosto', monthShort:'AGO', year:2027,
    location:'Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://www.navalshore.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'offshoreeurope2027',
    name:'SPE Offshore Europe 2027',
    dates:'7 – 9', month:'Setembro', monthShort:'SET', year:2027,
    location:'Aberdeen — Reino Unido',
    access:'public', link:'https://www.offshore-europe.co.uk/',
    past:false,
    parallel:[]
  },
  {
    id:'gastech2027',
    name:'Gastech 2027',
    dates:'14 – 17', month:'Setembro', monthShort:'SET', year:2027,
    location:'Houston, TX — EUA',
    access:'public', link:'https://www.gastechevent.com/',
    past:false,
    parallel:[]
  },
  {
    id:'otcbrasil2027',
    name:'OTC Brasil 2027',
    dates:'26 – 28', month:'Outubro', monthShort:'OUT', year:2027,
    location:'Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://otcbrasil.org/',
    past:false,
    parallel:[]
  },
  {
    id:'boge2027',
    name:'Bahia Oil & Gas Energy (BOGE27)',
    dates:'Data a confirmar', month:'A confirmar', monthShort:'—', year:2027,
    location:'Salvador Convention Center, Salvador, BA — Brasil',
    access:'public', link:'https://www.bahiaoilgasenergy.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'adipec2027',
    name:'ADIPEC 2027',
    dates:'Data a confirmar', month:'A confirmar', monthShort:'—', year:2027,
    location:'ADNEC, Abu Dhabi — Emirados Árabes Unidos',
    access:'public', link:'https://www.adipec.com/',
    past:false,
    parallel:[]
  },
  {
    id:'gasnatural2027',
    name:'Seminário de Gás Natural (IBP) — 22ª Edição',
    dates:'12 – 13', month:'Maio', monthShort:'MAI', year:2027,
    location:'Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://seminariodegasnatural.ibp.org.br/',
    past:false,
    parallel:[]
  },
  {
    id:'fatc2026',
    name:'FATC, SPE Brazil Flow Assurance Technology Congress',
    dates:'4 – 6', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Hotel Hilton Copacabana, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://www.sympla.com.br/evento/fatc-spe-brazil-flow-assurance-technology-congress-2026/3329823',
    past:false,
    parallel:[]
  },
  {
    id:'subseasymposium2026',
    name:'SPE Brazil Subsea Symposium & Exhibition',
    dates:'17 – 18', month:'Novembro', monthShort:'NOV', year:2026,
    location:'Windsor Barra Hotel, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://subsea-symposium.spebrasil.org/',
    past:false,
    parallel:[]
  },
  {
    id:'workboat2026',
    name:'International WorkBoat Show',
    dates:'2 – 4', month:'Dezembro', monthShort:'DEZ', year:2026,
    location:'Morial Convention Center, Nova Orleans, LA — EUA',
    access:'public', link:'https://www.workboatshow.com/',
    past:false,
    parallel:[]
  },
  {
    id:'corridaengenheiro2026',
    name:'Corrida do Engenheiro',
    dates:'13', month:'Dezembro', monthShort:'DEZ', year:2026,
    location:'Clube Cidade do Sol, Macaé, RJ — Brasil',
    access:'public', link:'https://corridadoengenheiro.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'ceraweek2027',
    name:'CERAWeek 2027',
    dates:'8 – 12', month:'Março', monthShort:'MAR', year:2027,
    location:'Houston, TX — EUA',
    access:'public', link:'https://www.ceraweek.com/',
    past:false,
    parallel:[]
  },
  {
    id:'amazonasog2027',
    name:'Amazonas Óleo, Gás & Energia 2027',
    dates:'9 – 11', month:'Março', monthShort:'MAR', year:2027,
    location:'Manaus, AM — Brasil',
    access:'public', link:'https://amazonasoleogasenergia.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'macae2027mar',
    name:'Macaé Energy 2027',
    dates:'Mar · Data a confirmar', month:'Março', monthShort:'MAR', year:2027,
    location:'Macaé, RJ — Brasil',
    access:'public', link:'https://macaeenergy.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'brasilogsummit2027',
    name:'Brasil Oil & Gas Summit (4ª edição)',
    dates:'13 – 14', month:'Abril', monthShort:'ABR', year:2027,
    location:'Windsor Florida Hotel, Rio de Janeiro, RJ — Brasil',
    access:'public', link:'',
    past:false,
    parallel:[]
  },
  {
    id:'mwa2027',
    name:'Maritime Week Americas 2027',
    dates:'17 – 20', month:'Maio', monthShort:'MAI', year:2027,
    location:'Cidade do Panamá — Panamá',
    access:'public', link:'https://ship.energy/conference/maritime-week-americas-2027/',
    past:false,
    parallel:[]
  },
  {
    id:'seogs2027',
    name:'SEOGS — Suriname Energy, Oil & Gas Summit & Exhibition',
    dates:'22 – 25', month:'Junho', monthShort:'JUN', year:2027,
    location:'Paramaribo — Suriname',
    access:'public', link:'https://suriname-energy.com/',
    past:false,
    parallel:[]
  },
  {
    id:'opecseminar2027',
    name:'10º Seminário Internacional da OPEP',
    dates:'7 – 8', month:'Julho', monthShort:'JUL', year:2027,
    location:'Imperial Hofburg Palace, Viena — Áustria',
    access:'public', link:'https://www.opec.org/',
    past:false,
    parallel:[]
  },
  {
    id:'mecshow2027',
    name:'Mec Show + ES Oil & Gas Energy 2027',
    dates:'Ago · Data a confirmar', month:'Agosto', monthShort:'AGO', year:2027,
    location:'Carapina, Serra, ES — Brasil',
    access:'public', link:'https://www.mecshow.com.br/',
    past:false,
    parallel:[]
  },
  {
    id:'aog2027',
    name:'XV Argentina Oil & Gas Expo (AOG)',
    dates:'20 – 23', month:'Setembro', monthShort:'SET', year:2027,
    location:'Buenos Aires — Argentina',
    access:'public', link:'https://aog.com.ar/',
    past:false,
    parallel:[]
  },
  {
    id:'riopipeline2027',
    name:'Rio Pipeline 2027 (IBP)',
    dates:'14 – 16', month:'Setembro', monthShort:'SET', year:2027,
    location:'Rio de Janeiro, RJ — Brasil',
    access:'public', link:'https://www.riopipeline.com.br/',
    past:false,
    parallel:[]
  }
];
