# Calendário de conteúdo — setembro 2026

Atualizado a 31 ago 2026. Substitui as linhas "Conteúdo" do
`Estado_e_Cronologia_Set2026.md`, que assumiam um arranque a 1 de setembro.

**Não arrancou a 1 de setembro. Arrancou a 29 de agosto**, e o mês está dois
dias adiantado em relação ao plano. Este ficheiro parte do que já saiu.

---

## 1. O que já aconteceu

| Quando | O quê | Resultado |
|---|---|---|
| 29–30 ago | Mensagem de dia 1 no canal + sondagem *"Where are you with forex right now?"* | enviada |
| 30 ago | Difusão ao bot: *"Most people size a trade by feel…"* | **800 de 919** · 119 perdidos (12,9%) |
| 31 ago | Sondagem *"How do you decide your lot size?"* | enviada |
| 30 ago | Ronda 1 dos anúncios fechada e lida | `b2` ganha a $0,0444/utilizador · 1,85× mais barata |
| 31 ago | Ronda 2 lançada, tudo na `b2` | a correr |

E, do lado do código, a cadeia de atribuição fechou: anúncio → `/start b2` →
resposta do bot → `/go/…?cid=b2` → painel da corretora. **A partir de agora**
cada pessoa nova traz consigo o anúncio que a pagou. As 945 anteriores não —
entraram antes da coluna existir e não há como recuperar.

---

## 2. As regras que governam este calendário

**Uma coisa por dia, no canal.** Uma sondagem precisa de um dia inteiro para
recolher votos; uma segunda mensagem por cima rouba-lhe a atenção e estraga a
amostra.

**Três a quatro posts por semana, não sete.** Um canal novo que publica todos
os dias fica sem nada para dizer à terceira semana e enche com ruído. Ruído é
como se perdem subscritores — e já sabemos o que custa perdê-los.

**Difusões ao bot: uma a cada dez a catorze dias, no máximo.** A primeira
custou 12,9% da lista. Se a segunda custar o mesmo, a lista parte-se ao meio em
cinco envios. O número da próxima difusão é o dado mais importante do mês.

**Hora: 19h00–21h00 IST** (14h30–16h30 em Lisboa). É quando o retalho indiano
está acordado e a sobreposição Londres–Nova Iorque está aberta. Publicar de
manhã em Lisboa é publicar de madrugada para eles.

**Inglês.** A decisão do mês foi dobrar na Índia/EN. O PT mantém-se, não cresce.

**Os invariantes valem aqui como valem no site.** Linguagem condicional, só
classes de ativos, disclaimer associado. Corretoras **só** em peça rotulada
"Partner · Ad", com divulgação de afiliação e aviso de risco CFD na própria
mensagem — o rodapé automático do bot não cobre uma difusão manual.

---

## 3. Formatos recorrentes

Três, para o canal não ter de ser inventado de novo todas as semanas.

**A · The arithmetic** — um número, feito à frente da pessoa. É o formato do
criativo que já existe (`bot-promo.png`): "₹191, um stop de 20 pips em 0,01
lotes". Duas vezes por semana. É o que o bot faz, em texto.

**B · One mistake** — um erro de dimensionamento ou de risco, e o que custa em
rupias. Uma vez por semana. Este é o formato que gera respostas.

**C · Sondagem** — uma por semana, à quinta ou sexta. Não é enchimento: é o que
decide o conteúdo da semana seguinte, e é a única medida de vida do canal que
não depende de ninguém escrever.

---

## 4. Semana 1 · 31 ago – 6 set · Revelar o bot

| Dia | Formato | O quê |
|---|---|---|
| **seg 31 ago** | C | ✅ *How do you decide your lot size?* |
| **ter 1 set** | C | *What stops you from starting?* — `Don't have enough capital` · `Don't know where to open an account` · `Scared of losing money` · `Already trading` |
| **qua 2 set** | — | **A revelação do bot.** Escrita a partir do resultado de segunda (ver §4.1). Link com `?start=canal`. |
| **qui 3 set** | A | *The arithmetic*: o custo de um stop de 20 pips em 0,01 lotes numa conta de ₹8.000 — o número que a sondagem de segunda mostrou que a maioria não calcula |
| **sex 4 set** | — | silêncio |
| **fds** | — | silêncio |

### 4.1 As três versões da revelação

Escolher pelo resultado da sondagem de segunda:

- **Ganha "Fixed lot every time" ou "By feel"** (provável): *"X% of you size by
  feel or with the same lot every time. That's the fastest way to blow an
  account — not because the trade is wrong, but because the size is. I built a
  bot that does the maths in three taps: your account, your risk %, your stop."*
- **Ganha "Not sure what lot size means"**: abrir com duas linhas a explicar o
  que é um lote, e só depois o bot. Este público precisa do conceito antes da
  ferramenta.
- **Ganha "I calculate it from my risk %"**: mudar o ângulo — *"Most of you
  already size by risk. Then you know the annoying part is doing it on the
  phone, mid-setup."* O bot como atalho, não como ensino.

**Registar antes:** total de votos e percentagem de cada opção. Votos a dividir
por subscritores é a taxa de vida real do canal, e é a linha de base contra a
qual tudo o resto se lê.

---

## 5. Semana 2 · 7–13 set · Provar a conversão

