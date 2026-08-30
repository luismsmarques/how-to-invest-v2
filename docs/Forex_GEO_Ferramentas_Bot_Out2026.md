# Expansão GEO das ferramentas de forex e do bot — Out–Dez 2026

_Plano de produto e engenharia derivado de `Plano_Ferramentas_Bot_Forex.docx` (28 ago 2026),
escrito contra o código e não contra a documentação. Cada afirmação sobre o estado atual tem
`ficheiro:linha`. O que não foi possível verificar nesta sessão está marcado **A VERIFICAR**,
com o comando ou a fonte primária — nunca como facto._

**Precede este documento:** `Estado_e_Cronologia_Set2026.md` (o mês de setembro, que este
plano assume concluído). **Complementa:** `Propeller_Campanhas_Bot_Telegram.md` (os criativos
e os códigos de campanha, que continuam válidos).

---

## 1. Porquê, e o que já existe

O `.docx` propõe transformar `/forex/` numa suite multi-GEO de calculadoras que funcionam
como iscos SEO de alta intenção, ligadas a um bot de Telegram que fecha o funil até ao
registo de afiliado. Metade da proposta já está construída e em produção.

| O que o `.docx` pede | Estado |
|---|---|
| Position/lot size calculator | ✅ `class-tools.php`, com extensão de margem/leverage |
| Pip calculator por ativo | ✅ EURUSD, GBPUSD, USDJPY, XAUUSD, USDINR (`class-config.php:33`) |
| Profit / P&L calculator | ✅ página `profit-calculator` (`class-seeder.php:319`) |
| Market hours / session clock | ✅ em IST (`class-config.php:115`) |
| Margin calculator autónomo | ❌ existe embutido no position size, não como ferramenta |
| Leverage calculator autónomo | ❌ idem |
| Compounding calculator | ❌ |
| Páginas por ativo (ouro primeiro) | ✅ parcial: `xauusd-lot-size-calculator` (`:333`) |
| Localização por moeda/GEO | ❌ **é o âmago deste plano** — por seletor, não por página (§6.1) |
| Bot de Telegram com CTA de afiliado | ✅ ~915 subscritores; CTA global, não por país |
| Telegram Mini App | ❌ **fora de âmbito** (decisão de 30 ago — ver §2.2) |
| Nurturing de 7 dias | ❌ existe difusão manual (`class-bot-broadcast.php`), não sequência |
| Calendário económico | ❌ fora de âmbito (ver §4) |

**O trabalho real não é acrescentar — é abstrair o INR**, que está entranhado em cinco
sítios: `Config::session_windows_ist()` fixa `Asia/Kolkata` (`class-config.php:116,144`),
`Config::pairs()` inclui `USDINR` (`:59`), `Bot_Math::inr()` faz agrupamento indiano
(`class-bot-math.php:398`), `parse_amount()` conhece `lakh`/`crore` (`:150`), e o `forex.js`
instancia `Intl.NumberFormat('en-IN', … 'INR')` em três constantes de módulo
(`forex.js:25-28`).

E falta uma **matriz de corretoras por país**: hoje há um único `cta_url` global para toda a
secção (`class-settings.php:57`), com um único `sub_param` (`:78`).

---

## 2. Decisões tomadas

Decisões do dono, tomadas em 30 ago 2026. Ficam aqui como decisões, não como opções.

| | Escolha |
|---|---|
| **Corretoras** | **XM na Índia**, **Exness nas restantes oito GEOs** |
| **GEOs** | As nove da tabela §2 do `.docx` |
| **Idiomas** | EN + vi, th, id, pt-BR |
| **Câmbios** | Custo zero: grátis onde existe, peg e override manual onde não existe |
| **URLs** | **Uma página por ferramenta, com seletor de moeda** — nenhuma página por GEO (§6.1) |
| **Bot** | Moeda + CTA por país + nurturing de 7 dias. **Sem Mini App** (§2.2) |
| **Calendário** | Out–Dez 2026 |

### 2.1 Duas leituras do documento de origem

**Só Telegram.** O §5.1 do `.docx` diz "APENAS Telegram nesta fase — sem WhatsApp/LINE/Zalo";
o item 5 do roadmap (§6) do mesmo documento diz "Bot WhatsApp (África/Brasil/Índia/SEA) +
Telegram (MENA)". São incompatíveis. Assume-se o §5.1, que é a secção que argumenta a
decisão; o item do roadmap lê-se como resíduo de uma versão anterior. WhatsApp fica para uma
fase 2 fora deste plano.

**Filipinas fora.** Aparece nas GEOs prioritárias do §8 mas não na tabela de nove moedas do
§2. Fica registada como a candidata mais barata a uma décima GEO — anglófona, e o PHP não
acrescenta custo de câmbios — mas fora do âmbito deste plano.

### 2.2 Mini App: fora de âmbito

**Decisão do dono, 30 ago 2026: o Telegram Mini App sai do plano.** O `.docx` §5.1 e §5.4
pedem-no — a calculadora a correr dentro do chat — e continua a ser a peça de maior fricção
removida do funil. Sai na mesma, e com ela sai a única exceção de segurança que o plano
tinha (um `<script>` de terceiros vindo de `telegram.org`, sem alternativa técnica).

