# Dossiê jurídico — secção `/games/` (jogos educativos)

**Data:** 31 de agosto de 2026
**Para:** revisão jurídica **L-D**, já aberta e marcada "bloqueador antes de divulgar"
(`STATUS.md:197-202`, `docs/Estado_e_Cronologia_Set2026.md:226-233`)
**Porquê:** a L-D ia acontecer de qualquer forma. Este documento existe para que
cubra também a secção nova, em vez de se descobrir depois que não cobriu.

> **O que se pretende decidir.** O dono quer comprar tráfego pago para as
> landing pages dos dois jogos. **Ainda não o fez.** As páginas também ainda não
> estão em produção. Nada aqui é irreversível.

> **Nota de leitura.** O documento é auto-suficiente: quem o ler não precisa de
> abrir o repositório. Os termos internos estão explicados na primeira
> ocorrência. As citações de código estão em inglês, como estão no ficheiro, com
> tradução a seguir. Cada uma traz `ficheiro:linha` para poder ser pedida.

> **Regra editorial.** Este documento relata o que o código e os documentos do
> projeto dizem. **Não dá parecer nem antecipa respostas.** Onde há decisão
> tomada, cita-se. Onde há lacuna, chama-se-lhe lacuna. Os pontos incómodos
> estão à frente e não no fim.

---

## 0. Resumo

| | |
|---|---|
| O que é | Dois jogos diários de literacia financeira, com dinheiro virtual |
| Onde vive | `howtoinvest.pro/games/` e `/pt/jogos/`, plugin isolado `hti-games` |
| Estado | Construído e testado; **não está em produção** |
| Dinheiro | Nenhum. Capital virtual, sem entrada, sem prémio, sem levantamento |
| Corretoras | **Nenhuma em lado nenhum da secção**, com teste automático a impedir |
| Publicidade | **Nenhum pixel**, nenhum script de terceiros, nenhum link para fora do site |
| Dados pessoais | Um identificador anónimo em cookie; alcunha e email **opcionais** |
| Idiomas | Inglês e português — logo, público da UE por construção |
| Já decidido | §8 — não precisa de parecer |
| Por decidir | §7 — dez perguntas |

---

## 1. O que são os dois jogos

A HowToInvest é uma plataforma educativa de literacia financeira. A secção
`/games/` é um plugin isolado: desativá-lo remove a secção inteira.

**Survive the Charts.** Mostra 80 velas de um gráfico de preços sem dizer que
mercado é. O jogador decide comprar, vender ou passar, e escolhe que fração de
uma conta virtual de 10 000 $ põe atrás dessa leitura — de 0,5% a 25%, com um
multiplicador opcional de ×2. A conta rebenta aos 1 000 $ e recomeça. **Os
gráficos são sintéticos**, gerados por computador a partir de uma semente fixa,
e a página diz isso: enquanto a biblioteca for gerada, a frase de entrada é *"um
mercado que se comporta como o real"* e nunca *"um gráfico histórico real"* — e
a frase muda sozinha se um dia forem importados dados verdadeiros, porque deriva
do próprio conteúdo e não de uma opção que alguém possa marcar.

O multiplicador chama-se **"dobrar a aposta"** e nunca "turbo"
(`class-config.php:84-90`). A mecânica é a mesma; a palavra foi mudada por ser a
única do desenho original que se lia como incentivo.

**The Reveal.** Mostra o dossiê anonimizado de uma empresa real num ano real:
setor, seis indicadores financeiros comparados com a média do setor, e três
manchetes do período. Sem nome. O jogador compromete 5, 10, 25 ou 50% da conta
virtual, ou passa. Só depois aparece o nome, o ano, o retorno a cinco anos e o
que um índice de mercado fez no mesmo período. **A §3 é sobre este jogo e é a
secção que mais precisa de parecer.**

**Comum aos dois:**

- Uma jogada por pessoa por dia, imposta por um índice único da base de dados e
  não por convenção de programação (`class-store.php:16-22`). A decisão é
  definitiva.
- O dia começa às 00:00 IST — 19:30 em Lisboa no verão. **Facto a registar:** o
  relógio é indiano porque o tráfego pago existente do site é indiano.
- Sem dinheiro real, sem execução de ordens em lado nenhum, sem prémios, sem
  registo obrigatório.
- **Nenhuma corretora.** Não é uma convenção: há um teste automático que varre
  todos os ficheiros do plugin **e renderiza as páginas nas duas línguas**,
  falhando a compilação se aparecer um link de afiliado, um slug de corretora, um
  módulo de parceria — ou qualquer link que saia do site.
