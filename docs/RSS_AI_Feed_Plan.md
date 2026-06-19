# RSS AI Feed — Plano de desenvolvimento (`hti-rss-ai`)

_Plugin que alimenta a área de **Notícias** (CPT `news` do `hti-engine`) a partir de
feeds RSS: ingere rascunhos, agrupa notícias similares, e — sob comando — pesquisa
factos na web e gera um artigo original, otimizado para SEO/Google News, deixado
em **pendente de revisão** para o administrador finalizar e publicar._

## Decisões (confirmadas)
- **Motor de pesquisa+geração:** **Gemini + Google Search grounding** (reaproveita a
  chave Gemini já configurada; uma chamada, com citações).
- **Estrutura:** **plugin separado** `hti-rss-ai` que integra o CPT `news` e o
  adaptador LLM do `hti-engine` (requer `hti-engine` ativo).
- **Ordem:** **Fundação primeiro** (M0–M2: scaffold → feeds → fetcher/rascunhos),
  depois agrupamento (M3) e IA (M4).

## Invariantes (herdados do projeto)
- Educativo; **nada de conselho de investimento**; **disclaimer** em cada notícia.
- **Factual com fontes**: cada afirmação relevante tem citação; sem fonte → não afirma.
- **Original**: nunca republicar o conteúdo do RSS literalmente (direitos de autor);
  sintetizar e atribuir.
- **Humano no fim**: o plugin **nunca publica** — só cria em `pending`.
- Tudo prefixado `rssai_`/`RSSAI_`; output escapado, input sanitizado; nonce + capability.
- i18n EN (default) + PT.

---

## Arquitetura
```
hti-rss-ai/
  hti-rss-ai.php            bootstrap, ativação/desativação, versão, dependência hti-engine
  includes/
    class-activator.php     dbDelta das tabelas; agenda Action Scheduler / cron
    class-settings.php      modelo Gemini, intervalos, threshold de similaridade,
                            limites, templates de prompt, idioma
    class-feeds.php         CRUD de feeds
    class-fetcher.php       cron: fetch_feed() + parse + dedupe + imagem → rascunhos
    class-grouping.php      clustering por similaridade (M3)
    class-research.php      Gemini grounded: factos + citações (M4)
    class-generator.php     compõe artigo SEO/Google News (JSON) → news pending (M4)
    class-validator.php     valida o JSON do LLM (M4)
    class-admin.php         páginas Feeds / Rascunhos / Grupos / Revisão
    class-list-tables.php   WP_List_Table para itens e grupos
    class-logger.php        logs sem PII
  assets/css, assets/js     UI admin (vanilla)
  languages/
  tests/                    testes pure-PHP (à semelhança do hti-engine)
```

**Dependência:** no boot, verificar `post_type_exists('news')`/classe do `hti-engine`;
se ausente, mostrar admin notice e não arrancar a geração (a ingestão pode funcionar
para `post` como fallback — decidir em M4).

---

## Modelo de dados (tabelas próprias)
**`{prefix}rssai_feeds`**
| campo | tipo | notas |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR | |
| url | TEXT | URL do feed |
| default_category | BIGINT | term_id de `news_category` (sugestão) |
| lang | VARCHAR(5) | `en`/`pt` |
| status | TINYINT | ativo/inativo |
| last_fetched | DATETIME | |
| error_count | INT | backoff |
| created_at | DATETIME | |

**`{prefix}rssai_items`** (rascunhos)
| campo | tipo | notas |
|---|---|---|
| id | BIGINT PK | |
| feed_id | BIGINT | FK |
| guid_hash | CHAR(40) | **dedupe** (sha1 de guid\|link) — UNIQUE |
| title | TEXT | |
| description | TEXT | resumo/summary |
| image_url | TEXT | enclosure / media:content / og:image |
| source | VARCHAR | nome da fonte |
| link | TEXT | URL original |
| published_at | DATETIME | |
| lang | VARCHAR(5) | |
| fingerprint | TEXT | tokens normalizados (para M3) |
| group_id | BIGINT NULL | FK rssai_groups |
| status | VARCHAR | `new` / `grouped` / `used` / `ignored` |
| fetched_at | DATETIME | |

**`{prefix}rssai_groups`**
| campo | tipo | notas |
|---|---|---|
| id | BIGINT PK | |
| label | TEXT | título representativo |
| lang | VARCHAR(5) | |
| status | VARCHAR | `open` / `generated` / `dismissed` |
| score | FLOAT | coesão do cluster |
| size | INT | nº de itens |
| created_at | DATETIME | |

**Artigo gerado** = post `news` (`post_status = pending`) + meta:
`rssai_group_id`, `rssai_sources` (array de {title,url}), `rssai_model`,
`rssai_generated_at`, `rssai_disclaimer`.

---

## Pipeline
1. **Feeds** — admin adiciona (URL + categoria + idioma), botão **Testar**.
2. **Fetch** (agendado) — `fetch_feed()`; por item: extrair título/descrição/imagem/source/
   link/data; **dedupe** por `guid_hash`; gravar rascunho (`status=new`).
3. **Agrupar** (M3) — similaridade título+descrição → `group_id`, cria/atualiza grupo.
4. **Gerar** (M4) — admin escolhe grupo → "Gerar artigo".
5. **Pesquisa factual** (M4) — Gemini grounded a partir dos títulos/descrições → factos+fontes.
6. **Geração** (M4) — JSON validado → cria `news` `pending` com citações, categoria
   sugerida, tags, meta description, disclaimer.
7. **Revisão** — admin adiciona imagem destacada, categoria, sitelinking, edita, **publica**.

---

