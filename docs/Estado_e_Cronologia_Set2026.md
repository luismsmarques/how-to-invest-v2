# Estado do projeto e cronologia — setembro 2026

_Auditoria de 30 ago 2026, feita contra o código e não contra a documentação.
Cada achado tem `ficheiro:linha`. A cronologia que se segue decorre dela._

## Porquê agora

Pela primeira vez o projeto tem tração: o canal de Telegram passou de zero a
~1.000 pessoas em dias, o bot tem **915 subscritores alcançáveis**, e já se
gastou dinheiro em campanhas. O que não existe é **uma única conversão
provada** — nem um clique de afiliado atribuído, nem um custo por utilizador
calculado.

Decisões tomadas para o mês:

| Decisão | Escolha | Consequência |
|---|---|---|
| Foco dominante | **Provar receita** | SEO, PT e go-live passam a segundo plano |
| Público do conteúdo | **Índia / EN** | O PT existente mantém-se, não cresce |
| Orçamento pago | **100–300 €** | Duas rondas: teste e vencedor |
| Tempo do dono | **>10 h/semana** | Cabe operação contínua na fila dele |

---

## 1. A documentação descreve um produto mais pequeno do que o que existe

| | Real | STATUS.md dizia |
|---|---|---|
| hti-engine | **0.15.0** | 0.7.0 |
| hti-forex | **0.12.4** | 0.9.0 |
| hti-rss-ai | **1.11.1** | 1.5.0 |
| tema | **0.8.58** | 0.6.9 |
| Testes | **~1.770 asserções** | "167 testes" |
| Glossário | **58 termos** | 42 |
| Learn | **35 capítulos** | 8 artigos |
| Plugins no deploy | **4** | 3 (e contradizia-se) |

Omitia por completo secções que estão **em produção**: o CPT `broker` e as ~26
páginas de corretoras, o redirector `/go/`, o comparador de depósitos, o ebook,
o widget de feedback, o pipeline `.md` de conteúdo. O `START_HERE.md` ainda diz
"Código: nada construído ainda".

## 2. A saúde técnica é boa

- **~1.770 asserções verdes, 0 falhas** (engine 1.072 · forex 527+83 ·
  rss-ai 60 · tools-core 27). `php -l` limpo em PHP 8.4.
- **Zero TODO/FIXME, zero classes órfãs, zero assets não enfileirados.**
- Invariantes com guarda executável: a alocação lança `RuntimeException` se não
  somar 100 (`class-engine.php:241-249`); o validador limita o LLM a três chaves
  e rejeita percentagens estranhas (`class-validator.php:23,159`); RGPD com
  erasure em cascata real (`class-account.php:750-787`); **zero chaves de API em
  qualquer JS ou HTML**.

## 3. O que está partido

**A difusão do bot — corrigido em 30 ago (hti-forex 0.12.4).**
`Bot_Broadcast::status()` não devolvia a chave `image` que `run()` lê, o que
lançava um `TypeError` no primeiro destinatário do primeiro lote, antes de
gravar progresso e antes de agendar o tick seguinte. Nada era entregue e o
estado ficava a dizer "a enviar" para sempre, recusando as difusões seguintes.
Há agora um teste que exige que toda a chave usada seja uma chave declarada,
mais um sinal de vida e uma guarda que liberta um envio morto.

**Um terço da instrumentação escreve para o vazio.** 11 dos 34 eventos são
gravados e nunca mostrados — entre eles `forex_bot_start`, `forex_bot_calc`,
`forex_bot_stop` e `forex_tool_use`, exatamente os que medem o objetivo do mês
(`class-metrics.php:72-77` só os declara; são emitidos em `class-bot.php:137` e
`forex.js:297`). O `location` do `forex_tool_use` é descartado: o desdobramento
só existe para `cta_click` (`class-metrics.php:197`).

**O `/forex/` podia emitir um URL de afiliado em cru — resolvido em 30 ago
(hti-forex 0.13.8).** O `href` do botão passou a ser `/forex/go/{ferramenta}/`,
o mesmo redirector que o PDF já usava: o `cta_for()` devolve a *placement* e
nunca o URL, portanto quem desenha a página não tem sequer o que imprimir. O id
de campanha, que o browser escrevia em cima do `href` do afiliado, viaja agora
como `cid` no nosso próprio URL e é reposto no destino do lado do servidor — o
painel do afiliado vê exatamente o que via antes. Um teste enumera os ficheiros
que leem `cta_url` e falha se aparecer um terceiro.

