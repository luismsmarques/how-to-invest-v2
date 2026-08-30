# STATUS — HowToInvest (handoff)

_Última atualização: 30 ago 2026, fim do dia (secção **`/games/`** — plugin novo `hti-games` com dois jogos educativos, integração fechada e passagem de QA às costuras entre workstreams. **Versões reais: HTI Engine 0.15.0 · HTI Forex 0.12.4 · RSS AI 1.11.1 · tema 0.8.58 · HTI Social 0.9.9 · HTI Games 0.1.0.** ~3.820 asserções verdes nas quatro suites. A secção `/games/` está **construída mas ainda não pode ir para o ar** — ver a secção própria mais abaixo). Anterior: 30 ago 2026 (auditoria completa ao projeto + cronologia de setembro em `docs/Estado_e_Cronologia_Set2026.md` — lê esse a seguir a este. Corrigida a difusão do bot, que nunca chegou a enviar nada. **Versões reais: HTI Engine 0.15.0 · HTI Forex 0.12.4 · RSS AI 1.11.1 · tema 0.8.58 · HTI Social 0.9.9.** ~1.770 asserções verdes nas quatro suites de então). Antes: 29 ago 2026 (bot de Telegram no hti-forex). Antes disso: 19 jun 2026 (sistema de emails completo: transacionais + newsletter Brevo segmentada EN/PT + lifecycle de conta 09–14; formulário de contacto; categorias de notícias; fix PT do /learn/. HTI Engine v0.7.0, RSS AI v1.5.0, tema v0.6.9). Lê isto primeiro ao retomar/numa sessão nova._