- Disclaimer completo em todos os ecrãs e sob cabeçalho próprio em todas as
  páginas, nas duas línguas (`class-strings.php:115-118`):

  > *"This is an educational simulation. The money is virtual, nothing here is
  > executed anywhere, and nothing here is financial advice or a recommendation
  > to buy or sell any asset. Investing carries risk, including loss of capital.
  > Past outcomes say nothing about future ones."*

  > *"Isto é uma simulação educativa. O dinheiro é virtual, nada aqui é
  > executado em lado nenhum, e nada aqui é aconselhamento financeiro nem
  > recomendação de compra ou venda de qualquer ativo. Investir envolve risco,
  > incluindo a perda de capital. Resultados passados não dizem nada sobre os
  > futuros."*

- Classificação pública por alcunha, ordenada por pontuação **normalizada ao
  risco** — ver §6.

---

## 2. Que dados pessoais existem, e quais não existem

| Dado | Onde vive | Quando nasce | Como se apaga |
|---|---|---|---|
| Identificador anónimo (UUID) | Cookie `hti_gp`, 400 dias, + uma linha em tabela própria | **Só depois** de o onboarding terminar | Botão "apagar os meus dados", eliminação de conta, ou retenção automática |
| Registo do reconhecimento | Mesma linha (`ack_at`, `ack_ver`) | Com o UUID | Idem |
| Alcunha (opcional) | Mesma linha; **pública** na tabela | Só se o jogador a escolher | Idem |
| Email (opcional) | **`wp_users` do WordPress**, nunca nas tabelas do plugin | Só se pedir o link de acesso por email | Eliminação de conta |
| Subscrição da newsletter (opcional) | **Brevo**, fora do site | Caixa separada, por marcar, desligada por omissão | Link de cancelamento |

**O que não existe:** nenhum email e nenhum endereço IP nas duas tabelas do
plugin. O limitador de pedidos guarda `md5(ação|IP)` e nunca o IP em claro.

**Quem apenas abre a página não deixa nada.** O cookie só é escrito depois de a
pessoa passar o onboarding.

**Quatro caminhos de apagamento**, incluindo um para o jogador anónimo que
nenhum mecanismo baseado em email alcançaria, mais retenção automática (400 dias
por omissão).

### A posição sobre base de licitude, tal como está construída

Verbatim de `class-player.php:10-31`:

> *"The onboarding checkbox is an ACKNOWLEDGEMENT, not a consent basis. Its text
> is 'I understand this is an educational simulation with virtual money and no
> real trading' — it exists so nobody can later claim they thought the numbers
> were real. A box you are required to tick before you may play is by definition
> not freely given, so under Art. 4(11) / Art. 7(4) RGPD it cannot be leaned on
> as consent, and this codebase must never treat it as one. What we store is the
> fact of the acknowledgement (`ack_at`, `ack_ver`) — the record that the warning
> was shown and read, which is a different thing from permission to process.*
>
> *The identity cookie (`hti_gp`) is likewise not consent-based. It is strictly
> necessary for the service the visitor explicitly asked for: a
> once-per-person-per-day game cannot exist without a per-person handle, so it
> falls under the ePrivacy Art. 5(3) exemption and needs no banner. That
> exemption is only true while the cookie stays what it is here — one opaque
> random value, no profiling, no third party, no ad tech — which is why the row
> it points at holds no email and no IP."*

Tradução: a caixa do onboarding é um **reconhecimento** e não uma base de
consentimento — uma caixa obrigatória para poder jogar não é livremente dada, e
por isso o código guarda o facto de o aviso ter sido mostrado e lido, que é coisa
diferente de permissão para tratar dados. O cookie de identidade é argumentado
como **estritamente necessário** ao serviço pedido, ao abrigo do Art. 5(3) da
ePrivacy, e essa isenção só se mantém enquanto o cookie for o que é ali: um valor
aleatório opaco, sem perfilagem, sem terceiros e sem tecnologia publicitária.

> **Isto é a posição do código, à espera de confirmação. Não é uma conclusão, e
> é o objeto da pergunta 5.**

**Lacunas a nomear:**

1. **Não há qualquer verificação de idade** nem menção a menores em nenhuma
   superfície da secção — confirmado por pesquisa a todo o plugin. Ver a
   pergunta 6.