**O mapa `cta` não tem teto de cardinalidade** (`class-metrics.php:197-200`) e
`POST /htinvest/v1/event` é público, sem nonce, e aceita `location` arbitrário.

**O bot falhava em silêncio — resolvido em 30 ago (hti-forex 0.12.5).** Não
havia um `error_log` em `includes/`, o retorno de `Telegram::send()` era
descartado em 6 sítios, e `Telegram::username()` existia "for the settings
screen" sem nunca ser chamado. O painel passa a mostrar o estado do webhook
vindo do próprio Telegram (incluindo o último erro de entrega que ele guarda),
o histórico das últimas dez difusões, a razão da última recusa, e as falhas de
envio agrupadas por código.

**`hti-forex` não tem `uninstall.php`** — desinstalar deixa chat_ids e o
segredo do webhook na base de dados.

**`hti-social` é o único artefacto deployado sem verificação nenhuma** — sem
testes, sem lint no CI, e o output do Gemini (copy pública) não passa por
`HTI\Engine\Validator`.

**O `vendor/` é destruído em todos os deploys.** `.cpanel.yml:15-16` faz
`rm -rf` + `cp -R` e depende do `composer install` protegido por `|| true`; se
falhar, o deploy fica verde e o export PDF degrada para HTML. O `DEPLOY.md:123`
afirma que há um rsync com `--exclude vendor/` — não há.

## 4. Acessibilidade

- **Auto-avanço parte a navegação por teclado (WCAG 3.2.2):**
  `questionnaire.js:157` avança 320 ms após `change`.
- **O foco falha contraste em todo o site (WCAG 1.4.11):** `primary #FF6B5E` dá
  **2,79:1** (mínimo 3:1). Um token, o site inteiro.
- `outline: none` em 4 inputs, incluindo o de eliminação de conta
  (`app.css:750, 844, 1086, 1147`).
- Botões no hover: **3,17:1**, menos legíveis que no estado normal.

## 5. Conteúdo, i18n e legal

- **53 dos 58 termos usam `topic: key-terms`** e `key-terms` não existe em
  `glossary_topics()` (`class-seeder.php:1314-1326`) → 16 termos ficam fora de
  todos os arquivos de tópico.
- **11 termos e 8 capítulos Learn sem link interno de entrada.** O
  `test-seo-structure.php` exige ≥3 links de *saída* e nenhuma regra de entrada.
- **~479 strings `__()` sem tradução PT**, incluindo 23 mensagens de erro
  voltadas ao visitante. Os ficheiros chamam-se `pt_PT` e o site corre
  `pt_PT_ao90` — o WordPress não faz fallback.
- **O link de privacidade do banner está fixo em EN** (`class-consent.php:84`).
- **A homepage diz "Seis perguntas curtas"** (`functions.php:396`, `:1310`) para
  um questionário de **8**.
- **As páginas legais já não são placeholders** — faltam marcadores `[●]` e a
  revisão jurídica.
- **O gate "Corretoras & afiliados" está 0/9** com a secção em produção.

---

## Cronologia

O princípio: há 915 pessoas e zero conversões provadas. Construir mais antes de
fechar o circuito de medição é comprar audiência com o contador desligado. As
duas primeiras semanas desbloqueiam e medem; a terceira constrói.

### Semana 1 (1–5 Set) · Desbloquear

**Desenvolvimento**
- ✅ Corrigir a difusão (feito, 0.12.4). Reenviar a mensagem à XM.
- Dar ecrã aos 11 eventos órfãos, a começar por `forex_bot_*` e
  `forex_tool_use`. Fazer o `location` do `forex_tool_use` contar.
- Enviar os commits parados na branch por `develop` → `main`.
- Repor a verdade no `STATUS.md`; apagar o `PUSH_INSTRUCTIONS.md`.

**Config (dono)**
- Criar `/go/xm-demo` e `/go/open-account-xm`; ligar `cta_enabled` e
  `bot_ad_enabled` — o diagnóstico de 4 condições diz qual falta.
- ✅ `cta_url` já não sai da máquina (hti-forex 0.13.8): pode continuar a ser o
  URL de afiliado em cru, porque só o redirector o segue. **Confirmar** que
  `/forex/go/position-size/` aterra na XM depois do deploy.
- Depois do deploy, confirmar que o export PDF ainda sai em PDF.

