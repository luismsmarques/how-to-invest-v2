# STATUS — HowToInvest (handoff)

_Última atualização: 18 jun 2026. Lê isto primeiro ao retomar/numa sessão nova._

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

**Idioma:** **EN por default** (regra do projeto). Os templates de bloco do tema têm
texto fixo em EN (o FSE não permite `__()` no HTML); os *patterns* usam `__()` (EN+PT)
e o app do plugin é EN-default com PT via `ui()`. O **PT** é servido pelo **Polylang**
(língua adicional `pt_PT_ao90`) — ver secção *Multilíngue* abaixo.

## Arquitetura (resumo)
- **Tema** `wp-content/themes/howtoinvest` (FSE, tokens em `theme.json`, design coral/cream, disclaimer no rodapé).
- **Plugin** `wp-content/plugins/hti-engine` (o produto). Regra de ouro: **as regras decidem, o LLM só explica**.
  - Motor determinístico (`class-engine`) + config curada (`class-config`) → arquétipo + alocação por classe (soma 100), 3 travas.
  - LLM: `class-llm` (transporte: **WP 7.0 AI Client / Connectors** → fallback `class-gemini`) · `class-prompt` · `class-validator` · `class-fallback` · `class-explainer`.
  - REST `htinvest/v1`: recommend, result, register, login, claim-profile, my-profiles, export, account.
  - Frontend (JS vanilla): `questionnaire.js`, `result.js` (donut conic-gradient), `account.js`, `consent.js`, `analytics.js`.
  - Segurança/RGPD: rate limit (`class-rate-limit`), verificação email double opt-in via **Brevo** (`class-verification`+`class-mailer`), consentimento (`class-consent`), GA gated, cron de limpeza (`class-cron`), login Google (`class-google`).
  - Admin: `class-settings` (Definições → HowToInvest). PDF: `class-pdf` (Dompdf, fallback HTML).
- Detalhe por ficheiro: `wp-content/plugins/hti-engine/README.md`.

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
- O **seeder cria o PT** de cada entrada (glossário/páginas/artigos) a partir das
  variantes `hti_*_pt`, define o idioma, partilha o slug EN e **liga EN↔PT**
  (`pll_save_post_translations`). Traduz/liga também o topic `glossary_topic`
  (*Asset classes → Classes de ativos*). Idempotente; sem Polylang degrada para EN+meta.
- Os **links internos** dos artigos PT são reescritos para o **permalink PT** do glossário
  (via `pll_get_post`/`get_permalink`) — robusto mesmo que traduzas os slugs no futuro.
- Correr depois de cada deploy que mude o seed: **Ferramentas → Semear conteúdo → Run seeder**
  (ou `wp hti seed`). O aviso mostra quantas traduções PT foram ligadas.

## Deploy
- Branches: **`main`** = produção · **`develop`** = staging/integração · feature → PR para `develop` → release `develop → main`. Ver `CONTRIBUTING.md`.
- cPanel Git: `Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit`. O `.cpanel.yml` (simples; destino fixo `howtoinvest.pro/wp-content`) copia tema+plugin.
- **Se o deploy do cPanel falhar/pendurar:** ver `DEPLOY.md §5.1` (deploy manual / File Manager copy a partir de `repositories/how-to-invest-v2/wp-content/...`).
- Testes: `for t in engine settings explainer prompt ratelimit cron mailer google llm; do php wp-content/plugins/hti-engine/tests/test-$t.php; done`

## O que falta para o GO-LIVE público (checklist completa: `docs/QA_Gate_Lancamento.md`)
**Código:** ✅ tudo (lacunas L-A/L-B/L-C fechadas).

**Operacional (teu, no servidor):**
- [ ] HTTPS forçado (redirect http→https em todo o site)
- [ ] Verificar os 8 redirects 301 do Base44 (ex.: `/About` → `/about/`)
- [ ] Backups externos automáticos **e restauro testado**
- [ ] Cache (LiteSpeed/WP) + CDN (Cloudflare) + Core Web Vitals
- [ ] **RankMath**: instalar/ativar → sitemap inclui `glossary`/`news` → submeter ao Search Console
- [ ] Configurar **Brevo** (senão o registo de contas não confirma)
- [ ] **Polylang**: atribuir idioma a todo o conteúdo + correr o seeder → confirmar ligações EN↔PT (e `hreflang` no sitemap)
- [ ] Acessibilidade: contraste AA + teste com leitor de ecrã

**Legal (⚠️ bloqueador antes de divulgar):**
- [ ] **L-D — Revisão jurídica** dos disclaimers + páginas privacidade/termos (são placeholders; mencionar o GA na política de privacidade).

## Próximos passos sugeridos
1. Configurar RankMath (sitemap + schema + Search Console).
2. Configurar Brevo e testar o fluxo de registo/verificação.
3. Verificar 301s + HTTPS.
4. Enviar textos legais ao jurista (L-D).