2. O texto do reconhecimento citado no comentário acima **não é literalmente** a
   frase que o jogador vê; a autoritativa é a string em
   `class-strings.php:220-221`.
3. `class-privacy.php:57-60` traz 180 dias como valor de recurso enquanto o
   valor vivo da retenção é 400. Divergência interna, sem efeito prático
   conhecido, mas registada.

---

## 3. The Reveal e as empresas nomeadas

**A secção que mais precisa de parecer.**

### A regra do projeto, e a exceção

O projeto proíbe nomear empresas em qualquer resultado do seu motor de
recomendação (invariante 2 do `CLAUDE.md`). The Reveal tem uma exceção escrita e
delimitada: só dentro do tipo de conteúdo dos casos, só para períodos com pelo
menos **cinco anos**, só depois de o jogador ter decidido, **nunca** como
afirmação prospetiva ou sugestão de compra.

### Todos os números são reconstruções

Verbatim do cabeçalho de `class-seed-cases.php:3-22`:

> *"READ THIS BEFORE TAKING A FIGURE OUT OF HERE AND USING IT FOR ANYTHING.
> Every number in this file is a RECONSTRUCTION. The companies are real, the
> years are real, and the direction of what happened next is real; the ratios,
> the sector averages, the headlines and the two five-year returns were written
> to make a pattern legible to a beginner, not copied out of a filing. No line of
> this was checked against a document, because the environment it was written in
> had no way to open one — and model memory is never a publishable source.*
>
> *That is a deliberate product decision, taken by the owner with the constraint
> in front of him: a case library that cannot be played is not a case library.
> What makes it honest is not the figures, which nobody has verified — it is that
> every case SAYS SO."*

Tradução: **todos os números do ficheiro são reconstruções.** As empresas, os
anos e a direção do que aconteceu a seguir são reais; os rácios, as médias de
setor, as manchetes e os dois retornos a cinco anos foram escritos para tornar um
padrão legível a um principiante, **não copiados de um relatório**. Nada foi
verificado contra um documento, porque o ambiente onde foi escrito não tinha como
abrir um — e a memória do modelo nunca é fonte publicável.

Esta última frase é a regra do próprio projeto, em
`.claude/skills/financial-analyst/SKILL.md`: *"Model memory is never a source"*,
fontes primárias, regra das duas fontes, *"can't verify it → don't publish it"*.

### A decisão de proveniência, registada como o que é

**Decisão do dono, 30 de agosto de 2026**, tomada com a restrição à frente: as
opções eram casos por preencher e injogáveis, ou casos publicados **declarados**
como reconstruções. Escolheu-se a segunda.

**Facto relevante:** a versão anterior do invariante exigia fonte verificada em
**todos** os casos. A versão atual é que abre a via ilustrativa, com a declaração
ao jogador como contrapartida obrigatória. Do `CLAUDE.md` atual, invariante 2:

> `illustrative` — os números são **reconstruções do padrão**, não extratos de um
> relatório. Publica com o dossiê completo e os cinco anos de idade, sem fonte e
> sem verificação, **e o ecrã de resultado diz ao jogador exatamente isso**. […]
> A declaração não é dispensável — é o que torna a reconstrução honesta em vez de
> uma afirmação factual por verificar.
>
> `verified` — os números saíram de um documento publicado. Exige
> `hti_rev_source_url` e verificação registada, e mostra a fonte em vez daquela
> linha. É o estado para onde um editor promove um caso, e é o valor por omissão
> de tudo o que não se declare ilustrativo.

### Como é declarado ao jogador

Uma linha no ecrã da revelação, exatamente onde um caso verificado mostraria a
sua fonte (`class-strings.php:702-705`):

> *"The company, the period and the direction of what happened next are real. The
> figures and the headlines are reconstructed to show the pattern, not copied
> from a filing or quoted from a newspaper."*

> *"A empresa, o período e a direção do que aconteceu a seguir são reais. Os
> números e as manchetes são reconstruídos para mostrar o padrão, não foram
> copiados de um relatório nem citados de um jornal."*

Mais o marcador **"— illustrative reconstruction"** no título de cada caso,
visível na administração.

### As 34 empresas