## Onde está o projeto
**LIVE em produção** (`howtoinvest.pro`) e funcional de ponta a ponta:
questionário → resultado (gráfico + disclaimer) → guardar perfil → dashboard;
homepage com artigos; glossário; páginas; **secção editorial de corretoras**
(CPT `broker`, ~26 páginas, redirector `/go/`); comparador de depósitos; hub de
ferramentas; `/forex/` e o bot de Telegram. **~3.820 asserções** verdes.
A secção **`/games/`** (plugin `hti-games`) está construída e testada mas **ainda
não está em produção**: falta o deploy, o conteúdo dos dois jogos e a verificação
editorial dos casos de The Reveal.

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
  - REST `htinvest/v1`: recommend, result, register, login, claim-profile, my-profiles, export, account (DELETE = **agenda**
    eliminação), cancel-deletion, change-email, preferences (GET/POST), email-result, contact, subscribe.
  - Frontend (JS vanilla): `questionnaire.js`, `result.js` (donut conic-gradient), `account.js`, `consent.js`, `analytics.js`.
  - Segurança/RGPD: rate limit (`class-rate-limit`), verificação email double opt-in via **Brevo** (`class-verification`+`class-mailer`), consentimento (`class-consent`), GA gated, cron de limpeza (`class-cron`), login Google (`class-google`).
  - Admin: `class-settings` (Definições → HowToInvest). PDF: `class-pdf` (Dompdf, fallback HTML).
  - **Tipos de conteúdo (CPTs públicos):** `glossary` (`/investing-glossary/`, taxonomia `glossary_topic`),
    `news` (`/financial-news/`, `news_category`), e **`learn`** (`/learn/`, taxonomia `learn_topic`) — os
    **artigos educativos** são agora um CPT dedicado (não posts), com base própria e categorias.
  - **Conteúdo SEO seedado** (`class-seeder`, bilingue EN+PT, idempotente):
    - **Glossário**: **58 termos** (pipeline `content/glossary/*.md`); SEO title/desc (RankMath+Yoast).
      ⚠️ 53 dos `.md` declaram `topic: key-terms`, que **não existe** em `glossary_topics()` — 16 termos
      ficam fora de todos os arquivos de tópico.
    - **Learn**: **35 capítulos** (30 em `content/learn/*.md` + 5 legados), espinha em `learn-plan.csv`;
      o seeder **migra** artigos legados (`post` → `learn`), atribui categoria, e liga "Artigos relacionados".
    - **Páginas de Arquétipos** (5, tabela de alocação ilustrativa do `Config`) + **Classes de ativos** (5
      "explained") + hubs (Perfis / Classes de ativos / Tools).
    - **Malha de internal linking bidirecional** (conteúdo ↔ glossário ↔ Learn), com localização PT robusta
      (passo final `relocalize_pt` independente da ordem do seed).
  - **Hub de Ferramentas** (`class-tools`, shortcode `[hti_tool name=…]`): 8 calculadoras educativas
    (juro composto, meta de poupança, inflação, custo de esperar, fundo de emergência, regra dos 72,
    impacto das comissões, visualizador de alocação) — JS vanilla com motor partilhado
    (`tools-core.js`, testado com Node), gráficos SVG leves, indexáveis. Slugs, títulos e copy vivem
    numa tabela única (`class-tools-content.php`), consumida pelo seeder, pelos 301 e pelo schema.
    Estrutura de URL igual à do forex: hub `/tools/` com as calculadoras como páginas-filhas
    (`/tools/{ferramenta}/`, `/pt/ferramentas/{ferramenta}/`); os URLs planos antigos redirecionam 301.
  - **Hub Aprender** (`/learn/`): bloco dinâmico `howtoinvest/learn-hub` (artigos por categoria, por idioma)
    em `archive-learn.html`; menu **Aprender → /learn/**; homepage lista o CPT `learn`.
  - **Menu principal:** Aprender · Perfis · Classes de ativos · Ferramentas · Glossário · Notícias.
  - **Formulário de contacto** (`class-contact`, shortcode `[hti_contact]` na página Contacto): nome/assunto/mensagem
    + **consentimento RGPD** obrigatório + honeypot; nonce + rate-limit; envia para **info@howtoinvest.pro** (Reply-To
    do visitante) e **auto-resposta** branded ao visitante (EN/PT pelo URL). Destinatário filtrável (`HTI_CONTACT_EMAIL`).
  - **Categorias de notícias** (`news_category`, seedadas bilingues): Market analysis, Stock Analysis, Economy & Central
    Banks, Companies & Earnings, Commodities & Currencies, Cryptocurrencies, Personal Finance. O prompt do RSS-AI escolhe
    de entre as existentes.
- **Sistema de emails** (todos no layout branded partilhado `class-emails`, bilingues EN/PT, via **Brevo** `class-mailer`):
  - **Transacionais (`class-account`/`class-verification`/`class-contact`):** 01 Boas-vindas (após confirmar), 02 Confirmar
    registo, 05 Repor password (email core do WP, branded via filtro), 07 Perfil de investidor ("Enviar-me o resultado"
    no result.js → `POST /email-result`), 08 Auto-resposta de contacto.
  - **Lifecycle de conta (`class-account`, templates 09–14):** 09 Alerta de segurança (password alterada: data/dispositivo/IP);
    10 Alteração de email (confirmação 24h, form na conta + `POST /change-email`); 11 **Eliminação RGPD agendada (30 dias)**
    com cancelar + descarregar (cron diário `hti_account_deletions` apaga no fim do prazo — substitui a eliminação imediata);
    12 Reativação (tracking de último login via `wp_login` + cron semanal `hti_reactivation` p/ inativos 90+ dias);
    13 Preferências (newsletter/frequência/categorias na conta → atributos do contacto Brevo + email de confirmação).
  - **Newsletter/marketing (`class-subscribe` + `class-campaigns` + `class-brevo`):** subscrição **double opt-in** via
    `[hti_subscribe]` (na archive de Notícias) com tokens HMAC sem estado (confirmar/cancelar) — **contactos geridos no
    Brevo** (Contacts API), **segmentados por idioma** (listas **EN/PT** separadas, atributo `LANGUAGE`). **Newsletter semanal**
    (cron seg 09:00) e **Resumo diário** (cron 07:00) construídos do CPT `news` por idioma e enviados via **Brevo Campaigns
    API**; **Aviso da plataforma** (broadcast manual EN/PT/ambas). Admin: **Settings → HTI Newsletter** (enviar/pré-visualizar).
  - **NPS (`class-nps`, template 14):** email com escala 0–10 clicável (links com token por utilizador) → regista a resposta;
    **Settings → HTI NPS** envia o inquérito e mostra resultados (nº, média, score NPS).
- Detalhe por ficheiro: `wp-content/plugins/hti-engine/README.md`.
- **Plugin** `wp-content/plugins/hti-rss-ai` (**HTI RSS AI Feed**, v1.5.0) — alimenta a área de
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
  - **Kit de redes sociais (M8) — REMOVIDO:** o antigo kit GD (cartões Quadrado/Story renderizados com GD +
    fontes `.ttf`) foi **removido** (hti-rss-ai v1.6.0) por ser substituído pelo plugin **`hti-social`** (Social
    Generator), que cobre os mesmos formatos e mais — com muito maior fidelidade ao design e exportação por
    `<foreignObject>`. A foto de destaque AI continua a ser reaproveitada (auto-fill na meta box do `hti-social`).
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
- **Brevo (Definições → HowToInvest):** chave API (`xkeysib-…`), **sender verificado** (SPF/DKIM no domínio),
  e **IDs das listas Newsletter (EN)** e **(PT)** (criar 2 listas em Brevo → Contacts → Lists). Newsletter/digest/aviso só
  enviam com lista configurada. Opcional: `HTI_CONTACT_EMAIL` (default `info@howtoinvest.pro`). O **repor password** é do core
  WP via `wp_mail` (branded) — para o passar por Brevo, instalar o plugin SMTP oficial do Brevo.
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
- cPanel Git: `Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit`. O `.cpanel.yml` (simples; destino fixo `howtoinvest.pro/wp-content`) copia **tema + hti-engine + hti-rss-ai + hti-social + hti-forex + hti-games** (cinco plugins).
  ⚠️ O deploy faz `rm -rf` + `cp -R`, portanto **destrói o `vendor/`** e depende do `composer install` protegido
  por `|| true`: se falhar, o deploy fica verde e o **export PDF degrada silenciosamente para HTML**. O
  `DEPLOY.md §` que fala de rsync com `--exclude vendor/` está errado.
- **Se o deploy do cPanel falhar/pendurar:** ver `DEPLOY.md §5.1` (deploy manual / File Manager copy a partir de `repositories/how-to-invest-v2/wp-content/...`).
- **Bump de versão obrigatório** ao mexer em CSS/JS do tema/plugin (constante VERSION → `?ver=`), senão a cache serve assets antigos. Em template parts personalizadas no Site Editor, *Clear customizations* para o tema voltar a usar os ficheiros.
- Suites (é o que a CI corre): `php wp-content/plugins/hti-engine/tests/run.php` (1.073) ·
  `php wp-content/plugins/hti-forex/tests/run.php` (676 PHP + 83 Node) ·
  `php wp-content/plugins/hti-rss-ai/tests/run.php` (67) ·
  `php wp-content/plugins/hti-games/tests/run.php` (1.888 PHP + 37 Node) — **~3.820 no total**.
  A CI faz `php -l` e `node --check` a **todos** os plugins e ao tema, corre as quatro suites e
  volta a correr as três suites Node explicitamente (para que um `node` em falta seja um erro e não
  uma linha "skipping" que ninguém lê).
  ⚠️ O `hti-social` continua **sem testes próprios** — é lintado, mas nada verifica o que faz.

## O que falta para o GO-LIVE público (checklist completa: `docs/QA_Gate_Lancamento.md`)
**Código (produto):** ✅ tudo (lacunas L-A/L-B/L-C fechadas).

**Código — adiado de propósito (opcional, não bloqueia):**
- [x] **Hub de Ferramentas — 2ª leva (feita):** Fundo de emergência, Visualizador de alocação por arquétipo (donut via `Config`+`Engine::allocate`, por classes), Regra dos 72, Impacto das comissões. (1ª leva — juro composto/inflação/meta/custo de esperar — também feita.) Páginas seedadas EN+PT + ligadas no hub `/tools/`; `tools-core` com 27 testes verdes.
- [x] **Plugin `hti-social` (Social Generator) — feito:** novo plugin que rende os modelos do design "Social Templates" (handoff 9) como HTML/CSS e exporta PNG fiel **sem dependências pesadas** (SVG `<foreignObject>` → canvas, fontes self-hosted em base64). **19 templates**: Notícias (Quadrado/Story/X), Glossário (Facebook/Feed/Story), Facto curioso (verde/roxo/story), CTA Questionário (Quadrado/Story/X), og:image (foto cheia/split 1200×630) e Editorial 4:5 (Destaque, Economia, Promo ferramenta, **Infográfico** com gráfico SVG, Resumo diário). Dois locais: página **Social** no admin + meta box **Social cards** em Notícias/Glossário (auto-preenchida). Disclaimer bilingue e linguagem por classes embutidos. Substitui o "kit social" GD do RSS-AI para estes formatos.
- [ ] **Base dos slugs dos CPTs em PT** (`/news/`, `/glossary/`) — exige Polylang Pro ou rewrite custom (deixada em EN).

**Operacional (teu, no servidor):**
- [ ] **Deploy para produção** da última `main` (foto AI de destaque + kit social) via cPanel.
- [ ] HTTPS forçado (redirect http→https em todo o site)
- [ ] Verificar os redirects 301 (19 entradas Base44 em `class-redirects.php:63-88`, mais PT e tools)
- [ ] Backups externos automáticos **e restauro testado**
- [ ] Cache (LiteSpeed/WP) + CDN (Cloudflare) + Core Web Vitals
- [ ] **RankMath**: instalar/ativar → sitemap inclui `glossary`/`news` → submeter ao Search Console
- [ ] Configurar **Brevo** (chave + sender verificado + **2 listas EN/PT** nas Definições) — senão o registo de contas
      não confirma e a newsletter/digest/NPS não enviam. Testar: subscrever (double opt-in), Settings → HTI Newsletter
      (preview/send), Settings → HTI NPS (send + resultados).
- [ ] **Polylang**: atribuir idioma a todo o conteúdo + correr o seeder → confirmar ligações EN↔PT (e `hreflang` no sitemap)
- [ ] **RSS AI Feed**: ativar o plugin em produção → *Settings* (confirmar `HTI_GEMINI_API_KEY` + acesso Imagen, modelo, intervalo) → adicionar feeds → *Fetch now* → *Group now* → gerar 1 grupo e **rever** (+ kit social) antes de publicar
- [ ] **Jogos `/games/`**: ativar o `hti-games`, semear as páginas, gerar/importar os cenários e **deixar
      The Reveal desligado** até um editor verificar os cinco casos. Checklist completa na secção
      *Jogos educativos (`hti-games`)* mais abaixo.
- [ ] Acessibilidade: contraste AA + teste com leitor de ecrã

**Legal (⚠️ bloqueador antes de divulgar):**
- [ ] **L-D — Revisão jurídica.** As páginas legais **já não são placeholders** (a política de privacidade é
      substantiva e descreve o GA4 em detalhe); faltam os marcadores `[●]` (morada, jurisdição) e a revisão em si.
      ⚠️ O âmbito é maior do que este documento assumia: cobre também a **camada de afiliação** (o gate
      "Corretoras & afiliados" está **0/9** com a secção já em produção), o `ads.txt` com publisher id real, e a
      exposição RBI/FEMA do `/forex/`. E já se está a divulgar — há campanhas pagas a correr.

## Forex tools Índia (`hti-forex`) — ago 2026

Plugin novo e **isolado** para a secção `/forex/` (EN-only): calculadoras forex
dirigidas ao mercado indiano, usadas como landing pages de campanhas pagas
(Propeller/Facebook via GTM) **e** páginas indexáveis (a lacuna INR identificada
na pesquisa de mercado). É a **única exceção documentada** aos invariantes
"sem CTA de corretora / bilingue" — contida no plugin; nada do hti-engine foi
alterado.
  - **Bloco de conversão comutável (0.8.0):** o slot a seguir à calculadora leva o canal de Telegram **ou** o formulário de email, escolhido em Definições → HTI Forex (`telegram` por omissão). É uma experiência: este público lê Telegram todos os dias e pode aderir a um canal mais facilmente do que dar o email a um site estrangeiro. A oferta é a mesma nos dois casos — o cheat sheet INR, fixado no canal em vez de enviado. Cliques contados como `cta_click` em `forex_telegram_*`; adesões pelo link de convite com nome do próprio Telegram. Reverter é um clique: o `hti_lead_magnet` e o PDF ficam ligados nos dois modos.

- **Ferramentas** (`[hti_forex_tool name=…]`): position size com conta em ₹
  (floor a micro-lote, estado "below one micro lot"), pip value em ₹ (EURUSD,
  GBPUSD, USDJPY, XAUUSD, USDINR; formatação lakh/crore), e relógio de sessões
  em **IST** (baseline server-side sem JS + relógio vivo com overlap Londres–NY;
  DST vem da tz database, incluindo os desyncs de março/novembro).
- **Câmbios**: cron 2×/dia (Frankfurter/BCE, sem chave) → option; precedência
  override do admin > fetched > fallback; o rate é sempre input editável com
  data visível — API morta nunca parte a página.
- **Monetização**: CTA de afiliado **off por defeito** (Settings → HTI Forex:
  URL https, label, kill-switch global + por ferramenta; `rel="sponsored
  nofollow noopener"`; clickid/utm_campaign propagado ao href no cliente) +
  captura de email via o endpoint `subscribe` existente com `source=forex-*`.
  Tracking: `cta_click` declarativo via `hti-track` (allowlist intocada).
- **SEO**: 4 páginas seedadas (`/forex/` hub + position-size-calculator +
  pip-value-calculator + market-hours-ist, botão no admin ou `wp hti-forex
  seed`), JSON-LD próprio (WebApplication INR + FAQPage + breadcrumbs), FAQs
  de `Config::faqs()` partilhadas entre página e schema. FAQ legal (FEMA/RBI)
  **só no hub**, num único sítio.
- **Testes**: suite própria (`php wp-content/plugins/hti-forex/tests/run.php`)
  — settings, rates, sessões/DST, schema em PHP + asserções Node no núcleo
  de matemática. CI e `.cpanel.yml` já incluem o plugin.
- **v2 (Fase 1 completa + funil de email):** profit/loss calculator em ₹
  (com sinal, verde/vermelho); extensão de margem/leverage do position size
  (`leverage="1"` → notional + margem em ₹ — "leverage muda a margem, não o
  tamanho"); 4 páginas de variante com conteúdo único (profit-calculator,
  xauusd-lot-size-calculator, lot-size-for-100-dollar-account,
  lot-size-calculator-with-leverage) — 8 páginas no total, hub relistado
  (apagar hub antigo + re-seed para regenerar a lista). **Funil:** o
  hti-engine ganhou um pending store generalizado (`hti_pending_source`) —
  o `source` de qualquer opt-in vira atributo `SOURCE` no Brevo (⚠️ criar o
  atributo de texto no dashboard do Brevo) e o filtro `hti_lead_magnet`
  permite lead magnets por plugin: opt-ins `forex-*` recebem o **INR lot
  size cheat sheet** (PDF de 2 páginas, commitado, fonte HTML regenerável
  via Chromium). Comportamento do ebook intacto; suites verdes.
- **Nota de i18n:** o `/forex/` é EN-only por desenho, mas **não é a única exceção** ao invariante bilingue —
o **comparador de depósitos é PT-first** (`class-deposits.php:169-173`).

**Antes de ligar o CTA em produção**: rever a exposição regulatória (Alert
  List RBI / FEMA — promover corretoras offshore a residentes indianos é o
  risco; as ferramentas em si são seguras) e configurar o URL de afiliado no
  admin. Sem configuração, as páginas são 100% educativas.

## Jogos educativos (`hti-games`) — ago 2026

Plugin novo e **isolado** para a secção `/games/` (`/pt/jogos/`), bilingue
EN+PT, **selado da parte monetizada do site** (invariante 9 do `CLAUDE.md`:
nenhum link de afiliado, banner, módulo de parceria ou menção a corretora em
lado nenhum). Dois jogos diários sobre dinheiro virtual, indexáveis, que
ensinam o que o questionário não ensina — o que o **tamanho da posição** faz a
uma conta.

- **Survive the Charts** — 80 velas, decidir compra/venda/passar e depois
  escolher que fração de uma conta virtual de $10.000 pôr atrás da leitura.
  Stop a 1×ATR, alvo a 1,5×ATR, seis níveis de risco (0,5% … 25%) e um
  interruptor de "dobrar a aposta". A vela que contém os dois níveis resolve
  sempre como stop — nada no OHLC diz qual dos preços veio primeiro, e o jogo
  nunca pode favorecer a posição.
- **The Reveal** — dossiê anonimizado de uma empresa real num ano real: setor,
  seis fundamentais contra a média do setor, três manchetes do período.
  Investir uma fatia da conta ou passar; só depois o nome, o ano e o retorno
  real a 5 anos, ao lado do que o índice fez no mesmo período.
- **Classificação e perfil:** duas tabelas (jogadores + corridas), board diário
  ordenado por **pontuação normalizada pelo risco** (`Scoring::board_score()`,
  o P&L a 1% de risco) e não pelo P&L cru — ordenar pelo lucro faria do topo
  da tabela a lista de quem apostou mais, que é o inverso da lição. O perfil
  mostra a **métrica de aprendizagem** (risco médio por semana), um calendário
  de 28 dias e as medalhas.

**Como está construído**

- **As regras decidem, o cliente só anima.** Os dois motores existem duas vezes
  — `class-stc-engine.php` / `class-reveal-engine.php` (decidem, no servidor) e
  `assets/js/*-core.js` (animam) — em aritmética **inteira** de ponta a ponta
  (preços em ticks, risco em pontos base, dinheiro em dólares inteiros).
  `tests/fixtures/parity.json` é o contrato entre as duas portas: mudar a
  matemática de um lado sem regenerar põe a outra suite a vermelho.
- **Anti-batota:** `GET /today` é construído por **whitelist campo a campo** —
  nem uma vela para lá da 80.ª, nem o nome da empresa, nem o ano, nem o
  retorno, nem o `post_id` (o cliente recebe um HMAC do dia sob `wp_salt`).
  Uma whitelist falha **fechada**: um campo novo no CPT simplesmente não sai.
- **Uma decisão por dia, à prova de corrida:** `UNIQUE KEY one_per_day
  (player_id, game, day_key)` **é** a regra, não uma verificação em PHP — o
  segundo POST falha no índice e devolve o resultado já registado (409).
- **Sessão anónima (RGPD):** cookie + tabela própria, **sem email e sem IP**;
  ligar uma conta é um `user_id` e mais nada. Onboarding com acknowledgement
  registado (`ack_at`/`ack_ver`), botão "esquece-me" na própria página de
  perfil, e `uninstall.php` que larga as duas tabelas.
- **Conteúdo:** CPTs privados `hti_stc_scenario` e `hti_reveal_case`; a rotação
  é **calculada na leitura** a partir do índice do dia (o WP-Cron está
  desligado em produção — um jogo que dependesse dele deixava de rodar).
  Importador CSV/JSON no admin, gerador determinístico (CLI
  `wp hti-games generate`) e, o que faz o jogo funcionar logo a seguir a um
  deploy, uma **biblioteca de 365 cenários que o plugin traz como semente e
  não como ficheiro de dados** — instalada por botão no admin, por lotes e
  retomável, sem SSH (`Config::LIBRARY_SEED`/`LIBRARY_COUNT`, `Installer`).
- **SEO:** 5 páginas seedadas EN+PT a partir de **uma só tabela de slugs**
  (`Config::pages()`) — hub, os dois jogos, classificação e perfil (este
  `noindex`); JSON-LD `Game` + `WebApplication` + `FAQPage` + breadcrumbs, com
  as FAQs vindas do mesmo array que a página mostra. A frase de aterragem do
  Survive the Charts **deriva do conteúdo**, não de uma opção: enquanto o pool
  tiver cenários gerados, a página diz que os gráficos são gerados.
- **Testes:** suite própria (`php wp-content/plugins/hti-games/tests/run.php`)
  — 1.888 asserções PHP + 37 Node, incluindo acessibilidade, segurança/RGPD,
  anti-batota, orçamento de assets em gzip, e o `test-no-brokers.php`, que
  agora **renderiza** as 30 páginas e shells da secção nas duas línguas e falha
  se aparecer um `/go/`, um `rel="sponsored"`, um slug de corretora (com
  fronteira de palavra — "xtb" vive dentro de "nextButtons") ou **qualquer link
  para fora do site**.
- **Fechado nesta passagem (30 ago):** a estatística da multidão passou a ter
  query (contagem de corridas perdedoras e de passes no mesmo `day_stats()`,
  mesmo transient de 60s) e as quatro strings que estavam mortas ficaram
  ligadas — com supressão da percentagem abaixo de 20 jogadores, porque "67%
  perderam" em três corridas é ruído; o `board_size` das definições passou a
  chegar à query (era um 50 fixo enquanto o admin oferecia 3–100); o JSON-LD
  deixou de anunciar como `Game` um jogo desligado pelo kill-switch; o
  `wp_robots` e o JSON-LD passaram a usar **um só detetor** de página; e o
  aviso do risco dobrado deixou de mostrar o número certo debaixo da frase
  errada ("a 0,5%…" com a conta de 1%). Novo `tests/test-integration.php` com
  as asserções que teriam apanhado cada uma destas.

**Ainda não pode ir para o ar — é o que falta a um humano:**

- [ ] **Deploy** para staging e depois produção (o `.cpanel.yml` e a CI já
      incluem o plugin) e **ativar** o plugin.
- [ ] **Semear as páginas**: Definições → HTI Games → *Seed / sync*. Sem isto
      são cinco 404 com shortcodes a funcionar por trás.
- [ ] **Polylang**: ativar tradução dos CPTs novos e confirmar as ligações
      EN↔PT das cinco páginas.
- [ ] **Conteúdo do Survive the Charts**: Definições → HTI Games → *Instalar a
      biblioteca de cenários*, e carregar até dizer feito (instala por lotes,
      ~100 por clique, e retoma onde parou). Publica os 365 gráficos da
      biblioteca que o plugin traz — que é uma **semente**, não um ficheiro de
      dados: `Config::LIBRARY_SEED` + `LIBRARY_COUNT` reproduzem sempre a mesma
      biblioteca. **Sem SSH e sem CLI.** Continua a haver
      `wp hti-games generate` para quem tem shell e quer outra semente, e o
      importador para séries reais.
- [ ] **The Reveal não pode abrir ainda.** Os cinco casos protótipo são
      seedados **de propósito por acabar**: `draft`, `hti_rev_verified = 0`,
      `hti_rev_source_url` **vazio** e **todos os números vazios** — os dois
      retornos a 5 anos e o valor e média setorial de cada fundamental. Este
      ambiente não tem rede e a memória do modelo **não é fonte publicável**
      (skill `financial-analyst`); pré-preencher o URL da fonte seria forjar o
      rasto de auditoria. Um editor tem de abrir o *filing* de cada empresa,
      escrever os números, colar o URL e marcar *verified* — o gate de
      publicação recusa os cinco até lá, e a query do pool recusa-os outra vez
      mesmo que algum chegue a `publish`. **Até isso acontecer, deixar
      `reveal_enabled` desligado nas definições.**
- [ ] **Rever a cópia dos jogos** (EN+PT) com olhos editoriais antes de
      divulgar, e ler o painel de prontidão em Definições → HTI Games, que diz
      qual das duas frases de aterragem está viva e porquê.
- [ ] **Acessibilidade:** o teste cobre a marcação (radiogroup, roving
      tabindex, live regions, saída do replay animado); falta a passagem real
      com leitor de ecrã e teclado no browser.

⚠️ **Orçamento de assets quase esgotado:** 35,6 KB gzip para o Survive the
Charts contra um teto de 36,0, e 47,6 KB no total contra 48,0. A próxima
funcionalidade no jogo dos gráficos parte o `test-asset-budget.php`, e a
correção **não é subir o número** — é partir o `games-shared.js` (que hoje leva
os ecrãs de classificação e perfil para páginas que nunca os correm).

## Auditoria de 30 ago 2026 — o que ficou por fazer

O retrato completo, com evidência por `ficheiro:linha`, e a cronologia de setembro estão em
**`docs/Estado_e_Cronologia_Set2026.md`**. Os achados que mais custam:

- **11 dos 34 eventos de métrica são gravados e nunca mostrados** — entre eles `forex_bot_start/calc/stop` e
  `forex_tool_use`, exatamente os que medem o bot. Um terço da instrumentação escreve para o vazio.
- ~~**O `/forex/` pode emitir um URL de afiliado em cru**~~ — **resolvido** (hti-forex 0.13.8): o botão das
  ferramentas aponta para `/forex/go/{ferramenta}/`, o redirector próprio, e o `cta_url` deixou de estar ao
  alcance de quem desenha a página — o `cta_for()` devolve a *placement*, não o URL. Um teste falha se algum
  ficheiro fora do ecrã de definições e do redirector voltar a ler `cta_url`.
- **O mapa `cta` não tem teto de cardinalidade** e `POST /htinvest/v1/event` é público e aceita `location`
  arbitrário.
- ~~**O bot falha em silêncio**~~ — **resolvido** (hti-forex 0.12.5): o painel mostra o @username do bot, se o
  webhook registado no Telegram é o nosso, as atualizações à espera e **o último erro de entrega que o Telegram
  guardou**; mais o histórico das últimas dez difusões, a razão da última recusa, e as falhas de envio agrupadas
  por código.
- ~~**`hti-forex` não tem `uninstall.php`**~~ — **resolvido** (hti-forex 0.13.11, hti-engine 0.15.5, hti-social
  0.10.0): os quatro plugins limpam-se. O forex larga a tabela dos subscritores, o segredo do webhook e os três
  cron; o engine larga os `htinvest_profile` (em lotes, com orçamento de tempo), a tabela de feedback, o user
  meta e os contadores — e **não toca** no `learn`/`glossary`/`news`/`broker`, que é conteúdo do site.
- ~~**Acessibilidade:** o token de foco `#FF6B5E` dá 2,79:1…~~ — **resolvido** (tema 0.8.60, hti-engine 0.15.4,
  hti-forex 0.13.10): o anel de foco passou a ter token próprio (`--wp--custom--focus-ring` = `#D9432F`, ≥3,41:1
  em todas as superfícies da paleta), catorze `outline: none` saíram dos campos, o auto-avanço só dispara com
  toque ou rato — a seta do teclado deixou de saltar a pergunta — e o scroll respeita `prefers-reduced-motion`.
  `test-focus-contrast.php` recalcula os rácios a partir do `theme.json` a cada corrida.
- ~~**Auditoria de segurança (30 ago)**~~ — seis achados, todos corrigidos: teto nos dois mapas de métricas que
  um pedido anónimo podia fazer crescer; ffmpeg do `hti-social` verificado por SHA-256 e servido só da nossa
  origem (deixou de haver `<script>` para um CDN sem integrity); `composer.lock` versionado e `composer audit`
  na CI; `/tts` e `/caption` passam a exigir `publish_posts` e têm rate limit; HSTS de 5 minutos para 24 horas.
  Superfícies limpas com prova no relatório: 37 handlers `admin_post`, 23 rotas REST, SQL, chaves, escape.
- **A homepage diz "Seis perguntas curtas"** para um questionário de 8.
- **~479 strings `__()` sem tradução PT**, e os ficheiros `pt_PT` podem nem carregar num site `pt_PT_ao90`.

## Próximos passos sugeridos
1. Configurar RankMath (sitemap + schema + Search Console).
2. Configurar Brevo e testar o fluxo de registo/verificação.
3. Verificar 301s + HTTPS.
4. Enviar textos legais ao jurista (L-D).
5. Ativar o **HTI RSS AI Feed**, adicionar feeds e validar 1 geração ponta a ponta antes de a usar em produção.
6. **MCP WordPress** (criar/editar conteúdo por comandos): plano e estado em `docs/MCP_WordPress.md` — bloqueado por egress (precisa de ambiente novo) + WAF.
7. **`/games/` para staging**: deploy + ativar + semear + gerar cenários; validar um dia completo dos dois
   jogos no browser (teclado e leitor de ecrã incluídos) antes de abrir o Survive the Charts. The Reveal
   fica desligado até haver casos verificados.