**Atribuição de ponta a ponta — feito em 30 ago** (hti-engine 0.15.3,
hti-forex 0.13.9). O `/go/{slug}` não reencaminhava query string nenhuma:
o id de campanha morria no salto e a corretora via todos os cliques sem
atribuição — incluindo os que vinham do bot, que é onde o dinheiro dos
anúncios foi. Agora o `/go/` reencaminha um `cid` como sub-id da rede (nome do
parâmetro por corretora, vazio → não envia nada), o bot grava a campanha de
cada pessoa na sua linha (primeiro toque, nunca sobrescrito) e cola-a no link
do parceiro. A cadeia fecha: anúncio → `/start b2` → resposta do bot →
`/go/open-xm/?cid=b2` → painel da corretora com `b2` ao lado da conta aberta.

**Marketing**
- Sondagens de aquecimento no canal.
- **Puxar o gasto por criativo da Propeller** — sem isso, `tg_b2 452 /
  tg_a1 248 / tg_c1 242` não significa nada.

**Conteúdo** — calendário do canal, 1 a 4 Set.

### Semana 2 (8–12 Set) · Provar a conversão

**Desenvolvimento**
- ✅ Prender o CTA do `/forex/` ao host próprio (feito, hti-forex 0.13.8).
- ✅ Teto de cardinalidade no `cta` (feito, hti-engine 0.15.1) + allowlist de
  `location` no endpoint público.
- ✅ Estado do webhook no painel + registo das falhas (feito, hti-forex 0.12.5 —
  subiu de prioridade porque uma difusão que não saiu custou três rondas de
  perguntas para se perceber porquê).

**Marketing**
- Revelar o bot no canal, depois das sondagens.
- Ler os cliques por `telegram_bot_demo` / `telegram_bot_real`.
- Ronda 1 da Propeller (~100 €): `a1`, `b2`, `c1`, tetos iguais, formato nativo
  de Telegram Mini Apps primeiro.

**Conteúdo**
- 8–11 Set + sondagem de 11 Set.
- Marcar o link do canal com `?start=canal` e o CTA do site com `?start=site`.

### Semana 3 (15–19 Set) · A primeira funcionalidade que vende

Uma das duas, decidida pelos dados da semana 2:
- **Calculadora de custo total de funding em ₹** (UPI/IMPS, markup de conversão,
  levantamento) — do backlog do plugin, precede a decisão de abrir conta.
- **Ou a resposta de seguimento do bot** — hoje a conversa acaba no momento de
  maior atenção do funil.

Mais: `uninstall.php` no `hti-forex`; ronda 2 da Propeller no vencedor,
por **custo por utilizador do bot**; calendário 14–18 Set.

### Semana 4 (22–30 Set) · Consolidar

- CI: lint do `hti-social` e suites Node.
- Validador de invariantes no output do Gemini do `hti-social`.
- Acessibilidade: trocar o token do foco, remover os 4 `outline:none`,
  condicionar auto-avanço e `scrollIntoView`.
- Correções de credibilidade: "6 perguntas" → 8; link de privacidade por idioma;
  registar `key-terms` em `glossary_topics()`.
- Matriz do motor: ESG para dentro do array (11 → 12 cenários).
- Conteúdo: 21–25 Set, escrever 28 Set–2 Out, 2–3 artigos EN para a Índia.
- Relatório do mês.

## Fora do mês, deliberadamente

SEO em PT e as ~479 strings por traduzir, go-live (HTTPS forçado, backups
testados, RankMath, Search Console), `llms.txt` e allowlist de crawlers de IA,
reels, MCP WordPress, base dos slugs de CPT em PT, malha de links de entrada.

**Uma exceção que não devia ser adiada.** A revisão jurídica (L-D) está marcada
como "bloqueador antes de divulgar" e já se está a divulgar. O âmbito real é
maior do que os documentos assumem: cobre a camada de afiliação (gate 0/9 com
26 páginas em produção), o `ads.txt` com publisher id real sem categoria de
marketing no banner, e a exposição RBI/FEMA do `/forex/`. Está registado que a
**XM aparece na Alert List do RBI** segundo duas fontes secundárias, ainda não
verificadas contra o PDF do próprio RBI — verificar na fonte primária é a ação
mínima que reduz exposição sem travar nada.

_(O pixel da Propeller sem gate de consentimento é deliberado e sai quando o
teste acabar.)_
