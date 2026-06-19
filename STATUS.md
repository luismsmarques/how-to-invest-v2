# STATUS — HowToInvest (handoff)

_Última atualização: 19 jun 2026 (Learn CPT + categorias + hub, internal linking, feeds sugeridos; RSS AI v1.4). Lê isto primeiro ao retomar/numa sessão nova._

## Onde está o projeto
**LIVE em produção** (`howtoinvest.pro`) e funcional de ponta a ponta:
questionário → resultado (gráfico + disclaimer) → guardar perfil → dashboard;
homepage com artigos; glossário; páginas. **167 testes** verdes.

WordPress 7.0 instalado em `/home/howtoinvest/howtoinvest.pro/`. Tema **HowToInvest**
e plugin **HTI Engine** ativos. Conteúdo criado pelo seeder (glossário + 7 páginas + 8 artigos).

**Design:** redesign **coral/cream** aplicado em todo o lado (tema + app do plugin):
tokens em `theme.json`, **fontes self-hosted** Poppins + Plus Jakarta Sans (em
`themes/howtoinvest/assets/fonts/`, subset latino), header sticky com blur, **donut
conic** no resultado, banner de disclaimer escuro, consentimento escuro.

**Idioma:** **EN por default** (regra do projeto). O *chrome* do tema (header, footer,
hero, passos, glossário, about, switcher) é resolvido **em tempo de render** por
**blocos dinâmicos** (`render_callback`) que usam um mapa EN/PT inline (`t()`/`strings()`
em `functions.php`) + `current_lang()` via Polylang. **Importante:** *patterns* correm no
`init` (antes do Polylang saber a língua) → **não** servem para texto multilíngue; por isso
o chrome usa blocos dinâmicos. O app do plugin é EN-default com PT via `ui()`. O **PT** é
servido pelo **Polylang** (língua adicional `pt_PT_ao90`) — ver secção *Multilíngue* abaixo.

**Header/footer iguais em todo o lado e editáveis:** os menus (primary/footer) voltam a
ser editáveis em *Aparência → Menus* (restaurados no tema de blocos via `register_nav_menus`)
e são renderizados pelo bloco `howtoinvest/menu`. **Mobile:** header responsivo limpo —
hamburger que abre painel do lado direito (CSS-only via checkbox + `header.js`), tap targets
full-width. **About:** página bilingue moderna (template `page-about`, bloco `howtoinvest/about`)
com foco no fundador (Luis Marques) e nos objetivos; ligada no footer. **Switcher de língua**
no footer (`howtoinvest/lang-switcher`, via `pll_the_languages`).