## Núcleo IA (M4) — Gemini + Google Search grounding
- **Entrada:** títulos + descrições do grupo (e idioma alvo).
- **Pesquisa:** chamada Gemini com a ferramenta **Google Search grounding**; recolhe
  factos atuais e **groundingMetadata** (fontes/URLs) para citações.
- **Saída em JSON (schema), validada** por `class-validator`:
  ```json
  {
    "headline": "…",
    "slug": "…",
    "meta_description": "… (<=155 chars)",
    "dek": "subtítulo/lead",
    "body_blocks": [ {"type":"paragraph","text":"…"}, {"type":"heading","text":"…"} ],
    "suggested_category": "…",
    "tags": ["…"],
    "sources": [ {"title":"…","url":"https://…"} ],
    "lang": "pt"
  }
  ```
- **Rejeições do validador:** sem `sources`; números/factos sem fonte; idioma errado;
  linguagem de conselho ("compra/vende/deves"); instrumentos/tickers; cópia literal do RSS.
  → não cria post; mostra erro ao admin (e log).
- **Disclaimer** acrescentado ao corpo automaticamente.
- **Custo/limites:** 1 grupo por chamada; rate limit + caching; reutiliza o
  `class-rate-limit` do hti-engine quando fizer sentido.

> Nota: o adaptador `class-llm` do `hti-engine` (WP AI Client) pode não expor o
> grounding; nesse caso `class-research` chama a API Gemini diretamente (à
> semelhança do `class-gemini`), mantendo a chave em `wp-config.php`/Definições.

---

## Agrupamento (M3)
- **V1 (sem custo):** normalizar (lowercase, remover acentos/stopwords), tokenizar,
  similaridade **Jaccard/cosseno TF-IDF** sobre título+descrição; threshold configurável;
  janela temporal (ex.: itens dos últimos N dias); mesmo idioma.
- **V2 (opcional):** embeddings via AI Client + cosseno (melhores clusters, custo/item).

---

## SEO / Google News
- Schema **NewsArticle** (headline, datePublished, dateModified, author, publisher, image)
  — estender o `class-seo` do hti-engine ou emitir no plugin.
- **News sitemap** (RankMath News ou geração própria).
- Sugestões de **sitelinking** ao revisor (glossário/notícias relacionadas).
- Byline/autor + transparência editorial (evita penalização de conteúdo auto-gerado).

## Guard-rails
- Alucinação → grounding + citações + revisão humana.
- Direitos de autor → síntese original + atribuição + respeitar ToS dos feeds.
- Política Google (IA) → valor editorial + supervisão (não auto-publica).
- Financeiro → educativo + disclaimer; sem conselho/tickers.
- Operacional → backoff por feed, limites, logs sem PII.

## Segurança
- Páginas admin com `manage_options`; ações com **nonce**; `$wpdb->prepare` em todo o SQL.
- Sanitizar URLs de feed (`esc_url_raw`), escapar todo o output.
- Chaves nunca no repo (Definições/`wp-config.php`).

---

## Milestones e critérios de aceitação

### M0 — Scaffold
- Bootstrap, ativação/desativação, versão, verificação de dependência `hti-engine`.
- `dbDelta` das 3 tabelas; página de **Definições** (modelo, intervalo, threshold, limites).
- i18n carregado.
- **Aceite:** ativa/desativa sem erros; tabelas criadas; settings gravam.

### M1 — Feeds
- CRUD de feeds (lista + adicionar/editar/remover) com `WP_List_Table`.
- Botão **Testar feed** (valida URL e mostra N itens de pré-visualização).
- **Aceite:** adicionar um feed válido e ver a pré-visualização; inválido dá erro claro.

### M2 — Fetcher + rascunhos
- Job agendado (Action Scheduler/cron) que percorre feeds ativos.
- Parse + extração de imagem + **dedupe** por `guid_hash`.
- Lista admin de **Rascunhos** (filtros por feed/idioma/estado; ações em lote: ignorar).
- **Aceite:** um feed válido gera N rascunhos **sem duplicados**, com imagem quando
  existe; re-correr não duplica; erros de feed não partem o lote (backoff).

### M3 — Agrupamento
- Clustering por similaridade + UI de **Grupos** (ver itens do grupo, dispensar).
- **Aceite:** itens claramente relacionados caem no mesmo grupo; threshold ajustável.

### M4 — Pesquisa + geração
- `class-research` (Gemini grounded) + `class-generator` + `class-validator`.
- Ação "Gerar artigo" num grupo → cria `news` `pending` com citações/meta/disclaimer.
- **Aceite:** a partir de um grupo, gera um artigo **original, factual, com fontes**,
  em pendente; outputs inválidos são rejeitados com mensagem; nada é publicado.

### M5 — Revisão + SEO
- Schema NewsArticle, news sitemap, sugestões de sitelinking, UI de revisão polida.
- **Aceite:** artigo publicado tem schema válido e aparece no sitemap de notícias.

### M6 — Hardening
- Rate limits, tratamento de erros, logs, EN/PT completo, suíte de testes pure-PHP.
- **Aceite:** suíte verde; limites respeitados; sem PII em logs.

---

## Riscos / questões em aberto
- **Grounding via WP AI Client vs Gemini direto** — confirmar em M4 (provável: Gemini direto).
- **Action Scheduler vs WP-Cron** — recomendado Action Scheduler (fiabilidade); decidir em M2.
- **Idioma dos feeds** — assumir 1 idioma por feed (campo `lang`); cross-língua fica fora do V1.
- **Custos Gemini grounding** — limitar nº de gerações/dia; cache.
- **Google News onboarding** — requisito de processo editorial (fora do código).