**O que isto não afeta:** o formato **Telegram Mini Apps Ads** da PropellerAds, que
`Propeller_Campanhas_Bot_Telegram.md §4.1` marca como prioridade máxima. É uma *colocação
dentro de mini-apps de terceiros*, com destino `t.me/bot?start=código` — não exige que
tenhamos um Mini App nosso. O canal de anúncios fica intacto.

**O que se perde:** quem vem de um anúncio dentro do Telegram continua a sair para o
browser se quiser a calculadora completa. O bot responde no chat, que é o essencial;
o Mini App era a diferença entre responder e deixar mexer.

### 2.3 As nove GEOs

| GEO | Moeda | Corretora | Fonte de câmbio | Fuso | Idioma | CTA à nascença |
|---|---|---|---|---|---|---|
| Índia | INR ₹ | XM | Frankfurter | Asia/Kolkata (IST) | EN | ligado (já está) |
| Nigéria | NGN ₦ | Exness | **manual** | Africa/Lagos (WAT) | EN | ligado |
| África do Sul | ZAR R | Exness | Frankfurter | Africa/Johannesburg (SAST) | EN | ligado |
| UAE | AED د.إ | Exness | **peg** | Asia/Dubai (GST) | EN | ligado |
| Malásia | MYR RM | Exness | Frankfurter | Asia/Kuala_Lumpur (MYT) | EN | ligado |
| Vietname | VND ₫ | Exness | **manual** | Asia/Ho_Chi_Minh (ICT) | EN + vi | **desligado** (§5) |
| Tailândia | THB ฿ | Exness | Frankfurter | Asia/Bangkok (ICT) | EN + th | **desligado** (§5) |
| Indonésia | IDR Rp | Exness | Frankfurter | Asia/Jakarta (WIB) | EN + id | **desligado** (§5) |
| Brasil | BRL R$ | Exness | Frankfurter | America/Sao_Paulo (BRT) | EN + pt-BR | ligado |

---

## 3. Motor de GEO

### 3.1 `includes/class-geo.php` (novo)

Uma tabela pura, sem dependências de WordPress além do guard de `ABSPATH`, no espírito
exato do `class-config.php` — testável sem WordPress e fonte única para as páginas, o bot,
o schema e o seeder.

Uma linha por GEO, com: código ISO, slug de URL, moeda, símbolo, **agrupamento de dígitos**
(`indian` | `western`), **casas decimais**, locale de formatação, fuso IANA e etiqueta,
idiomas, regulador local, corretora, e estado do CTA.

Dois detalhes que partem código ingénuo e por isso vivem na tabela, não em condicionais
espalhadas:

- **VND e IDR não têm subunidade em uso.** Zero casas decimais. Um `number_format($v, 2)`
  produz `₫1.250.000,00`, que nenhum vietnamita escreve.
- **A ordem de grandeza muda três casas.** Uma conta típica são ₹50.000, R$500, ₦800.000 ou
  ₫12.000.000. Os escalões de `Bot_Math::buckets()` (`class-bot-math.php:60`) estão fixados
  em rupias e têm de passar a ser derivados de uma escala em USD e apresentados na moeda
  local, ou o histograma de saldos do painel deixa de dizer nada.

### 3.2 Generalizações, preservando compatibilidade

| Hoje | Passa a | Compatibilidade |
|---|---|---|
| `Config::session_windows_ist( $day )` (`:115`) | `session_windows( $day, $tz )` | `_ist()` fica como wrapper fino |
| `Config::overlap_london_ny_ist( $day )` (`:143`) | `overlap_london_ny( $day, $tz )` | idem |
| `Bot_Math::inr( $v, $places )` (`:398`) | despacho por agrupamento | `inr()` fica a delegar |
| `Bot_Math::plain()` (`:424`) | já serve o agrupamento ocidental | intocado |
| `Bot_Math::parse_amount( $raw, $usd_inr )` (`:133`) | recebe o perfil de GEO | assinatura nova, chamadores atualizados |
| `forex.js:25-28` (`en-IN`/`INR` fixos) | locale e moeda de `data-` da página | — |

Os wrappers não são cortesia: as oito páginas atuais e a suite de 527 asserções chamam as
funções `_ist()`. Mantê-las é o que permite fazer esta abstração **sem tocar numa única
página em produção**.

**Parser de montantes por locale.** O `parse_amount()` aceita hoje `5000`, `₹5,000`,
`Rs 5000`, `1,00,000`, `50k`, `2 lakh` e `$100`. Acrescenta-se por GEO: `lakh`/`crore` (IN,
já lá está), `triệu`/`tỷ` (VN, milhões e milhares de milhões), `juta` (ID), `ribu` (ID,
milhares), e `k`/`m` em todas. **A VERIFICAR** com um falante antes de publicar: a grafia
com e sem diacríticos que um teclado móvel produz de facto.

---

## 4. Câmbios a custo zero

