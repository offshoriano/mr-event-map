# Radar Offshore: dados da ANP

O dashboard em `/anp/` lê os arquivos de `anp/data/`. Eles são gerados por
`fetch.py`, que consulta os mesmos painéis públicos de Power BI que a ANP
publica no gov.br, sem login e sem dependências fora da biblioteca padrão do Python.

```
python3 scripts/anp/fetch.py                 # tudo
python3 scripts/anp/fetch.py producao pocos  # só alguns
```

| Arquivo | Painel da ANP | Conteúdo |
|---|---|---|
| `producao.json` | Boletim Mensal de Produção | série nacional desde 2010, produção por campo e por plataforma (últimos 25 meses) |
| `instalacoes.json` | Instalações Marítimas em Operação | unidades de produção com coordenadas, capacidade, lâmina d'água e vida útil |
| `pocos.json` | Poços na Fase de Produção | sondas em operação por dia, intervenções por ano, situação dos poços |
| `exploracao.json` | Fase de Exploração | blocos sob contrato, PADs em andamento, declarações de comercialidade, poços exploratórios |

Unidades: petróleo em barris/dia (convertido de m³ com 6,2898), gás em mil m³/dia.

O workflow `.github/workflows/anp-refresh.yml` roda toda segunda-feira, grava
os dados novos no `main` e no ramo que o Hostinger publica (`claude/event-map-platform-db2gk`). Também dá para rodar na mão em
Actions > "Atualizar Radar Offshore" > Run workflow.

Se a ANP renomear uma coluna ou medida, o script para com a mensagem de erro do
Power BI apontando o nome que sumiu. As chaves dos painéis ficam em `pbi.py`.
