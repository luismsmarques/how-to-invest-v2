# Stack Concreta — HowToInvest MVP

**Companion do:** PRD (v3) + Modelo de Dados + Prompt LLM + Textos
**Data:** 18 de junho de 2026
**Estado:** especificação de stack para desenvolvimento

## Decisões fechadas (registo)

| Decisão | Escolha |
|---|---|
| Tema | Block theme nativo (Full Site Editing) |
| App interativa | Plugin custom próprio, frontend JS ligeiro (sem React) |
| Hosting | cPanel próprio do cliente (partilhado/auto-gerido) |
| Provider LLM | Google Gemini (JSON mode) |
| Desenvolvimento | Equipa própria, confortável com código |
| Idioma | EN default + PT |

> **Implicação do cPanel:** não havendo hosting gerido, performance, cache, backups e segurança passam a ser responsabilidade tua. Estão abaixo como itens concretos, não assumidos.

---

## 1. Camada de servidor (verificar no cPanel)

Alvos recomendados (2026), não mínimos:

| Componente | Alvo | Nota |
|---|---|---|
| PHP | **8.4** (ou 8.3) | 8.4 é a escolha sólida e madura de 2026; evitar EOL (≤8.1). Selecionável no cPanel (PHP Manager / MultiPHP). |
| WordPress | **7.0+** | Versão atual (saiu maio 2026). |
| Base de dados | MySQL **8.0+** ou MariaDB **10.6+** | Piso recomendado oficial. |
| Servidor web | Apache (com mod_rewrite) ou Nginx | cPanel é tipicamente Apache + mod_rewrite. |
| HTTPS | Obrigatório | Let's Encrypt via cPanel (AutoSSL). |

**php.ini (ajustar no cPanel se necessário):**
- `memory_limit` ≥ 256M
- `max_execution_time` ≥ 60s (chamada ao Gemini + geração de PDF)
- `upload_max_filesize` / `post_max_size` — confortáveis para uploads de imagens de artigos
- `max_input_vars` ≥ 5000

*Verificar em wp-admin → Tools → Site Health → Info depois de instalar.*

---

## 2. Tema

- **Block theme nativo** (FSE). Opção: partir de um tema base oficial leve (ex.: a família Twenty Twenty-X atual) e criar um **child theme** para customizações, mantendo updates seguros.
- Sem page builders pesados (Elementor/Divi) — contrariam o objetivo de performance/SEO e o tom "limpo" escolhido.
- Customização via `theme.json` (tokens de cor, tipografia, espaçamento) — alinha com o passo de design no Claude Design.
- Templates FSE para: home, single post, arquivo, página de termo de glossário, página de notícia.

---

## 3. Plugins (mínimo viável, sem excesso)

Princípio: cada plugin é peso e superfície de ataque. Só o essencial.

| Função | Plugin sugerido | Prioridade | Nota |
|---|---|---|---|
| SEO | RankMath **ou** Yoast | P0 | Schema, sitemaps, metas. Escolher um. |
| Motor + app | **Plugin custom próprio** (`hti-engine`) | P0 | Não é de terceiros — é o teu produto. |
| Consentimento RGPD | Plugin de consentimento (ex.: Complianz) | P0 | Recusa de não-essenciais por omissão. |
| Cache | Plugin de cache (ex.: WP Super Cache / W3TC / LiteSpeed se o cPanel for LiteSpeed) | P0 | Em cPanel a cache é tua responsabilidade. |
| Backups | Plugin de backup (ex.: UpdraftPlus) p/ destino externo | P0 | cPanel partilhado: backup externo é essencial. |
| Segurança | Plugin de hardening (ex.: Wordfence/Solid Security) | P1 | Login throttling, firewall aplicacional. |
| PDF | Biblioteca PHP no plugin custom (ex.: Dompdf/mPDF) | P0 | Export do resultado; não precisa de plugin externo. |
| Login Google | Via plugin OAuth ou implementado no `hti-engine` | P0 | Parte do req. 6.8. |

*Multi-idioma EN+PT: avaliar plugin (Polylang) vs. abordagem nativa. Como o modelo de dados já é language-aware, decidir na implementação se o conteúdo editorial usa Polylang ou estrutura própria. Registar como detalhe a fechar.*

---

## 4. O plugin custom `hti-engine` (o teu produto)

Estrutura recomendada:

```
hti-engine/
├── hti-engine.php          # bootstrap, hooks
├── includes/
│   ├── class-cpt.php        # regista CPT htinvest_profile (privado)
│   ├── class-rest.php       # endpoints /recommend, /claim-profile,
│   │                        #   /my-profiles, /account, /export
│   ├── class-engine.php     # regras determinísticas (pontuação→arquétipo→alocação)
│   ├── class-gemini.php     # chamada server-side ao Gemini + validação schema
│   ├── class-fallback.php   # textos pré-escritos por arquétipo/idioma
│   ├── class-pdf.php        # geração do PDF do resultado
│   └── class-settings.php   # página admin: chave API, modelo, arquétipos, scoring
├── assets/
│   ├── js/questionnaire.js  # frontend JS ligeiro (multi-step, estado de sessão)
│   ├── js/result.js         # render do gráfico (lib de chart leve) + ações
│   └── css/                 # estilos do app
└── languages/               # EN, PT
```

**Frontend JS ligeiro:** questionário multi-step e render do resultado em JS vanilla (ou uma micro-lib). Gráfico de alocação com uma biblioteca de charts leve carregada só na página de resultado. Sem build pesado de React — alinhado com a tua decisão.

**Segurança das chamadas:** chave do Gemini guardada server-side (nas settings, idealmente referenciada via `wp-config.php`/variável de ambiente, não em texto no admin se possível). Endpoints REST protegidos por nonce. A chave **nunca** vai para o JS do cliente.

---

## 5. Ambientes (dev → staging → produção)

Confortável com código → fluxo profissional recomendado:

- **Local:** ambiente de desenvolvimento local (ex.: LocalWP, ou Docker com a mesma versão de PHP/MySQL do cPanel — paridade é importante).
- **Staging:** subdomínio no cPanel (ex.: `staging.howtoinvest.pro`) ou pasta separada, com `noindex` e proteção por password. Onde testas antes de produção.
- **Produção:** o domínio principal.
- **Controlo de versões:** Git para o `hti-engine` (e child theme). O core e plugins de terceiros não vão para o repo; versiona o teu código.
- **Deploy:** via Git + SSH no cPanel (se disponível) ou deploy controlado por ficheiros. Evitar editar em produção.

---

## 6. Performance e SEO (responsabilidade tua em cPanel)

- Cache de página ativa (ver §3).
- CDN à frente do site (ex.: Cloudflare gratuito) — melhora velocidade global e dá camada extra de segurança/SSL.
- Imagens otimizadas (WebP, lazy-load — o WP moderno já faz lazy-load nativo).
- Core Web Vitals monitorizados (Search Console).
- Sitemaps e schema via plugin SEO; `noindex` em questionário/resultado/staging.

---

## 7. Monitorização e operação

- **Backups automáticos** para destino externo (não confiar só no cPanel).
- **Uptime monitoring** (ex.: serviço gratuito de ping).
- **Logs do motor** sem dados pessoais (ver Modelo de Dados §8).
- **Custo Gemini:** monitorizar uso de tokens por recomendação (barato, mas observar picos).
- Atualizações de WP/PHP/plugins testadas em staging antes de produção.

---

## 8. Custos estimados (ordem de grandeza, mensal)

| Item | Estimativa |
|---|---|
| Hosting cPanel | já tens |
| Domínio | já tens (howtoinvest.pro) |
| CDN (Cloudflare free) | 0€ |
| Gemini API | baixo (poucos cêntimos a poucos euros, conforme volume) |
| Plugins premium (opcional: SEO/segurança) | 0–~20€ conforme escolhas |

Stack deliberadamente enxuta e barata, alinhada com o objetivo.

---

## 9. Riscos da escolha cPanel (transparência)

- **Escala:** cPanel partilhado pode sofrer em picos de tráfego. Se o SEO funcionar e o tráfego crescer, prever migração para VPS/gerido.
- **Operação manual:** cache, backups e segurança dependem de ti — daí os plugins P0 acima.
- **Paridade de ambiente:** garantir que local/staging usam a mesma versão de PHP/MySQL do cPanel para evitar surpresas no deploy.

---

## 10. O que falta a seguir (via completa)

Fechado: ✅ dados+API · ✅ prompt+schema · ✅ textos · ✅ stack concreta.
Falta **um** artefacto para fechar a via completa:
- **Critérios de "pronto" + plano de QA** — consolida tudo num checklist de aceitação e lançamento (acessibilidade, performance, edge cases do motor, RGPD, SEO).

Detalhes a fechar na implementação: escolha SEO (RankMath vs Yoast), abordagem multi-idioma (Polylang vs nativo), e gestão da chave Gemini (env var vs settings).