### 4.1 O que existe

`class-rates.php` faz um `GET` a `api.frankfurter.dev` com `symbols=INR,JPY,EUR,GBP`
(`:27`), duas vezes por dia, valida contra limites de plausibilidade por símbolo (`:33`),
exige `USDINR` e `USDJPY` para aceitar o payload (`:46`), e guarda numa opção com
precedência **override do admin > obtido > fallback embarcado** (`:65`), marcando `stale`
ao fim de sete dias (`:72,201`).

O desenho já é o certo. Falta-lhe cardinalidade.

### 4.2 O que muda

As quatro constantes paralelas (`BOUNDS`, `REQUIRED`, `SYMBOLS`, `FALLBACK`) passam a **um
registo por moeda**, com a fonte declarada:

- **`frankfurter`** — as moedas que o BCE publica.
- **`peg`** — **AED**. O dirham está fixado ao dólar a 3,6725 desde 1997 pelo banco central
  dos EAU. Tratá-lo como constante é correto, não uma aproximação; a página di-lo.
- **`manual`** — **NGN** e **VND**. Override do admin, com data visível e aviso no painel
  quando passa de 30 dias.

O `REQUIRED` guarda a lição já aprendida e comentada no próprio ficheiro (`:41-45`): só
INR/JPY invalidam um payload. **Uma moeda nova em falta nunca pode partir os cálculos de
todo o site.**

### 4.3 O risco, dito por inteiro

**O NGN é o único risco real.** Flutua desde a liberalização de 2023 e um valor manual de
três meses pode estar errado em dezenas de pontos percentuais — o que produz números
confiantes e falsos numa calculadora cuja proposta de valor é ser honesta. Três mitigações,
todas já suportadas pela arquitetura:

1. O rate é **sempre um input editável na própria página**, com a data ao lado — é assim que
   funciona hoje para o INR e é o que impede que uma API morta parta a página.
2. Aviso no painel de administração aos 30 dias.
3. Se o desvio se provar caro, a saída é um adaptador de fonte paga — o registo por moeda
   torna isso uma linha de configuração, não uma reescrita.

### 4.4 A VERIFICAR antes de construir

O proxy desta sessão bloqueou a saída (`CONNECT tunnel failed, 403`), pelo que **não foi
possível confirmar** quais das nove moedas o Frankfurter serve. Um comando resolve:

```sh
curl -s https://api.frankfurter.dev/v1/currencies
```

O desenho é robusto ao resultado: qualquer moeda ausente da lista cai em `manual` sem
alterar mais nada. A tabela do §2.3 assume INR, ZAR, MYR, THB, BRL e IDR disponíveis e
NGN, AED e VND ausentes — **é uma expectativa, não um facto verificado.**

### 4.5 Calendário económico: fora

O §1 do `.docx` classifica-o como Tier B, 150k–350k buscas/mês. Fica fora deste plano por
duas razões: não tem fonte gratuita com qualidade publicável, e o tráfego é informativo —
não é o que converte. Reavaliar quando houver receita provada para pagar por ele.

---

## 5. ⚠️ Exposição legal — o que o documento de origem não cobre

O §7 do `.docx` marca apenas a Índia. **Não é suficiente.**

### 5.1 Três GEOs com restrições comparáveis à indiana

Pelo que se conhece do enquadramento nestes mercados, **Vietname, Indonésia e Tailândia
restringem o forex OTC alavancado de retalho** de forma comparável à Índia: a Indonésia
exige corretora licenciada pela Bappebti, o Vietname restringe o forex de retalho a
indivíduos, e a Tailândia limita-o. **Estas afirmações não estão verificadas contra fonte
primária** e não devem ser tratadas como facto.

**Decisão de engenharia, que não espera pela verificação:** VN, ID e TH nascem com o **CTA
desligado** por GEO. Servem ferramentas, conteúdo e tráfego; não emitem um único link de
afiliado até o dono decidir com a fonte do regulador à frente. Custo: zero. É a posição
defensável, e o kill-switch por GEO (§6.4) existe precisamente para a tornar reversível
com um clique quando a verificação for feita.

**A VERIFICAR**, por ordem de custo: Bappebti (ID), SBV/Ngân hàng Nhà nước (VN), SEC
Tailândia. Antes de ligar o CTA em qualquer uma.

### 5.2 A Índia, e a decisão de manter a XM

O `.docx` §9 recomenda o contrário do que está montado: não usar XM/Exness na Índia, e
monetizar com corretoras SEBI (Angel One, Upstox, Zerodha).

**O dono decidiu manter a XM na Índia** (30 ago 2026), reafirmando a escolha depois de a
contradição lhe ser posta. Fica registada como decisão de negócio, com o risco residual que
o `wp-content/plugins/hti-forex/README.md:179` já regista:

> XM appears on the RBI's Alert List, and trading offshore OTC forex breaches FEMA for
> Indian residents. […] The label, the CFD risk warning, the Alert List line and answering
> before advertising are as far as the code can go; the rest is a business call.

