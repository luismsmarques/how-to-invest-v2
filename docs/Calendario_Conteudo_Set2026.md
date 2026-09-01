# Calendário de conteúdo — setembro 2026

Atualizado a 1 set 2026, a partir das publicações reais do canal (853
subscritores à data). Substitui a versão de 31 ago, cuja regra de 3–4 posts
por semana a prática já ultrapassou: o canal publicou três vezes a 31 de
agosto e o dono decidiu manter 1–3 publicações por dia. Este ficheiro passa a
governar essa cadência — e as regras que a tornam sustentável sem queimar a
lista.

---

## 1. O que já aconteceu, com números

| Quando | O quê | Resultado |
|---|---|---|
| 29 ago | Cheat sheet afixado + *The arithmetic* (um pip em ₹) + sondagem *"Where are you with forex right now?"* | 734 · 625 vistas · **14 votos, 4 comentários** |
| 30 ago | Difusão ao bot: *"Most people size a trade by feel…"* | **800 de 919** · 119 perdidos (12,9%) |
| 30 ago | Sondagem *"How do you decide your lot size?"* (escolha múltipla) | **7 votantes, empate nas 4 opções** · 4 comentários |
| 31 ago | *Lot sizes, without the jargon* (12:15) · **revelação do bot** (14:54) · *Market hours in IST* (18:04) | 1 403 · **2 382** · 2 089 vistas |
| 1 set | Primeiro **Diário IST** (09:32) | 1 324 vistas |
| 30–31 ago | Ronda 1 fechada e lida: `b2` ganha a $0,0444/utilizador · Ronda 2 lançada, tudo na `b2` | a correr |

O que estes números dizem:

- **As vistas (1,3k–2,4k) são muito acima dos 853 subscritores** — há partilhas
  e visitantes de fora. O canal alcança mais do que a sua lista; o conteúdo
  âncora está a funcionar como aquisição, não só como retenção.
- **A revelação do bot é o post mais visto do canal** (2 382). O produto é o
  melhor conteúdo.
- **As sondagens votam pouco (14 e 7 — 1–2% de quem vê) mas são o único
  formato que gera comentários** (4 cada). A interação precisa de menos
  atrito, não de mais sondagens iguais — daí o formato quiz (§3).
- **A sondagem do lot size empatou nas quatro opções com 7 votantes.** Amostra
  pequena demais para decidir seja o que for — é por isso que se repete no
  marco dos 1 000 (§6.1), onde a segunda medição decide de facto.

E, do lado do código, a cadeia de atribuição fechou: anúncio → `/start b2` →
resposta do bot → `/go/…?cid=b2` → painel da corretora. Cada pessoa nova traz
consigo o anúncio que a pagou; as ~945 anteriores não.

---

## 2. As regras (revistas a 1 set)

**Um âncora por dia, e é sempre o mesmo.** O Diário IST sai todas as manhãs de
semana. É templated — os cinco blocos de §3 — e é isso que torna a cadência
diária sustentável: canais morrem quando o post diário exige uma ideia nova
por dia; o âncora não exige nenhuma.

**No máximo um post de interação por dia, na sobreposição (19h–21h IST).**
Sondagem, quiz ou "manda-me o teu número" — nunca dois no mesmo dia: roubam
votos um ao outro e estragam as duas amostras.

**O terceiro post é opcional e nunca é enchimento.** Notícia com cartão (o
gerador está em `assets/brand/src/`), um marco, uma funcionalidade nova. Se
não houver nada, não há terceiro post — os dias de dois posts não são falhas.

**Difusões ao bot: uma a cada dez a catorze dias, no máximo** (mantém-se). A
primeira custou 12,9% da lista. O `dropped` da segunda é o dado mais
importante do mês.

**Horas.** Diário até às 14h30 IST (10h em Lisboa); interação e peças de
formato na sobreposição 19h–21h IST (14h30–16h30 em Lisboa).

**Inglês.** A decisão do mês foi dobrar na Índia/EN. O PT mantém-se, não cresce.

**Os invariantes valem aqui como valem no site.** Linguagem condicional, só
classes de ativos, disclaimer. A regra das corretoras foi revista — ver §4,
que substitui a formulação de 31 ago.

---

## 3. Formatos recorrentes

Cinco, para o canal não ter de ser inventado de novo todas as semanas.

**D · Diário IST** — o âncora (novo; o primeiro saiu a 1 set com 1 324
vistas). Cinco blocos fixos, meia hora de trabalho:
1. ontem em 2–3 números, dados indianos primeiro (PMI, GST, rupia);
2. hoje no calendário, com hora IST e porque interessa;
3. a janela 17h30–21h30 e o que muda nela;
4. um número em ₹ ligado ao preço de ontem ("a 95,17, um pip em 0,01 lotes ≈ ₹9,52");
5. o pedido de reação ("❤️ if this is useful") e o rodapé de §4.

