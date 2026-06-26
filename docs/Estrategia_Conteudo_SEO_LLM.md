# Estratégia de Conteúdo — SEO + LLMs (HowToInvest)

Objetivo de negócio: **crescer por SEO/conteúdo**. Este documento define a
arquitetura de conteúdo, a cobertura de pesquisa, a otimização para motores
generativos (GEO/LLMs), a ligação interna, o roadmap de produção e a medição.
É bilingue (EN default + PT) e respeita os **invariantes do projeto** (só classes
de ativos, sem instrumentos/empresas nomeados, linguagem condicional, disclaimer).

---

## 1. Princípios (guardrails de conteúdo)

Todo o conteúdo, para SEO e para citação por IA, obedece a:
- **Só classes de ativos** (global_equity, bonds, cash, reits_alt, crypto) — nunca tickers, fundos, corretoras ou empresas.
- **Educativo, não aconselhamento.** Linguagem condicional e ilustrativa.
- **Disclaimer associado** a qualquer exemplo de carteira.
- **Paridade EN/PT** — cada peça existe nas duas línguas, ligada por Polylang.

Estes guardrails são também um **ativo de citação**: posicionam o site como fonte
educativa neutra, o que os LLMs preferem citar.

---

## 2. Arquitetura: hub-and-spoke (clusters temáticos)

Três camadas que se reforçam:

| Camada | CPT | Papel SEO | Intenção |
|---|---|---|---|
| **Pilar** — curso "Do zero à primeira carteira" | `learn` (7 módulos / ~26 capítulos) | Autoridade tópica, guias passo-a-passo | "como investir", "o que é X", how-to |
| **Definições** — glossário | `glossary` (~42 termos) | Captura "o que é/significa X", `DefinedTerm` | informacional, long-tail |
| **Frescura** — notícias | `news` (+ RSS AI Feed) | Top Stories, recência, trending | navegacional/news |

**Conversão:** o **questionário de perfil** (5 arquétipos → carteira ilustrativa)
é o destino de intenção mais "decisional" — ligado de capítulos, glossário e CTAs.

**Regra de cluster:** cada tópico-núcleo tem (a) um capítulo Learn (pilar),
(b) os termos de glossário de apoio (spokes), (c) ligações cruzadas densas entre
eles. Ex.: cluster *Alocação* = capítulo `what-is-asset-allocation` ↔ termos
`asset`, `portfolio`, `diversification` ↔ capítulo `how-a-portfolio-is-built`.

---

## 3. Cobertura de intenção de pesquisa

- **Informacional** ("o que é X", "como funciona Y"): glossário + capítulos com
  **H2 em forma de pergunta** (já implementado) e **TL;DR de resposta direta**.
- **Comparativa/decisional** (tipos de investidor, classes de ativos): arquétipos,
  explainers por classe (`global-equities-explained`, `bonds-explained`, …).
- **Transacional educativa** (encontrar o meu perfil): questionário.
- **Long-tail:** cada termo/capítulo tem SEO title + meta description curados
  (já existentes no seeder; manter por peça).

---

## 4. GEO / LLMs (motores generativos: AI Overviews, ChatGPT, Perplexity)

O que faz o conteúdo ser **citado** por IA — estado atual e plano:

- ✅ **Respostas diretas** — TL;DR ("Em uma linha") no topo de cada peça.
- ✅ **Headings em forma de pergunta** — espelham a query do utilizador.
- ✅ **Dados estruturados** — WebSite, Organization (EducationalOrganization),
  Course, LearningResource, **Quiz/Question**, **DefinedTerm**. Factos parseáveis.
- ✅ **llms.txt** (via RankMath) — entrada curada + bloco de enquadramento
  (propósito educativo, classes de ativos, disclaimer) para os crawlers de IA.
- ✅ **Entidade de marca** — Organization + `sameAs`, `@id` consistente.
- ✅ **Crawlers de IA permitidos** (GPTBot/PerplexityBot/ClaudeBot/Google-Extended).
- ⏳ **Citabilidade ao nível da passagem** — passagens auto-contidas, definições
  claras, sempre com o enquadramento de classes de ativos + disclaimer (em curso
  com a **expansão do glossário**).
- ⏳ **Consistência de entidades** — usar tokens `[glossary:…]` para reforçar as
  relações entre conceitos (em curso).

---

## 5. Sistema de ligação interna (topical signals)

Já construído:
- **Hub → spoke:** o hub `/learn/` lista módulos/capítulos.
- **Spoke ↔ spoke:** bloco "continuar a ler" (capítulos irmãos por tópico).
- **Spoke → glossário:** rail "Termos relacionados" (capítulo→glossário, por-língua)
  + tokens `[glossary:…]` inline na prosa.
- **Glossário → glossário/deep:** pills "Related terms" + "Learn more".

A completar:
- **Glossário → Learn:** cada termo deve ligar ao capítulo-pilar do seu cluster
  (token `[learn:slug|…]`, já suportado pelo conversor).
- **Auditoria de cobertura:** garantir que nenhum termo/capítulo fica órfão (sem
  ligações de entrada nem de saída dentro do cluster).

---

## 6. Roadmap de produção (priorizado)

1. **P0 — Expandir o glossário** (em curso): de 1 linha para ~150–220 palavras por
   termo, mantendo a definição como lead, com H2-pergunta, exemplo ao nível de
   classe, tokens de glossário e ligação ao capítulo-pilar. Via **pipeline `.md`**
   (igual ao learn): `content/glossary/*.md` + importador idempotente + botão em
   Ferramentas. Piloto de 5–6 termos → validar → escalar aos ~36 restantes.
2. **P0 — Completar a ligação inter-cluster:** mapear termo↔capítulo e adicionar os
   links em falta (glossário→learn).
3. **P1 — Análise de lacunas de cluster:** identificar queries de alto valor sem
   página e criar o capítulo/termo correspondente.
4. **P1 — Cadência de notícias:** fluxo regular (frescura → Top Stories).
5. **P2 — Conteúdo comparativo/decisional:** comparações entre arquétipos e entre
   classes de ativos (sempre ilustrativo, com disclaimer).

---

## 7. Medição

- **Google Search Console:** impressões/cliques/posição **por cluster**; cobertura
  de rich results (Course/Quiz/DefinedTerm); inspeção de URL EN+PT.
- **Visibilidade em IA:** monitorizar menções/citações da marca em ChatGPT,
  Perplexity e AI Overviews para os tópicos-núcleo.
- **Core Web Vitals (CrUX/PageSpeed)** e indexação.
- **Feedback/NPS on-site** (já implementado) para sinais de experiência.

---

## 8. Estado atual (resumo)

**Feito ✓:** schema completo (entidade + Course/LearningResource/Quiz/DefinedTerm),
hreflang EN↔PT + canonical por-língua, sitemaps submetidos, llms.txt (RankMath),
H2 em pergunta + tokens de glossário (19 capítulos), rail de termos relacionados,
byline/datas (E-E-A-T), quizzes em todos os capítulos, badges/progresso.

**Em curso:** expansão do glossário (pipeline `.md` + piloto).

**A seguir:** completar ligação inter-cluster → análise de lacunas → cadência de
notícias.