**A ação mínima continua por fazer:** verificar a Alert List contra **o PDF do próprio RBI**,
não contra as duas fontes secundárias em que a afirmação assenta hoje. É a única coisa que
reduz exposição sem travar nada.

A rota SEBI fica documentada como alternativa disponível — o `.docx` §9 identifica Angel
One e Upstox como os programas de maior payout e mais fáceis de integrar — e a matriz de
corretoras (§6.4) torna a troca uma linha de configuração se a decisão mudar.

### 5.3 Exclusões de país das duas corretoras

A matriz tem de as respeitar estruturalmente, não por convenção:

- **XM**: não aceita referências de **Portugal, Espanha e Bélgica**; a entidade global exclui
  EUA, Canadá, Irão e Israel.
- **Exness**: não aceita EUA, Canadá, Austrália, Reino Unido (retalho) nem a maior parte
  da UE.

Portugal na lista de exclusão da XM é a que mais importa aqui, porque **este site é
PT-facing**. A mitigação é de arquitetura: o CTA nunca é emitido sem uma GEO escolhida, as
páginas do `/forex/` são inglesas, e **o lado `/pt/` do site nunca lhes liga**. Não existe
GEO "Portugal" na tabela, portanto não existe caminho de código que resolva um CTA para um
visitante português. Um teste da suite falha se uma GEO apontar para uma corretora que a
exclui (§9).

### 5.4 O que se mantém do que já está feito

Aviso de risco CFD junto de cada CTA; linguagem condicional; disclaimer de que as
calculadoras dão valores indicativos; e o `/forex/go/{slot}/` como único emissor de URLs
de afiliado — o `cta_for()` devolve a *placement*, nunca o URL, e um teste falha se um
terceiro ficheiro voltar a ler `cta_url`.

---

## 6. Arquitetura

### 6.1 URLs — uma página por ferramenta, com seletor de moeda

**Decisão do dono (30 ago 2026): nenhuma página por GEO.** A estrutura de URLs não cresce
com as nove GEOs — cada ferramenta é **uma página que serve as nove moedas** através de um
seletor.

- `/forex/` — hub, como hoje.
- `/forex/{ferramenta}/` — uma por ferramenta, multi-moeda.
- As três variantes por ativo e caso que já existem — `xauusd-lot-size-calculator`,
  `lot-size-for-100-dollar-account`, `lot-size-calculator-with-leverage` — **mantêm-se e
  são o padrão a repetir** quando houver apetite para mais long-tail: são páginas por
  *ativo* e por *caso de uso*, que é onde a busca existe sem multiplicar por país.

**Zero páginas novas nesta arquitetura.** As oito atuais ganham o seletor; a F2 acrescenta
três ferramentas em falta. **Onze páginas no estado final**, contra ~107 do desenho
anterior.

O `class-seeder.php` continua com a hierarquia de um nível que já tem (`:134-144`,
`:176,216`) — não precisa do segundo nível que a arquitetura por GEO exigia.

> **O que se perde, dito uma vez.** As buscas com país — "position size calculator in
> naira", "pip value in rupees" — têm concorrência baixa e eram o ponto 1 da diferenciação
> face à Myfxbook e à Investing.com (`.docx` §4). Numa página única, competimos pelos termos
> de cabeça, onde a dificuldade é alta. É uma troca deliberada de alcance por custo de
> conteúdo. O §6.3 descreve o que a recupera em parte sem criar páginas.

### 6.2 O seletor de moeda

Passa a ser a peça central da secção, e tem quatro requisitos que não são negociáveis
porque cada um protege algo que já funciona.

**1. O default vem de uma definição, não do IP.** Arranca em **INR**. As páginas atuais são
os landers das campanhas pagas indianas em curso: um clique da Propeller tem de aterrar
exactamente no que aterra hoje. A definição é de admin, portanto muda sem deploy quando a
audiência deixar de ser só indiana. **Geolocalização por IP continua fora** — pelas três
razões do §6.4 (privacidade, cache, dependência externa).

**2. A escolha viaja no URL como parâmetro, não como página.** `?c=ngn` fixa a moeda sem
criar um URL indexável — serve os deep links do bot, os landers de campanha e a partilha.
O valor é validado contra a tabela de GEOs (allowlist), nunca aceite em cru. A escolha
persiste em `localStorage` para o visitante recorrente.

**3. A baseline sem JavaScript mantém-se.** A página de sessões renderiza hoje a tabela do
lado do servidor e funciona com o JS desligado (`class-config.php:109`). Com o seletor, o
servidor rende a moeda default e o JS troca — a propriedade não se perde. Vale para o
câmbio editável, que passa a trocar com o seletor.

**4. Um só caminho de compliance.** Este é o ganho real da arquitetura, e não é pequeno:
escolher a moeda passa a resolver, no mesmo sítio, o bloco de regulador local, o aviso de
risco, e se há ou não CTA. Escolher VND não mostra CTA nenhum (§5.1); escolher NGN resolve
a Exness; escolher INR resolve a XM. **Uma implementação em vez de nove templates de
página** — muito menos superfície onde a regra de compliance pode ficar por aplicar.