## Arquitetura (resumo)
- **Tema** `wp-content/themes/howtoinvest` (FSE, tokens em `theme.json`, design coral/cream, disclaimer no rodapé).
- **Plugin** `wp-content/plugins/hti-engine` (o produto). Regra de ouro: **as regras decidem, o LLM só explica**.
  - Motor determinístico (`class-engine`) + config curada (`class-config`) → arquétipo + alocação por classe (soma 100), 3 travas.
  - LLM: `class-llm` (transporte: **WP 7.0 AI Client / Connectors** → fallback `class-gemini`) · `class-prompt` · `class-validator` · `class-fallback` · `class-explainer`.
  - REST `htinvest/v1`: recommend, result, register, login, claim-profile, my-profiles, export, account.
  - Frontend (JS vanilla): `questionnaire.js`, `result.js` (donut conic-gradient), `account.js`, `consent.js`, `analytics.js`.
  - Segurança/RGPD: rate limit (`class-rate-limit`), verificação email double opt-in via **Brevo** (`class-verification`+`class-mailer`), consentimento (`class-consent`), GA gated, cron de limpeza (`class-cron`), login Google (`class-google`).
  - Admin: `class-settings` (Definições → HowToInvest). PDF: `class-pdf` (Dompdf, fallback HTML).
  - **Tipos de conteúdo (CPTs públicos):** `glossary` (`/investing-glossary/`, taxonomia `glossary_topic`),
    `news` (`/financial-news/`, `news_category`), e **`learn`** (`/learn/`, taxonomia `learn_topic`) — os
    **artigos educativos** são agora um CPT dedicado (não posts), com base própria e categorias.
  - **Conteúdo SEO seedado** (`class-seeder`, bilingue EN+PT, idempotente):
    - **Glossário**: 42 termos em **10 tópicos** (`glossary_topic_of`); SEO title/desc (RankMath+Yoast).
    - **Learn**: 8 artigos em **4 categorias** (`learn_topic`: Começar / Conceitos / Comportamento / Planeamento);
      o seeder **migra** artigos legados (`post` → `learn`), atribui categoria, e liga "Artigos relacionados".
    - **Páginas de Arquétipos** (5, tabela de alocação ilustrativa do `Config`) + **Classes de ativos** (5
      "explained") + hubs (Perfis / Classes de ativos / Tools).
    - **Malha de internal linking bidirecional** (conteúdo ↔ glossário ↔ Learn), com localização PT robusta
      (passo final `relocalize_pt` independente da ordem do seed).
  - **Hub de Ferramentas** (`class-tools`, shortcode `[hti_tool name=…]`): 4 calculadoras educativas
    (juro composto, inflação, meta de poupança, custo de esperar) — JS vanilla com motor partilhado
    (`tools-core.js`, testado com Node), gráficos SVG leves, indexáveis; hub `/tools/` + 4 páginas no menu.
  - **Hub Aprender** (`/learn/`): bloco dinâmico `howtoinvest/learn-hub` (artigos por categoria, por idioma)
    em `archive-learn.html`; menu **Aprender → /learn/**; homepage lista o CPT `learn`.
  - **Menu principal:** Aprender · Perfis · Classes de ativos · Ferramentas · Glossário · Notícias.
- Detalhe por ficheiro: `wp-content/plugins/hti-engine/README.md`.
- **Plugin** `wp-content/plugins/hti-rss-ai` (**HTI RSS AI Feed**, v1.4.0) — alimenta a área de
  **notícias** (`news` CPT do hti-engine). Pipeline com **humano no meio (nunca auto-publica)**:
  **Feeds** (CRUD + *Test feed*) → **Fetch** (cron `rssai_fetch_cron` ou *Fetch now*) →
  **Drafts** (itens dedup por `sha1(guid|link)`, imagem extraída) → **Groups** (clustering Jaccard
  por língua, threshold configurável) → escolher grupo → **Generate** (Gemini com **Google Search
  grounding** → investiga factos + cria artigo SEO/Google-News com fontes citadas) → `news`
  em **pending review**, **já com imagem de destaque**. Travas: factual/citado/original, **sem conselhos**, **sem tickers**,
  disclaimer; valida via `class-validator` e limite diário de gerações.
  - **3 tabelas** (`rssai_feeds`, `rssai_items`, `rssai_groups`); opções `rssai_settings`/`rssai_logs`.
  - **Reutiliza `HTI_GEMINI_API_KEY`** (nunca guarda a chave; filtro `rssai_gemini_api_key` opcional).
  - Modelo texto default `gemini-2.5-flash`; menu próprio *RSS AI Feed* (Settings/Feeds/Drafts/Groups/Logs).
  - **Feeds:** botão *Add suggested feeds* semeia 11 fontes curadas (EN+PT: MarketWatch, CNBC, Investing.com,
    BBC, Guardian, Fed, Economist, ECO, Observador, Jornal de Negócios) — idempotente; testar cada uma.
  - **Imagem de destaque (M7):** **foto AI** sobre o tema da notícia (16:9), guardada como thumbnail.
    Cliente **dual-endpoint**: modelos **Imagen** (`:predict`, default `imagen-4.0-generate-001`) e **Gemini-image**
    (`:generateContent`) escolhidos pelo nome. **Image-to-image:** se o draft tiver imagem de feed, ela é a **base**
    e é reinventada no estilo da marca por um modelo Gemini-image (default `gemini-2.5-flash-image`); senão
    text-to-image; senão imagem do feed crua; senão nenhuma. Fonte registada (`ai-from-feed`/`ai`/`feed`/`none`).
    Botão *Regenerate AI image* na meta box. **Imagen exige billing + acesso a image-gen**.
  - **Kit de redes sociais (M8):** meta box pós-publicação com **download** de cartões de marca renderizados
    **localmente com GD** (fontes `.ttf` em `assets/fonts/`) — **Quadrado 1080×1080** (feed) e **Story 1080×1920**
    (stories) — com a **foto de destaque lá dentro** + título/data/disclaimer. **Reaproveita** a foto (sem gastar AI).
    Templates fiéis a *Notícias · Quadrado* e *Notícias · Story* do design. **Adiados:** editorial 4:5, X/Twitter.
  - Meta box no editor de `news`: proveniência + fontes + sugestões de sitelinking (glossário/related).
  - Detalhe: `wp-content/plugins/hti-rss-ai/README.md`; plano: `docs/RSS_AI_Feed_Plan.md`.

## Chaves a definir (no `wp-config.php` de `howtoinvest.pro/`)
```php
define( 'HTI_GEMINI_API_KEY', '...' );   // ou Definições → Connectors (WP AI Client)
define( 'HTI_BREVO_API_KEY',  '...' );   // emails de verificação de conta (P0 p/ contas)
define( 'HTI_GOOGLE_CLIENT_ID',     '...' ); // login Google (opcional)
define( 'HTI_GOOGLE_CLIENT_SECRET', '...' );
```
- Sem Gemini/Connectors → resultado usa **fallback curado** (funciona).
- Sem Brevo → `wp_mail` (pode não entregar em shared hosting) → registo de contas não confirma.
- Google: registar o **Redirect URI** (Definições → HowToInvest) no Google Cloud Console.
- GA4 já ativo (`G-QWST7PZNBT`), só carrega após aceitar o banner de cookies.

## Multilíngue (Polylang)
- **EN = língua default**, **PT (`pt_PT_ao90`) = adicional**. Garantir que o conteúdo
  EN existente tem idioma atribuído (*Languages → Settings → "Set the language for all content"*).
- ⚠️ **Ativar tradução dos CPTs/taxonomias** em *Languages → Settings*: `glossary`/`glossary_topic`,
  `news`/`news_category` e **`learn`/`learn_topic`** (novo). Sem isto, as traduções PT não ligam.
- O **seeder cria o PT** de cada entrada (glossário/páginas/artigos) a partir das
  variantes `hti_*_pt`, define o idioma, partilha o slug EN e **liga EN↔PT**
  (`pll_save_post_translations`). Traduz/liga também o topic `glossary_topic`
  (*Asset classes → Classes de ativos*). Idempotente; sem Polylang degrada para EN+meta.
- **Slugs/permalinks PT traduzidos** para SEO: o seeder usa um mapa curado (`pt_slug()` em
  `class-seeder.php`, ex.: `global-equities → acoes-globais`, `how-to-start-investing →
  como-comecar-a-investir`). A **base dos CPTs fica em EN por agora** (traduzir a base exigiria
  Polylang Pro ou rewrite custom frágil — decisão adiada).
- Os **links internos** dos artigos PT são reescritos para o **permalink PT** do glossário
  (via `pll_get_post`/`get_permalink`) — robusto mesmo com os slugs traduzidos.
- Correr depois de cada deploy que mude o seed: **Ferramentas → Semear conteúdo → Run seeder**
  (ou `wp hti seed`). O aviso mostra quantas traduções PT foram ligadas.

## Deploy
- Branches: **`main`** = produção · **`develop`** = staging/integração · feature → PR para `develop` → release `develop → main`. Ver `CONTRIBUTING.md`.
- cPanel Git: `Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit`. O `.cpanel.yml` (simples; destino fixo `howtoinvest.pro/wp-content`) copia **tema + hti-engine + hti-rss-ai**.
- **Se o deploy do cPanel falhar/pendurar:** ver `DEPLOY.md §5.1` (deploy manual / File Manager copy a partir de `repositories/how-to-invest-v2/wp-content/...`).
- **Bump de versão obrigatório** ao mexer em CSS/JS do tema/plugin (constante VERSION → `?ver=`), senão a cache serve assets antigos. Em template parts personalizadas no Site Editor, *Clear customizations* para o tema voltar a usar os ficheiros.
- Testes engine (157 verdes): `for t in engine settings explainer prompt ratelimit cron mailer google llm; do php wp-content/plugins/hti-engine/tests/test-$t.php; done`
- Testes calculadoras (Node, 14 verdes): `node wp-content/plugins/hti-engine/tests/test-tools-core.mjs`
- Testes RSS AI (29 verdes): `for t in extract-json validator grouping social-card image-client; do php wp-content/plugins/hti-rss-ai/tests/test-$t.php; done`

## O que falta para o GO-LIVE público (checklist completa: `docs/QA_Gate_Lancamento.md`)
**Código (produto):** ✅ tudo (lacunas L-A/L-B/L-C fechadas).

**Código — adiado de propósito (opcional, não bloqueia):**
- [ ] **Hub de Ferramentas — 2ª leva:** Fundo de emergência, Visualizador de alocação por arquétipo (reusa `result.js`+`Config`), Regra dos 72, Impacto das comissões. (1ª leva — juro composto/inflação/meta/custo de esperar — **feita**.)
- [ ] Kit social: template **Editorial 4:5** (img3) e **X/Twitter 1600×900**.
- [ ] Outros templates do design (Facto curioso, Glossário cards, CTA, Infográfico, Resumo diário) — "kit de conteúdo" futuro.
- [ ] **Base dos slugs dos CPTs em PT** (`/news/`, `/glossary/`) — exige Polylang Pro ou rewrite custom (deixada em EN).

**Operacional (teu, no servidor):**
- [ ] **Deploy para produção** da última `main` (foto AI de destaque + kit social) via cPanel.
- [ ] HTTPS forçado (redirect http→https em todo o site)
- [ ] Verificar os 8 redirects 301 do Base44 (ex.: `/About` → `/about/`)
- [ ] Backups externos automáticos **e restauro testado**
- [ ] Cache (LiteSpeed/WP) + CDN (Cloudflare) + Core Web Vitals
- [ ] **RankMath**: instalar/ativar → sitemap inclui `glossary`/`news` → submeter ao Search Console
- [ ] Configurar **Brevo** (senão o registo de contas não confirma)
- [ ] **Polylang**: atribuir idioma a todo o conteúdo + correr o seeder → confirmar ligações EN↔PT (e `hreflang` no sitemap)
- [ ] **RSS AI Feed**: ativar o plugin em produção → *Settings* (confirmar `HTI_GEMINI_API_KEY` + acesso Imagen, modelo, intervalo) → adicionar feeds → *Fetch now* → *Group now* → gerar 1 grupo e **rever** (+ kit social) antes de publicar
- [ ] Acessibilidade: contraste AA + teste com leitor de ecrã

**Legal (⚠️ bloqueador antes de divulgar):**
- [ ] **L-D — Revisão jurídica** dos disclaimers + páginas privacidade/termos (são placeholders; mencionar o GA na política de privacidade).

## Próximos passos sugeridos
1. Configurar RankMath (sitemap + schema + Search Console).
2. Configurar Brevo e testar o fluxo de registo/verificação.
3. Verificar 301s + HTTPS.
4. Enviar textos legais ao jurista (L-D).
5. Ativar o **HTI RSS AI Feed**, adicionar feeds e validar 1 geração ponta a ponta antes de a usar em produção.
