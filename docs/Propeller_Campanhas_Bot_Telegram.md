# PropellerAds — campanhas para o bot de Telegram

Documento de passagem de trabalho. Contém tudo o que é preciso para criar as
campanhas via MCP da PropellerAds sem ter de reconstruir o contexto.

**A conta da PropellerAds é real e gasta dinheiro real.** Criar, arrancar ou
alterar uma campanha move orçamento. Não arrancar nada que não tenha sido
pedido explicitamente, e confirmar antes de qualquer `start_campaigns`.

---

## 1. O que se está a anunciar

Um **bot de Telegram** — uma calculadora de forex para traders indianos.
Manda-se-lhe o saldo da conta (um número) e ele responde com o que a mais
pequena posição possível custa: margem retida, valor do pip em rupias, custo de
um stop de 20 e de 50 pips, e que fatia da conta isso representa.

**Público:** Índia, inglês, mobile. Maioritariamente principiantes com contas
pequenas — a página do site que melhor converte chama-se literalmente *lot size
for a $100 account*. Não é um público de traders experientes.

**Objetivo da campanha:** utilizadores que abrem o bot. Não cliques, não
visitas ao site.

**Posicionamento, que restringe a criatividade:** o produto distingue-se por ser
honesto e educativo — nada de sinais, nada de promessas. A concorrência na lista
de conversas do utilizador são canais chamados "FX Signals VIP 🚀". Criativos com
foguetes, chamas, setas verdes ou notas de dinheiro são contraproducentes aqui e
além disso são o que faz as redes reprovarem criativos financeiros.

---

## 2. Pré-condição, antes de gastar um cêntimo

**O bot tem de estar vivo e o webhook registado.** Se não estiver, o dinheiro
compra pessoas que escrevem a um bot morto e perdem-se todas.

Verificação: abrir `t.me/HowToInvestForexBot`, escrever `5000`, e confirmar que
responde com uma tabela em rupias. Se não responder, parar aqui.

---

## 3. Os URLs de destino, e porque têm um código

O Telegram transforma `t.me/OBot?start=CODIGO` na mensagem `/start CODIGO`
entregue ao bot. É o **único referrer que um bot de Telegram tem**. O bot já
está preparado para o ler e conta cada código uma vez por pessoa nova.

Sem código, a campanha diz quantas pessoas chegaram e nada sobre qual criativo
as pagou — que é a única pergunta que um teste de criativos faz.

| Criativo | URL de destino |
|---|---|
| Push, conceito A (o número) | `https://t.me/HowToInvestForexBot?start=px_a1` |
| Push, conceito B (a pergunta) | `https://t.me/HowToInvestForexBot?start=px_b2` |
| Push, conceito C (a honestidade) | `https://t.me/HowToInvestForexBot?start=px_c1` |
| Telegram Ads, conceito A | `https://t.me/HowToInvestForexBot?start=tg_a1` |
| Telegram Ads, conceito B | `https://t.me/HowToInvestForexBot?start=tg_b2` |
| Telegram Ads, conceito C | `https://t.me/HowToInvestForexBot?start=tg_c1` |

**Formato dos códigos** (imposto pelo bot, fora disto é descartado em silêncio):
minúsculas, dígitos, `_` e `-`, máximo 32 caracteres. Prefixo por formato
(`px_` push, `tg_` telegram ads) para se poder comparar **conceito contra
conceito dentro do formato** e **formato contra formato com o mesmo conceito** —
duas conclusões diferentes que um código único juntaria numa só.

**Se a PropellerAds recusar links `t.me`** como destino — algumas redes recusam —
usar em vez disso `https://howtoinvest.pro/go/bot/?loc=ads`, um redirector do
próprio site. Custa um salto extra e alguma conversão, mas funciona. Nesse caso
o slug `bot` tem de existir primeiro nas definições do WordPress; confirmar com
o dono antes de assumir que existe.

---

## 4. Formatos, por ordem de prioridade

### 4.1 Telegram Mini Apps Ads — **prioridade máxima**

A PropellerAds tem um formato nativo dentro de mini-apps do Telegram
(colocações em task-list, interstitial e banner). A própria PropellerAds
identifica "Telegram apps and channels" como uma das verticais que melhor
funciona neste formato.

Porque é decisivo aqui: no push web o percurso é anúncio → browser → t.me →
Telegram → bot, com fuga em cada salto. No formato nativo o utilizador já está
dentro do Telegram e é um toque.

**Especificações de criativo não verificadas** — ler as exigidas pelo
construtor da campanha no momento da criação. Existe também uma funcionalidade
de auto-criativos gerados por ML que pode servir para uma primeira ronda.

Ferramentas MCP: `get_best_rates_telegram_ads`, `create_telegram_ads_campaign`.

### 4.2 In-Page Push — segunda

Mesmos criativos e cópia do push clássico, renderizado como widget na página.

### 4.3 Push clássico — terceira

**Especificações verificadas:**

| Peça | Dimensão | Peso máximo |
|---|---|---|
| Ícone | 192 × 192 | 200 KB |
| Banner | 360 × 240 | 720 KB |

| Campo | Limite |
|---|---|
| Título | 30 caracteres |
| Descrição | 40 caracteres |