### 6.3 Como recuperar parte do long-tail sem páginas novas

Duas vias, ambas dentro das onze páginas:

**As FAQs carregam as buscas por moeda.** "How much is 1 pip in Nigerian naira?" pode ser
uma entrada de FAQ numa página que também responde em rands e em rupias. É `FAQPage`
JSON-LD, elegível para resultados enriquecidos, e capta parte da consulta sem uma página
por país. Nove entradas de FAQ numa página é legítimo; nove páginas quase iguais é onde
estava o risco de thin content.

**As variantes por ativo e caso são o padrão que já se provou.** `xauusd-lot-size-calculator`
e `lot-size-for-100-dollar-account` existem e são long-tail real — por ativo e por caso, não
por país. O `.docx` §3 identifica o ouro como o modificador que mais converte. Se houver
apetite para mais páginas mais tarde, **é por aqui que se cresce**, não por GEO.

### 6.4 Matriz de corretoras

O `cta_url` único (`class-settings.php:57`) e o `sub_param` único (`:78`) dão lugar a duas
tabelas:

```
brokers:    xm     => { label, url, sub_param, active,
                        logo_url, ad_top, ad_top_mobile, ad_inline,
                        bot_demo_url, bot_demo_text,
                        bot_real_url, bot_real_text }
            exness => { … os mesmos campos }
geo_broker: in => xm | ng => exness | za => exness | …
```

**A linha da corretora carrega os criativos, não só o link** — é o que permite acrescentar a
Exness sem deploy. Quase tudo isto já existe hoje, mas com um valor só para toda a secção;
o trabalho é passá-lo a por-corretora:

| Peça | Onde está hoje | Passa a |
|---|---|---|
| Link de afiliado | `cta_url` (`class-settings.php:57`) | `brokers[x].url` |
| Sub-id da rede | `sub_param` (`:78`) | `brokers[x].sub_param` |
| Logo do parceiro | `cta_logo_url` (`:60`), com wordmark de recurso e alt | `brokers[x].logo_url` |
| Banner topo desktop 600×90 | slot de tag de anúncio (`:584`) | `brokers[x].ad_top` |
| Banner topo mobile 320×100/50 | slot (`:591`) | `brokers[x].ad_top_mobile` |
| Banner sob a ferramenta 468×60 / 300×250 | slot (`:601`) | `brokers[x].ad_inline` |
| Anúncio do bot — demo e conta real | `bot_ad_*_url` / `_text` (`:70-73`) | `brokers[x].bot_*` |
| Interruptor dos banners | `ads_enabled` (`:111`) | mantém-se global, mais o por-GEO |

Os slots aceitam **a tag de banner da rede** (iframe ou script do painel do parceiro),
limitada a 10.000 caracteres com a mensagem de erro que já existe (`:123`) — colar uma tag,
não uma página. O logo é um URL de imagem https, validado como já é (`:178-185`).

**Uma exceção que continua a exigir reconstrução:** o banner do cheat sheet em PDF vive em
`assets/pdf/src/xm-600x90.png` e é injetado no marcador `<!--XM_BANNER-->` pelo `build.sh`.
Um PDF fica no disco do leitor para sempre, por isso a imagem não pode vir de uma definição.
Acrescentar a Exness aqui é trocar o ficheiro e correr o `build.sh` — a única parte da
monetização que não é um clique no admin.

**A GEO nunca vem de geolocalização por IP.** Vem do **seletor de moeda** (no site) e da
linha do subscritor (no bot). Zero PII nova, zero problema de cache, zero dependência de um
serviço de terceiros. É a escolha certa por três razões independentes.

**Como a GEO chega ao redirector sem furar a invariante.** Desde o `hti-forex` 0.13.8 o
`cta_for()` devolve a *placement* e nunca o URL — o `cta_url` não está ao alcance de quem
desenha a página, e um teste falha se um terceiro ficheiro o voltar a ler. Isso mantém-se:
o `href` continua a ser `/forex/go/{slot}/`, escrito do lado do servidor, e o seletor
acrescenta-lhe **`?g={geo}`** do lado do cliente — do mesmo modo que o `cid` de campanha já
viaja hoje (`class-go.php:60,227`). O redirector valida o `g` contra a tabela de GEOs
(**allowlist**, nunca texto livre, ou reabre-se a superfície de cardinalidade que a
auditoria fechou), resolve a corretora, e só então anexa o sub-id dela.

Sem `g`, ou com um `g` que não está na tabela, o redirector usa a GEO default das definições
— nunca falha, nunca 404, que é a regra que esta rota já tem para o caso do PDF impresso.

**Kill-switches:** o global e o por-ferramenta que já existem (`class-settings.php:111`),
**mais um por GEO**. Necessário para o §5.1 (VN/ID/TH nascem desligadas), para o §5.3
(exclusões de país), e para o caso banal de um contrato de afiliação acabar num mercado.

Reutiliza-se a **forma** do `Broker_Go::with_sub_id()` do hti-engine
(`class-broker-go.php:159`), sem criar dependência: o `hti-forex` é isolado por desenho e
essa contenção é o que torna a secção removível desativando um plugin.