**A · The arithmetic** — um número, feito à frente da pessoa. Duas vezes por
semana. É o que o bot faz, em texto — e cada cartão A é matéria-prima de um
quiz E na semana seguinte.

**B · Manda-me o teu número** — uma vez por semana, à sexta: "send me your
account balance, I'll do the maths in ₹". É o formato que gera respostas, e
cada resposta pública é um anúncio do bot.

**C · Sondagem** — uma por semana, à quinta. Não é enchimento: decide o
conteúdo da semana seguinte e é a medida de vida do canal que não depende de
ninguém escrever.

**E · Quiz** (novo) — uma por semana, à terça. Sondagem em modo quiz do
Telegram, com resposta certa: menos atrito do que pedir opinião, feedback
imediato a quem vota, e o material já existe nos cartões A ("One pip on a
micro lot of EUR/USD is roughly…"). Mede compreensão, não opinião — e é a
ponte natural para o bot ("the bot does this for your balance").

---

## 4. Afiliados: presença diária sem queimar o canal

A regra de 31 ago ("corretoras só em peça rotulada, uma por mês") já não
descreve a prática: o link da XM está hoje em quase todos os posts. Em vez de
manter uma regra que já ninguém segue, a regra passa a ser esta:

- **Rodapé padrão de duas linhas, sempre igual:**
  `Partner · Ad — Trade with XM → howtoinvest.pro/go/xm-open-account/?cid=…`
  `CFDs are high-risk. Educational content, not investment advice.`
  Rótulo e aviso de risco na própria mensagem, como os invariantes pedem. Um
  rodapé constante e arrumado envelhece melhor do que uma frase de venda
  colada ao corpo do post.
- **Um `cid` por formato.** O `/go/` já aceita `?cid=` e passa-o como sub-id:
  `canal-daily`, `canal-arit`, `canal-quiz`, `canal-poll`, `canal-reply`,
  `canal-weekahead`. Ao fim de uma semana o painel da corretora diz que
  formato converte — o rodapé deixa de ser fé e passa a ser dado.
- **Metade dos posts fecha para dentro** — bot, cheat sheet, páginas
  `/forex/` — e não para a XM. Primeiro porque o funil site → `/go/` também
  converte e constrói ativo próprio e indexável; segundo porque o mesmo bloco
  no fim de todos os posts treina cegueira. A variação é o que mantém o clique.
- **Zero afiliado nos posts de interação** (C, E, B). Pedir um voto e vender
  na mesma mensagem baixa as duas coisas.

---

## 5. A semana-tipo

| Dia | Manhã (≤14h30 IST) | Sobreposição (19h–21h IST) |
|---|---|---|
| seg | D | A |
| ter | D | E · quiz |
| qua | D | 3.º opcional (notícia com cartão, marco, funcionalidade) |
| qui | D | C · sondagem |
| sex | D | B · manda-me o teu número |
| sáb | — | Recap: *the week in 5 numbers* |
| dom | — | *The week ahead in IST* |

Sábado e domingo são um post só. O *week ahead* de domingo é o D esticado à
semana — e é o post certo para o rodapé de parceiro (`canal-weekahead`),
porque é o que o leitor guarda e reabre.

---

## 6. O resto de setembro

### Semana 1 · 1–7 set (transição para o esqueleto)

| Dia | O quê |
|---|---|
| **ter 1** | ✅ D (09:32) · à noite, **C** *What stops you from starting?* — estava planeada e ainda não saiu; é ela que escreve a difusão de dia 10 |
| **qua 2** | D · 3.º opcional |
| **qui 3** | D · **A** — o custo de um stop de 20 pips em 0,01 lotes numa conta de ₹8.000 |
| **sex 4** | D · **B** — primeira *send me your balance* |
| **sáb 5** | Recap |
| **dom 6** | *Week ahead* |

### 6.1 O marco dos 1 000 subscritores

Quando o canal cruzar os 1 000 (faltam ~150), o slot de interação desse dia é
a **repetição de *"How do you decide your lot size?"*** com enquadramento de
marco: *"We just hit 1,000. When we were a few hundred, this poll tied across
all four answers — let's settle it."* A de 30 ago teve 7 votantes; esta é a
segunda medição, com a amostra que finalmente escolhe a versão do pitch do bot
(§4.1 da versão anterior). Uma semana depois, o post-comparação — "then vs
now", formato A sobre a própria audiência. A primeira sondagem (*"Where are
you with forex right now?"*) repete-se na sexta seguinte no slot C: é a que
diz quem a `b2` realmente trouxe, cruzável com o `count_source('b2')`.

### Semana 2 · 8–14 set · Provar a conversão

O esqueleto de §5, com duas peças fixas:

| Dia | O quê |
|---|---|
| **qui 10** | **Difusão ao bot** (10 dias depois da primeira). Nesse dia o canal fica só com o D — a difusão é o evento. Devolve valor: a resposta à sondagem de 1 set; fecha com a calculadora, não com a XM. **Registar `dropped`**: ~13% outra vez = balde furado, mudar os anúncios; 2–3% = a lista que ficou é real |
| **sex 11** | **C** escrita a partir do que a sondagem de 1 set mostrar |

### Semana 3 · 15–21 set · Do canal para o site

O esqueleto de §5; os slots A e B desta semana ligam às páginas, não ao bot —
é o que transforma audiência alugada em audiência própria e indexável:

- **A** — valor do pip em XAU/USD contra EUR/USD → `/forex/xauusd-lot-size-calculator/`
- **B** — confundir alavancagem com risco → `/forex/lot-size-calculator-with-leverage/`
- **C** — *"Which pair do you actually trade?"* — dá a agenda de outubro
- Fora do canal: primeiro artigo EN (§7) e leitura dos cliques
  `telegram_bot_demo` / `telegram_bot_real` no funil

### Semana 4 · 22–30 set · Consolidar e escrever

- **A** — o custo real de entrar e sair: spread + swap numa posição de um dia para o outro
- **B** — dobrar depois de uma perda, em rupias, ao terceiro trade
- **qui 24** — **difusão ao bot** (14 dias depois da anterior), com o segundo artigo publicado
- **sex 25** — **C** de balanço: o que a pessoa quer ver no canal em outubro
- **28–30** — relatório do mês; agenda de outubro escrita a partir da sondagem de 25 e dos `cid` por formato (§4)

---

## 7. Conteúdo do site — 2 a 3 artigos EN para a Índia

Escrever nas semanas 3 e 4, publicar à medida. Todos entram no cluster
`/forex/` e ligam às ferramentas que já existem, seguindo as regras do
`seo-content` (H2 em pergunta, TL;DR no topo, ≥3 links internos).

**1. "What is a lot in forex? (with ₹ examples)"** — definicional, volume
alto, e alimenta todas as sete páginas de ferramenta por dentro.

**2. "How much money do you need to start forex trading in India?"** — é
literalmente a primeira opção da sondagem de 1 de setembro. Se ganhar, o
artigo está validado pelo público antes de ser escrito. Liga a
`/forex/lot-size-for-100-dollar-account/`, a página que já converte melhor.

**3. "Is forex trading legal in India?" — alto valor, e bloqueado.** Maior
volume de pesquisa do nicho e a pergunta que trava a conversão. Território
RBI/FEMA, já assinalado como exposição legal por rever. **Não escrever antes
da revisão jurídica.** Escrever mal isto é pior do que não ter a página.

---

## 8. O que se mede, e quando

| Métrica | Onde | Quando |
|---|---|---|
| Votos por sondagem ÷ subscritores · respostas ao quiz ÷ subscritores | Telegram | a cada C e E |
| Vistas por post, por formato | Telegram | domingo, no recap interno |
| `dropped` por difusão | painel do bot | 10 set, 24 set |
| Cliques em `telegram_bot_demo` / `telegram_bot_real` | HTI Funnel | semanal |
| `forex_go_*` por ferramenta · **contas abertas por `cid` de formato** | HTI Funnel · painel da corretora | semanal |
| Custo por utilizador do bot, por criativo | PropellerAds × contagens | fim de cada ronda |

A última linha continua a ser a única que decide se outubro é maior ou mais
pequeno do que setembro. A penúltima é a que decide **onde** o rodapé de
parceiro vive em outubro.

---

## 9. O que este calendário deliberadamente não faz

- **Não publica dois posts de interação no mesmo dia.** Um voto pedido de cada
  vez.
- **Não deixa o terceiro post virar obrigação.** A cadência é 1–3; os dias de
  1–2 não são falhas, são a regra a funcionar.
- **Não mete afiliado nos posts de interação.** A venda vive no âncora e nas
  peças de formato, rotulada (§4).
- **Não repete a difusão de parceiro ao bot.** Uma por mês, no máximo. A
  monetização diária vive no canal e nas páginas, não na caixa de entrada.
- **Não cresce o PT.** Decisão do mês; mantém-se.
- **Não toca em RBI/FEMA** antes da revisão jurídica (agora em
  `docs/Jogos_Questoes_Juridicas.md` está o precedente do formato para essa
  revisão).