| Fraude / contabilidade contestada | Rutura tecnológica | Balanço | Ainda cotadas hoje |
|---|---|---|---|
| Enron 2000, WorldCom 2001, Parmalat 2002, Satyam 2008, Tyco 1999, Tesco 2013, Valeant 2015, Carillion 2016 | Nokia 2007, Kodak 2005, Blockbuster 2004, Research In Motion 2010, Lucent 1999, Cisco 2000 | Northern Rock 2006, SVB Financial 2021, SLM 2006 | Apple 1997, Amazon 2001, Coca-Cola 2010, Moody's 2006, Salesforce 2008, Copart 2010, Cintas 2006, Domino's 2009, Skyworks 2015, Snap 2017, GoPro 2014, Plug Power 2014, Freeport-McMoRan 2007, Peabody 2011, J. C. Penney 2012, Blue Apron 2017, Pets.com 1999 |

**A direção real de cada desfecho está presa por um teste automático**: 21
empresas com a direção fixada pelo nome, de modo que um rácio reconstruído
continua a ser uma reconstrução mas um desfecho invertido rebenta a compilação.
A razão está escrita no teste: caso contrário *"a beginner would be taught that
the fraud paid"*.

### Barreiras

O portão de publicação e a consulta que escolhe o caso do dia verificam ambos a
mesma coisa, de forma independente. Um caso que se diga verificado não publica
sem URL de fonte e sem visto de quem a leu; **mexer em qualquer um dos números
verificados limpa o visto**. O valor por omissão falha fechado.

Cada caso traz também um guia de investigação que diz que **tipos** de documento
abrir e que rubrica alimenta cada campo — nunca um URL, uma referência de
arquivo ou uma data, porque isso forjaria a trilha de auditoria.

### Uma correção feita durante esta revisão

O cartão de regras do onboarding dizia, **antes** de o jogador decidir, que o
resultado é *"o que a empresa realmente rendeu"* nos cinco anos seguintes —
enquanto em todos os 34 casos esse retorno é reconstruído, e a linha corretiva só
aparecia depois da decisão. **Encontrado nesta revisão, a 31 de agosto de 2026, e
corrigido na mesma passagem**: a regra passa a dizer que o resultado *segue o que
realmente aconteceu à empresa*, verdadeiro nos dois tipos de caso. Foi
acrescentado um teste que impede qualquer frase pré-decisão de prometer um número
auditado.

Fica registado para que, se a redação antiga aparecer numa página em cache, se
saiba que foi corrigida.

### Pontos fracos, sem os suavizar

1. **Números reconstruídos ficam ao lado do nome de empresas ainda em
   atividade**, e vários casos são de fraude ou de contabilidade contestada. É
   este o principal ponto de exposição.
2. **As manchetes são a parte mais citável e a menos ancorada.** Nunca vão entre
   aspas nem atribuídas a uma publicação — mas são reconstruções de afirmações
   sobre empresas reais num momento real.
3. **Nenhum caso tem hoje fonte nem visto**, e os 34 dossiês **nunca tiveram
   revisão editorial humana** (`STATUS.md:363-365`).
4. **Mesmo depois da correção, a declaração de reconstrução aparece depois da
   decisão do jogador.** É onde tem de estar para o jogo funcionar, mas é um
   facto a pôr à frente e não a esconder.
5. **SVB Financial 2021** é o caso mais recente e cumpre a regra dos cinco anos
   por margem mínima em 2026.

---

## 4. O que distingue isto do `/forex/`

O site já tem uma secção com corretoras e tráfego pago. **São coisas diferentes.**

| | `/forex/` (`hti-forex`) | `/games/` (`hti-games`) |
|---|---|---|
| Idioma | Só inglês — exceção documentada | Inglês **e português** |
| Público-alvo | Índia | Inclui a UE por construção |
| Corretoras | CTA de parceiro e espaços de banner | **Nenhuma**, com teste a impedir |
| Pixel publicitário | Propeller, **sem gate de consentimento** | **Nenhum** |
| Tráfego pago | Já corre | Ainda não |

A decisão sobre o pixel do `/forex/` está registada três vezes, e as três
delimitam-na. Verbatim de `hti-forex/includes/class-tools.php:57-63`:

> *"Print the Propeller Ads audience pixel — ONLY on the forex pages (the
> paid-campaign landers) and only when a partner id is configured. **Deliberately
> not consent-gated: the campaigns target outside the EU and the owner accepted
> the residual exposure for EU visitors to /forex/** (decision recorded 2026-08;
> the rest of the site stays consent-gated)."*

Tradução: o pixel carrega **apenas** nas páginas forex e apenas com um
identificador de parceiro configurado. **Deliberadamente sem gate de
consentimento: as campanhas visam fora da UE e o dono aceitou a exposição
residual dos visitantes da UE ao `/forex/`** (decisão registada em agosto de
2026; o resto do site mantém-se sob consentimento).

O painel de administração repete-o (`class-settings.php:683`), e a cronologia do
projeto regista a decisão como **temporária** (`Estado_e_Cronologia_Set2026.md:235`):

> *"(O pixel da Propeller sem gate de consentimento é deliberado e sai quando o
> teste acabar.)"*

**Ou seja:** a decisão existente é limitada ao `/forex/`, justificada por o
tráfego ser fora da UE, e declarada temporária. Os jogos são bilingues.

O âmbito já declarado da L-D inclui ainda o `ads.txt` com identificador de editor
real sem categoria de marketing no banner de consentimento.

---

## 5. O banner de consentimento, como está construído

Duas categorias e só duas: **essenciais** (sempre ativas) e **analítica**. O
cookie de consentimento guarda um único indicador e mais nada. Recusa por
omissão.

> **Não existe categoria de marketing ou publicidade.** É um facto verificado no
> código, não uma opinião, e é o que torna a pergunta 3 concreta.

---

## 6. O quadro regulatório que o projeto já escreveu

De `.claude/skills/broker-affiliate/SKILL.md`, que é o documento interno que
governa a secção de corretoras:

- **CMVM** (entendimento de 13/03/2025, "finfluencers"): publicidade e captação
  de clientes **por conta de** um intermediário financeiro está reservada a
  intermediários e agentes vinculados. Disclaimers genéricos não bastam; a
  relação de afiliação tem de ser divulgada em cada página e canal; o
  intermediário é corresponsável.
- **ESMA**: incentivos monetários **ligados a negociação de CFD por retalho**
  estão proibidos na UE; publicidade a CFD exige o aviso de risco.

> **Ponto a sublinhar, sem tirar conclusão.** Ambos os ganchos, tal como o
> projeto os escreveu, estão **condicionados à existência de uma corretora** — a
> CMVM a captar *"on behalf of"* um intermediário, a ESMA a incentivos *"tied to
> retail CFD trading"*. Na secção `/games/` não há corretora, não há produto CFD
> e não há sequer um link para fora do site. O projeto **não sabe** se a ausência
> de corretora retira o caso do âmbito, e não é quem decide isso. É por isso que
> a §7 existe.

O projeto escreveu também a razão de a secção não levar corretoras: um ranking
competitivo que premiasse alavancagem, ao lado de um CTA de corretora, estaria
demasiado perto da linha. E a exceção do `/forex/` está expressamente fechada por
âmbito, sem se estender por analogia.

---

## 7. As perguntas

**1. Tráfego pago para um simulador de negociação alavancada, com tabela
classificativa pública, com audiência que inclui a UE, e sem qualquer relação
com corretora — pode?**
E em concreto: que pode e que não pode dizer a criativa? O projeto já se proíbe
internamente de escrever taxas de acerto, "garantido", "lucro", "oportunidade" e
alavancagem como argumento de venda. Essa linha própria é suficiente? Pode a
criativa mostrar um número de resultado? Uma posição no ranking? A palavra
"trading"? O valor "10 000 $" sem a palavra "virtual" ao lado?

**2. Séries, distintivos e classificação são "incentivos" para efeito da ESMA
quando não há produto CFD nem corretora em nenhum ponto do funil?**
Factos: os prémios são dígitos numa conta virtual, não há levantamento, não há
valor transferível, não há sorteio. E o ranking diário é **normalizado ao risco**
precisamente para não premiar o tamanho da posição — do código
(`class-scoring.php:328-348`): *"Normalising by the risk taken removes the reward
for size entirely: two players who read the same chart the same way score the
same, and the one who bet the account gains nothing on the board for it."*

**3. O que é preciso antes de um pixel publicitário poder correr numa secção
virada à UE — e o banner atual, que não tem categoria de marketing, tem de passar
a ter uma?**
Contexto: o `/forex/` corre um pixel sem gate sob decisão registada, limitada e
declarada temporária; `/games/` não tem pixel nenhum. Comprar tráfego pago
implica, no mínimo, medir. Que muda se a medição for apenas a analítica de
primeira parte já existente, que não usa cookies nem identificadores?

**4. Nomear empresas reais com números declaradamente ilustrativos cria
exposição — e a declaração, tal como está escrita, faz o trabalho?**
Contexto: o texto da declaração nas duas línguas, o marcador no título, o momento
em que aparece (**depois** da decisão), e a lista das 34 empresas com os casos de
fraude assinalados.

**5. A posição "reconhecimento e não consentimento" no checkbox do onboarding
está correta, e o cookie de identidade é mesmo estritamente necessário?**
Contexto: o excerto citado na §2, o facto de o cookie só nascer depois do
onboarding, e a duração de 400 dias.

**6. Menores.**
Não há verificação de idade nem menção a idade em nenhuma superfície da secção.
Um jogo com tabela pública, alcunha e caixa de newsletter é atrativo para
menores. Que é preciso — idade mínima nos termos, gate no onboarding, tratamento
do Art. 8 do RGPD para a newsletter?

**7. A tabela pública é publicação de dados pessoais pseudonimizados.**
A alcunha é escolhida pelo jogador e pode ser identificável. O código já não
mostra percentagens do dia abaixo de 20 jogadores, **explicitamente porque abaixo
disso a percentagem é invertível até se saber quem perdeu**. Há obrigação de
informação, ou de opção de saída da tabela?

**8. Enquadramento do próprio jogo.**
Simulação com capital virtual, sem entrada, sem prémio, com resultado dependente
de perícia e de dados. Confirmar que não cai em nenhuma definição de jogo a
dinheiro ou de sorte aplicável em Portugal ou noutro mercado da UE onde as
páginas fiquem indexadas.

**9. Relação com o `/forex/`.**
As duas secções vivem no mesmo domínio, e o relógio do jogo é indiano porque o
tráfego pago existente é indiano. Se o mesmo domínio tem um funil com corretora e
uma secção sem corretora, a separação técnica é suficiente, ou é preciso
separação editorial visível?

**10. Termos e política de privacidade.**
Ambos têm ainda marcadores por preencher, nunca foram revistos, e nenhum descreve
a secção `/games/`.

---

## 8. O que já está decidido e não precisa de parecer

Para poupar tempo. Estes pontos estão fechados no código, com testes automáticos
a impedir a regressão:

- Nenhuma corretora, nenhum link de afiliado, nenhum banner de parceiro, **nenhum
  link que saia do site** — com teste que renderiza as páginas e falha.
- Sem prémios, sem dinheiro real, sem execução de ordens.
- Ranking normalizado ao risco, e não por resultado bruto.
- Disclaimer completo em todos os ecrãs e páginas, nas duas línguas.
- Apagamento por quatro caminhos, incluindo o do jogador anónimo, mais retenção
  automática.
- Sem email e sem IP nas tabelas do plugin.
- Casos com menos de cinco anos não são servidos; um caso que se diga verificado
  não publica sem fonte e sem visto.
- Sem pixel publicitário e sem categoria de marketing na secção.

---

## 9. Anexo — mapa de ficheiros citados

| Ficheiro:linha | O que diz |
|---|---|
| `CLAUDE.md` invariante 2 | A exceção das empresas nomeadas e as duas proveniências |
| `CLAUDE.md` invariante 9 | Nenhuma corretora nos jogos, e porquê |
| `wp-content/plugins/hti-games/includes/class-player.php:10-31` | A posição sobre base de licitude |
| `…/includes/class-seed-cases.php:3-22` | Que todos os números são reconstruções |
| `…/includes/class-strings.php:115-118` | O disclaimer completo, EN e PT |
| `…/includes/class-strings.php:702-705` | A declaração de reconstrução, EN e PT |
| `…/includes/class-scoring.php:328-348` | Porque o ranking é normalizado ao risco |
| `…/tests/test-seed-cases.php:255-299` | As direções reais presas por teste |
| `…/tests/test-no-brokers.php` | O controlo automático anti-corretoras |
| `wp-content/plugins/hti-forex/includes/class-tools.php:57-63` | A decisão do pixel sem gate, e o seu âmbito |
| `wp-content/plugins/hti-forex/includes/class-settings.php:683` | A mesma decisão, no painel de administração |
| `docs/Estado_e_Cronologia_Set2026.md:235` | Que essa decisão é temporária |
| `docs/Estado_e_Cronologia_Set2026.md:226-233` | O âmbito real da L-D |
| `STATUS.md:197-202` | A L-D como bloqueador antes de divulgar |
| `STATUS.md:363-365` | Que os 34 dossiês nunca tiveram revisão humana |
| `.claude/skills/broker-affiliate/SKILL.md:13-27` | As regras CMVM e ESMA como o projeto as escreveu |
| `.claude/skills/financial-analyst/SKILL.md` | "Model memory is never a source" |