### 6.5 Conteúdo: as contas

| Bloco | Páginas |
|---|---|
| Hub | 1 |
| Ferramentas (4 existentes + 3 da F2) | 7 |
| Variantes por ativo e caso (XAUUSD, conta de $100, com leverage) | 3 |
| **Subtotal EN** | **11** (8 existem) |
| Traduções para vi, th, id, pt-BR (F4) | 44 |
| **Total** | **55** |

Prosa a escrever à mão:

| Peça | Quantas |
|---|---|
| FAQs da aritmética — 2 por ferramenta, uma vez cada | ~20 |
| Parágrafo de contexto local por GEO (regulador, depósito, fiscalidade) | 9 |
| **Total à mão** | **~29** |
| FAQs por moeda — templadas da tabela de GEOs, não escritas | ~20 |

**As FAQs por moeda saem da tabela, não da caneta.** "Quanto vale 1 pip em naira?" tem uma
resposta que é aritmética com os dados dessa GEO — câmbio, símbolo, ordem de grandeza da
conta, quanto é um micro-lote naquela moeda. Não é texto girado; são números genuinamente
diferentes. O que precisa mesmo de um humano é **um parágrafo de contexto local por GEO**,
reutilizado em todas as páginas onde essa moeda está escolhida: regulador e a sua posição,
método de depósito (UPI/IMPS na Índia, Paystack/Flutterwave na Nigéria, PIX no Brasil,
DuitNow na Malásia), enquadramento fiscal.

> **Nota honesta sobre a poupança.** Colapsar de ~107 para 11 páginas poupa **páginas**, não
> prosa: ~29 peças à mão contra ~155 no desenho por GEO — mas contra ~23 no desenho
> intermédio (duas âncoras por GEO). O ganho grande está noutro lado: menos superfície de
> revisão, menos `hreflang` a gerir, menos seeding, e um só caminho de compliance (§6.2).
> O custo está no alcance de busca (§6.1).

**A tradução passa a ser o maior custo de conteúdo do plano** — 44 páginas contra 11 em
inglês. Ver §8, F4.

As FAQs vivem em `Config::faqs()` (`class-config.php:164`), fonte única da página e do
JSON-LD. Vale o caveat de drift já documentado no README do plugin: reescrever a copy no
wp-admin dessincroniza o schema.

### 6.6 Bot

**Moeda e GEO passam a colunas** de `hti_forex_bot_subs`. A tabela já tem `pair`, `leverage`
e `source` (`class-bot-store.php:82-84`) e versionamento de schema (`:36`) — é um bump de
`SCHEMA` e um `dbDelta`, mecanismo que já corre em cada `init`.

**Drip de 7 dias** (`includes/class-bot-drip.php`, novo). Reutiliza o padrão do
`Bot_Broadcast`: lotes caminhados por cursor em eventos de cron único, largando quem bloqueou
o bot. O `Telegram::send()` já converte 403 em "esquecer esta pessoa" e 429 em "esperar
isto" — as duas coisas que um drip precisa e que custaram a acertar uma vez.

`/stop` apaga a linha (`class-bot.php`, `Bot_Store::forget()`), portanto **o drip pára
sozinho**: a semântica RGPD certa cai da arquitetura existente em vez de ser uma regra que
alguém tem de se lembrar de aplicar.

**Sem n8n/Make**, contra a sugestão do `.docx` §5.4: evita infraestrutura nova, custo
recorrente, e — o que decide — dados pessoais de 915 pessoas fora do nosso lado, com o RGPD
por cima.

**O anúncio do bot passa a ser por corretora.** Existem hoje dois slots — o de demo e o de
conta real — cada um com URL e texto próprios (`class-settings.php:70-73`), ambos com
default para `/go/xm-demo/` e `/go/open-account-xm/`. Continuam a ser **obrigatoriamente
links do nosso host**: uma mensagem privada não carrega divulgação e não se corrige depois
de enviada, por isso exigir o `/go/` é estrutural e não uma regra a lembrar. O que muda é
que os quatro valores passam a viver na linha da corretora (§6.4), resolvidos pela GEO da
pessoa.

---

## 7. Precondição de medição

A `Estado_e_Cronologia_Set2026.md` regista que 11 dos 34 eventos eram gravados e nunca
mostrados, entre eles `forex_bot_start/calc/stop` e `forex_tool_use`, e que o `location` do
`forex_tool_use` era descartado.

**Isso já foi corrigido, e este documento corrige o registo.** Verificado no código:

- Os quatro eventos têm ecrã próprio — secção "Forex bot & tools" (`class-metrics.php:996-1011`).
- O `location` do `forex_tool_use` tem desdobramento próprio, no mapa `tool`, deliberadamente
  separado do `cta` (`:234-245`).
- Ambos os mapas têm teto de cardinalidade, `MAX_PATHS_PER_DAY = 300` (`:37,228,242`).