A semana em que o dinheiro da ronda 2 aterra. O conteúdo serve a medição, não
o contrário.

| Dia | Formato | O quê |
|---|---|---|
| **seg 7** | A | *The arithmetic*: o mesmo trade em 0,01 e em 0,10 lotes, lado a lado. Onde a conta de ₹8.000 sobrevive e onde não |
| **qua 9** | B | *One mistake*: mover o stop "só desta vez". O que faz ao risco por trade, em rupias |
| **qui 10** | — | **Difusão ao bot** (10 dias depois da primeira). Ver §5.1 |
| **sex 11** | C | Sondagem escrita a partir do que a de 1 set mostrar |

### 5.1 A segunda difusão

**Não é uma segunda mensagem de parceiro.** A primeira já o foi. Esta tem de
devolver valor, ou o número de `dropped` volta a subir e a lista deixa de valer
o que custou.

Sugestão: a resposta à sondagem de 1 de setembro. Se ganhar *"Don't have enough
capital"*, a difusão é a aritmética de uma conta pequena — que é, por acaso, a
página do site que melhor converte. Fecha com a calculadora, não com a XM.

**O número a registar:** `dropped`. Se rondar 13% outra vez, a lista é um balde
furado e a estratégia de anúncios tem de mudar antes de se gastar mais. Se cair
para 2–3%, a primeira difusão limpou os turistas e o que ficou é real.

---

## 6. Semana 3 · 14–20 set · Do canal para o site

O canal passa a puxar tráfego para as páginas, não só para o bot. É o que
transforma audiência alugada no Telegram em audiência própria e indexável.

| Dia | Formato | O quê |
|---|---|---|
| **seg 14** | A | *The arithmetic*: valor do pip em XAU/USD contra EUR/USD. Liga a `/forex/xauusd-lot-size-calculator/` |
| **qua 16** | B | *One mistake*: confundir alavancagem com risco. Liga a `/forex/lot-size-calculator-with-leverage/` |
| **sex 18** | C | Sondagem sobre pares — *"Which pair do you actually trade?"* — que dá a agenda de outubro |

**Também esta semana, fora do canal:** escrever o primeiro dos artigos EN
(§8), e ler os cliques por `telegram_bot_demo` / `telegram_bot_real` no funil.

---

## 7. Semana 4 · 21–30 set · Consolidar e escrever

| Dia | Formato | O quê |
|---|---|---|
| **seg 21** | A | *The arithmetic*: o custo real de entrar e sair — spread + swap numa posição mantida uma noite |
| **qua 23** | B | *One mistake*: dobrar depois de uma perda. Em rupias, ao terceiro trade |
| **qui 24** | — | **Difusão ao bot** (14 dias depois da anterior), com o segundo artigo publicado |
| **sex 25** | C | Sondagem de balanço — o que a pessoa quer ver no canal em outubro |
| **28–30** | — | Relatório do mês; agenda de outubro escrita a partir da sondagem de 25 |

---

## 8. Conteúdo do site — 2 a 3 artigos EN para a Índia

Escrever nas semanas 3 e 4, publicar à medida. Todos entram no cluster
`/forex/` e ligam às ferramentas que já existem, seguindo as regras do
`seo-content` (H2 em pergunta, TL;DR no topo, ≥3 links internos).

**1. "What is a lot in forex? (with ₹ examples)"** — definicional, volume
alto, e alimenta todas as sete páginas de ferramenta por dentro. É o artigo
que falta debaixo delas.

**2. "How much money do you need to start forex trading in India?"** — é
literalmente a primeira opção da sondagem de 1 de setembro. Se ganhar, o artigo
está validado pelo público antes de ser escrito. Liga a
`/forex/lot-size-for-100-dollar-account/`, que é a página que já converte
melhor.

**3. "Is forex trading legal in India?" — alto valor, e bloqueado.** É o maior
volume de pesquisa do nicho e é a pergunta que trava a conversão. Também é
território RBI/FEMA, que o `Estado_e_Cronologia` já assinala como exposição
legal por rever. **Não escrever antes da revisão jurídica.** Escrever mal isto
é pior do que não ter a página.

---

## 9. O que se mede, e quando

| Métrica | Onde | Quando |
|---|---|---|
| Votos por sondagem ÷ subscritores | Telegram | a cada sondagem |
| `dropped` por difusão | painel do bot | 10 set, 24 set |
| Cliques em `telegram_bot_demo` / `telegram_bot_real` | HTI Funnel | semanal |
| `forex_go_*` por ferramenta | HTI Funnel | semanal |
| Contas abertas por `cid` | painel da corretora | assim que o *sub-id* estiver preenchido |
| Custo por utilizador do bot, por criativo | PropellerAds × contagens | fim de cada ronda |

A última linha é a única que decide se outubro é maior ou mais pequeno do que
setembro.

---

## 10. O que este calendário deliberadamente não faz

- **Não publica todos os dias.** Um canal com três coisas boas por semana vale
  mais do que um com sete medianas, e a lista não aguenta o desgaste.
- **Não repete a mensagem de parceiro.** Uma por mês, no máximo, rotulada. A
  monetização vive nas páginas, não na caixa de entrada das pessoas.
- **Não cresce o PT.** Foi a decisão do mês; está registada e mantém-se.
- **Não toca em RBI/FEMA** antes da revisão jurídica.
