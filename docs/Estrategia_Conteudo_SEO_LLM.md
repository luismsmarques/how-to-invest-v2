# Estratégia de Conteúdo — SEO + LLMs (HowToInvest)

Objetivo de negócio: **crescer por SEO/conteúdo**. Este documento define a
arquitetura de conteúdo, a cobertura de pesquisa, a otimização para motores
generativos (GEO/LLMs), a ligação interna, o roadmap de produção e a medição.
É bilingue (EN default + PT) e respeita os **invariantes do projeto** (só classes
de ativos, sem instrumentos/empresas nomeados, linguagem condicional, disclaimer).

---

## 1. Princípios (guardrails de conteúdo)

Todo o conteúdo, para SEO e para citação por IA, obedece a:
- **Só classes de ativos** (global_equity, bonds, cash, reits_alt, crypto) no conteúdo educativo e no output do motor — nunca tickers, fundos ou empresas. **Exceção controlada:** a secção editorial de corretoras (comparador, reviews, guias de abertura de conta) nomeia corretoras reguladas em conteúdo comparativo factual, sempre com rótulo "Parceria · Publicidade", disclosure de afiliação na página, links só via `/go/{slug}` com `rel="sponsored nofollow"` e aviso de risco CFD quando aplicável (ver skill `broker-affiliate`).
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
| **Pilar** — curso "Do zero à primeira carteira" | `learn` (7 módulos / 24 capítulos `.md` + 5 seeded-legacy) | Autoridade tópica, guias passo-a-passo | "como investir", "o que é X", how-to |
| **Definições** — glossário | `glossary` (54 termos `.md`) | Captura "o que é/significa X", `DefinedTerm` | informacional, long-tail |
| **Frescura** — notícias | `news` (+ RSS AI Feed) | Top Stories, recência, trending | navegacional/news |
| **Money** — corretoras | páginas + CPT `broker` (6 pillar/categoria, 10 reviews, 10 guias) | Comparativa/transacional, "Parceria · Publicidade" | "melhores corretoras", "análise X", "abrir conta" |
| **Satélite** — forex Índia | páginas `/forex/` (hub + 7 ferramentas, EN-only) | Landing de campanhas, calculadoras INR/IST | transacional-educativa, mercado Índia |
| **Editorial PT** — depósitos | comparador `[hti_depositos]` | Comparativa factual PT | "melhores depósitos a prazo" |

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

Regras em vigor (enforced pelo `tests/test-seo-structure.php` na CI):
- **Todo o capítulo Learn** tem `seo_title_en/pt` curado (≤60 chars) e **≥3
  links internos contextuais** na secção EN (tokens `[learn:]`/`[glossary:]`/
  `[page:]`); descrições ≤160.
- **Todo o termo de glossário** liga ao seu capítulo-pilar via `[learn:]` — nas
  duas línguas (a secção PT fecha com a frase-pilar antes dos Pontos-chave).
- **Zero tokens pendentes** (alvo tem de existir nos `.md`).

Matriz de linkagem por cluster:
- **Learn ↔ Learn:** tokens inline na prosa + prev/next derivado do currículo.
- **Learn ↔ glossário:** tokens inline + rail "Termos relacionados" (frontmatter
  `glossary`).
- **Educativo → money:** capítulos com intenção prática
  (`understanding-account-types`, `costs-and-fees-explained`, `your-next-steps`)
  linkam a comparação de corretoras via `[page:…]` — sempre a página de
  comparação, nunca CTA de broker (regra `seo-content`/`broker-affiliate`).
- **Money → educativo:** reviews ("Keep reading/Continuar a ler") linkam
  `custos-e-taxas-explicados`; guias de abertura linkam
  `perceber-os-tipos-de-conta`; tudo gerado pelo broker seeder (upsert →
  propaga em cada deploy).
- **Forex → educativo (EN):** position-size → risco/retorno; leverage → custos.
  A entrada para `/forex/` a partir do site principal é manual (página Tools).
- **Tokens PT:** o conversor resolve `[glossary:]`/`[learn:]` na secção PT para
  o permalink PT — os corpos PT têm links inline próprios, não só rails.

---

## 6. Roadmap de produção (priorizado)

1. ✅ **P0 — Expandir o glossário (feito):** 54 termos `.md` completos (definição
   como lead, H2-pergunta, exemplo ao nível de classe, tokens e ligação ao
   capítulo-pilar nas duas línguas), via pipeline `content/glossary/*.md` +
   importador idempotente.
2. ✅ **P0 — Ligação inter-cluster (feito):** todos os termos de glossário ligam ao
   capítulo-pilar do seu cluster via token `[learn:slug|…]` (na secção EN, a que o
   conversor resolve). Para dar casa aos termos avançados/macro sem pilar natural,
   criaram-se dois capítulos Learn: `how-markets-work` (mercados, bolsas, IPO,
   underwriting, Wall Street, NASDAQ) e `central-banks-and-monetary-policy`
   (bancos centrais, taxas, QE). Sem tokens pendentes.
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
H2 em pergunta + TL;DR, rail de termos relacionados, byline/datas (E-E-A-T),
quizzes em todos os capítulos, badges/progresso; glossário expandido (54 termos
`.md`, seo_title/desc EN+PT); meta titles curados nos 24 capítulos Learn;
malha de linkagem completa nas duas línguas (Learn↔Learn, glossário↔pilar EN+PT,
educativo↔corretoras nos dois sentidos, forex→Learn) com auditoria em CI
(`test-seo-structure.php`); sync de conteúdo em cada deploy (brokers/Learn/
glossário via Content_Sync; /forex/ via o gate do hti-forex).

**A seguir:** análise de lacunas de cluster (queries de alto valor sem página) →
cadência de notícias → conteúdo comparativo/decisional.