**O que falta é outra coisa: o GEO não é dimensão de nada.** Nenhum dos mapas — `cta`,
`tool`, `bkr`, `bkr_loc` — carrega a GEO. Multiplicar por nove sem isso produz um agregado
que não responde à única pergunta que interessa: **que GEO paga.**

**A arquitetura de página única agrava isto, não alivia.** No desenho por GEO, o URL da
página dizia sempre de que país era o clique — a GEO podia ser lida do caminho. Com uma
página a servir nove moedas, **o URL deixa de dizer nada** e a única fonte da GEO é o
seletor. Se o evento não a carregar, a informação não existe em lado nenhum. Isto passa de
precondição a bloqueador.

Precondição, antes de ligar o seletor em produção:

1. GEO codificada no `location` com uma convenção fixa (`{ferramenta}_{geo}`) e desdobrada
   no ecrã — nos mapas `tool` e `cta`.
2. Confirmar que o teto de 300 chega para ferramenta × GEO × placement. Dez ferramentas × 9
   GEOs × alguns slots fica confortavelmente abaixo — mas é uma conta a fazer, não a assumir.
3. O `source` do bot já distingue campanhas (`class-bot-store.php:84`, teto de 50 em `:47`);
   confirmar que os códigos de campanha por GEO cabem nesse teto.
4. **Uma métrica nova que a arquitetura anterior não precisava:** quantas pessoas mexem no
   seletor, e para que moeda. É o único sinal que diz se as nove GEOs têm procura real —
   sem páginas por país, não há como o ler do Search Console.

---

## 8. Faseamento

Sem páginas por GEO, as fases deixam de ser sobre semear conteúdo e passam a ser sobre
capacidade. É um plano mais curto e com menos dependência de escrita.

### F0 · Precondição (início de outubro)

GEO no `location` dos eventos e no ecrã, mais a métrica de uso do seletor (§7). Verificar a
lista de moedas do Frankfurter (§4.4). Verificar a Alert List do RBI na fonte primária
(§5.2). **Nada disto é opcional:** sem o ponto 1, ligar o seletor apaga a informação de país
em vez de a criar.

### F1 · Motor de GEO e seletor

`class-geo.php`, registo de câmbios por moeda, formatadores por agrupamento, parser de
montantes por locale, matriz de corretoras com kill-switch por GEO, e o **seletor de moeda**
nas oito páginas atuais (§6.2) — default INR, `?c=` validado, baseline sem JS preservada,
bloco de regulador e CTA a resolver pela escolha.

**Zero páginas novas.** As nove GEOs ficam servidas no dia em que esta fase fecha.

### F2 · As três ferramentas em falta

Margem e leverage autónomas — a matemática já existe embutida no position size, é uma página
e um `[hti_forex_tool name=…]` novo, não uma implementação — e compounding, o funil
aspiracional do `.docx` §1. **3 páginas**, cada uma multi-moeda à nascença.

### F3 · Bot

CTA e anúncio por país, drip de 7 dias. É a fase que fecha o funil que o `.docx` §5.2
desenha — e a que mais depende de F0, porque sem GEO nas métricas não se sabe qual país
paga o bot.

**Sem Mini App** (§2.2), o que torna esta fase substancialmente mais curta: cai a rota de
validação de `initData`, cai a página `/forex/app/`, e cai a única exceção de segurança que
o plano tinha.

### F4 · Idiomas

vi, th, id e pt-BR: **44 páginas traduzidas** (11 × 4).

É agora, de longe, **a maior fatia de conteúdo do plano** — quatro vezes o site inglês —
e a que menos se justifica sozinha. Vale a pena tratá-la como decisão à parte, tomada
depois de F1 dizer se aquelas quatro GEOs têm procura no seletor. Se não tiverem, esta fase
não deve acontecer.

**Quem revê copy financeira em quatro línguas que ninguém no projeto lê** é o risco desta
fase e não tem solução técnica. A alternativa honesta, se não houver revisor, é publicar
VN/TH/ID/BR só em inglês e aceitar perder o long-tail local.

#### O Polylang gere línguas, não GEOs

**Decisão, 30 ago 2026: não se criam línguas por GEO no Polylang.** A tentação é registar
`en-IN`, `en-NG`, `en-ZA`, `en-AE`, `en-MY` "para organizar". Seria um erro em três frentes:

1. **O Polylang resolve a língua pelo prefixo do URL.** Registar cinco variantes de inglês
   traz de volta `/ng/`, `/za/`, `/ae/` — exatamente os URLs por GEO que a decisão do §6.1
   removeu, e com eles a contagem de páginas.
2. **O switcher e o `hreflang` exigem a página em cada árvore de língua.** Não é arrumação:
   é cinco cópias de cada página, com texto quase igual em cinco URLs, das quais o Google
   indexa uma.
3. **`current_lang()` é binário** (`functions.php:344-353`, devolve `'en'|'pt'`) e alimenta
   o chrome inteiro do tema. Cada locale acrescentado é uma alteração em cada sítio que o
   chama.