**Atenção ao banner.** 360×240 é minúsculo. Criativos desenhados a 1280 ou 2560
de largura ficam ilegíveis quando reduzidos — têm de ser **redesenhados** para
esta dimensão, não exportados mais pequenos. Regra prática: desenhar a 720×480
com o texto mais pequeno a 48 px e exportar a metade.

**O ícone já existe** e não precisa de designer: é o avatar do bot, em
`wp-content/plugins/hti-forex/assets/brand/hti-forex-telegram-bot.png`
(512×512, o símbolo ₹ sobre disco navy). Redimensionar para 192×192.

Ferramentas MCP: `get_best_rates_push`, `create_push_campaign`,
`add_campaign_creatives`.

---

## 5. A cópia dos anúncios

Todas verificadas contra os limites (título ≤30, descrição ≤40). O número entre
parênteses é a contagem real.

| Código | Conceito | Título | Descrição |
|---|---|---|---|
| `a1` | o número | `₹191` (4) | `That's a 20-pip stop on 0.01 lots` (33) |
| `a2` | o número | `1 pip = ₹9.55` (13) | `Know what a trade costs before it` (33) |
| `b1` | a pergunta | `You have ₹5,000. Now what?` (26) | `Free calculator. Answer in seconds` (34) |
| `b2` | a pergunta | `Trading with a $100 account?` (28) | `See what you can actually place` (31) |
| `c1` | a honestidade | `No signals. Just the maths.` (27) | `A calculator, not a tip channel` (31) |
| `c2` | a honestidade | `Is your account too small?` (26) | `The calculator will tell you` (28) |
| `d1` | sem atrito | `Free lot size calculator` (24) | `No sign-up. Works inside Telegram` (33) |
| `d2` | sem atrito | `Lot size, in rupees` (19) | `Send a balance. Get the numbers.` (32) |
| `e1` | Índia | `Position size in ₹` (18) | `Built for Indian traders. Free.` (31) |

**Para a primeira ronda usar apenas `a1`, `b2` e `c1`** — um por conceito. As
restantes são reserva para a segunda ronda.

**Aposta do autor deste documento:** a `b2`. A página do site que mais converte
é sobre contas de $100, portanto essa pergunta já está validada neste público.

**Nunca escrever no anúncio:** taxas de acerto, "garantido", "lucro",
"oportunidade", alavancagem como argumento de venda. Além de falso para este
produto, é o que faz as redes reprovarem criativos financeiros.

---

## 6. Configuração das campanhas

| Definição | Valor |
|---|---|
| País | Índia |
| Dispositivo | Mobile |
| SO | Android |
| Idioma do browser | EN |
| Modelo de preço | CPC ou Smart CPC |
| Orçamento | teto diário **igual** por criativo |

**Porquê CPC e não CPA Goal:** o CPA Goal precisa de conversões para otimizar, e
no início não há nenhuma. Passar a CPA Goal só depois de haver dados.

**Porquê tetos iguais por criativo:** com um orçamento partilhado, a rede
concentra a verba no criativo que arranca melhor nas primeiras horas e os outros
nunca chegam a ser vistos. O teste fica por fazer e não se nota.

**Uma variável de cada vez.** Primeira ronda: três conceitos, mesma cor, mesmo
formato. Segunda ronda: o conceito vencedor em duas cores. Testar seis criativos
× duas cores × três textos ao mesmo tempo com orçamento pequeno produz ruído,
não dados.

Ferramentas MCP para a segmentação e o arranque: `get_countries`,
`set_campaign_targeting`, `set_campaign_rates`, `start_campaigns`.

---

## 7. Como se leem os resultados

Os números vivem em dois sítios e juntam-se pelo código:

- **PropellerAds** (`get_statistics`): impressões, cliques, custo, por criativo.
- **O bot** (wp-admin → Definições → HTI Forex, secção *Where they came from*):
  quantas **pessoas novas** cada código trouxe.

A métrica que interessa é **custo por utilizador do bot**, não custo por clique:
gasto do criativo ÷ pessoas novas do código correspondente. Um criativo com
cliques baratos e poucas adesões está a comprar curiosos.

O bot conta cada código uma vez por pessoa nova — abrir o mesmo anúncio duas
vezes é uma pessoa. Os números dos dois lados não vão bater certo, e é suposto:
a diferença entre cliques da PropellerAds e pessoas novas no bot é a fuga do
percurso, e é precisamente o que justifica preferir o formato nativo do Telegram.

---

## 8. Antes de arrancar — lista de verificação

1. O bot responde a `5000` no Telegram.
2. Cada criativo tem o seu código no URL, e os códigos são os da secção 3.
3. Os banners são 360×240 redesenhados, não reduzidos.
4. Título e descrição dentro de 30 e 40 caracteres.
5. Segmentação: Índia, mobile, Android, EN.
6. Teto diário igual em todos os criativos.
7. Nenhum criativo com foguetes, chamas, setas verdes ou promessas de retorno.
8. Confirmação explícita do dono da conta antes do `start_campaigns`.

---

## 9. O que não foi verificado e tem de ser confirmado no construtor

- Dimensões exatas dos criativos do formato Telegram Mini Apps.
- Se a PropellerAds aceita `t.me` como URL de destino.
- Lances mínimos por formato e por geografia — usar `get_best_rates_*`.
- Se a categoria financeira exige aprovação prévia de criativos nesta conta.