**A estrutura organizadora é o `class-geo.php`** (§3.1), não o Polylang: uma linha por GEO,
fonte única para páginas, bot, schema e definições. A GEO é estado de execução — o seletor —
e não conteúdo. São eixos diferentes e devem continuar a sê-lo.

#### ⚠️ Um bloqueador a corrigir antes desta fase

`SEO::post_lang()` (`class-seo.php:668-677`) é uma função de **dois valores**:

```php
return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt-PT' : 'en-US';
```

Colapsa qualquer língua num dos dois. Com a F4, uma página **vi, th ou id** declararia
`inLanguage: en-US` no grafo de schema, e uma página **pt-BR** declararia `pt-PT`. Toda a
página traduzida mentiria sobre a própria língua.

É um sítio só e a correção é uma função — mas tem de vir antes de existir a primeira página
traduzida, não depois. Os dois ramos que o consomem (`:299` no `faq_page()` e `:594` no
bloco do Quiz) estão corretos **dado** o normalizador; corrigir `post_lang()` obriga a
revisitá-los, porque deixam de poder assumir que só há duas respostas possíveis.

Acresce que o seeder do forex fixa `pll_set_post_language( $id, 'en' )`
(`class-seeder.php:228-229`) — passa a receber a língua como parâmetro.

---

## 9. Testes

Na suite existente — `php wp-content/plugins/hti-forex/tests/run.php`, hoje 527 asserções
PHP e 83 Node, verdes.

| Ficheiro | O que fecha |
|---|---|
| `test-geo.php` (novo) | Toda a GEO tem as chaves todas; toda a moeda tem fonte declarada; toda a GEO aponta a uma corretora que existe **e que não a exclui** (§5.3); VN/ID/TH têm o CTA desligado (§5.1) |
| `test-rates.php` (estender) | Precedência por moeda; flag de stale por moeda; o peg AED é constante; uma moeda em falta no payload não invalida as outras |
| `test-bot-math.php` (estender) | Agrupamento indiano vs ocidental; moedas sem casas decimais (VND, IDR); parser por locale; escalões derivados de USD |
| `test-settings.php` (estender) | Cada corretora tem URL https, `sub_param` e slots de criativo válidos; o logo e os banners rejeitam `http` e HTML acima do teto; os dois URLs do bot continuam a exigir o host próprio |
| `test-drip.php` (novo) | Avanço de dia; `/stop` interrompe; 403 larga a linha; 429 recua |
| `test-selector.php` (novo) | O `?c=` é validado contra a allowlist e um valor inválido cai no default; o `?g=` do redirector idem; o default vem das definições e não do IP; as oito páginas atuais mantêm os slugs |
| `test-go.php` (estender) | `/forex/go/{slot}/?g={geo}` resolve a corretora certa por GEO; um `g` fora da tabela usa o default e nunca 404; o `cta_url` continua a não sair do ecrã de definições e do redirector |
| `test-forex-core.mjs` (estender) | Paridade PHP↔JS para as moedas novas |
| `test-post-lang.php` no hti-engine (novo, **antes da F4**) | `post_lang()` devolve a etiqueta BCP-47 de cada língua registada e não colapsa vi/th/id em `en-US` nem pt-BR em `pt-PT`; os consumidores em `class-seo.php:299,594` continuam corretos com mais de duas respostas |

A regra do projeto mantém-se: as suites correm antes de cada commit.

---

## 10. Riscos, por ordem de custo

1. **O alcance de busca que a página única deixa na mesa** (§6.1). É o custo assumido da
   decisão de 30 ago: competimos pelos termos de cabeça, onde a dificuldade é alta, em vez
   das consultas com país, onde não é. **Como saber se foi caro:** a métrica de uso do
   seletor (§7). Se muita gente trocar de moeda, há procura por GEO que não estamos a captar
   na busca — e a saída é acrescentar páginas para as duas âncoras, sem tocar no código
   (§6.3).
2. **Exposição legal em VN/ID/TH** (§5.1) e na Índia (§5.2). Mitigado por defeito no código;
   por resolver na verificação.
3. **Tradução (F4): 44 páginas**, quatro vezes o site inglês, e sem revisor de copy
   financeira nas quatro línguas. É o maior custo de conteúdo que resta e deve ser decidido
   depois de F1, não antes. A alternativa honesta é publicar VN/TH/ID/BR só em inglês.
4. **Desatualização do NGN** (§4.3). Mitigado por três camadas; vigiar.
5. **Criativos por corretora desatualizados** (§6.4). Um banner ou logo que o parceiro retira
   deixa um buraco na página. Mitigado por o slot vazio não renderizar nada e pelo
   kill-switch `ads_enabled`; o PDF é a exceção, porque exige reconstrução.
6. **A revisão jurídica (L-D) continua por fazer** e a `Estado_e_Cronologia_Set2026.md`
   marca-a como bloqueador de divulgação, com o gate "Corretoras & afiliados" a 0/9 e a
   secção em produção. Este plano multiplica por nove os mercados que ela teria de cobrir —
   mesmo sem multiplicar as páginas. Não é razão para não avançar; é razão para a L-D deixar
   de esperar.
