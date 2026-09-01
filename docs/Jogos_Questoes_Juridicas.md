# Jogos (`hti-games`) — questões para a revisão jurídica

_Preparado em 31 ago 2026 para a revisão **L-D**, que o `STATUS.md` marca como bloqueador
de divulgação. **Isto não é aconselhamento jurídico** — é a preparação que torna a revisão
barata: os factos verificados contra o código, com `ficheiro:linha`, e as perguntas em
aberto. Onde há uma leitura minha, está identificada como tal e não substitui a resposta._

**Estado:** os dois jogos não estão em produção. Não foi comprado tráfego pago para eles.
Nada aqui bloqueia o resto do site.

---

## 1. Contexto em trinta segundos

Site educativo de literacia financeira. Dois jogos diários, capital virtual, sem execução
de ordens, sem corretora em ponto nenhum da secção, sem prémios, sem registo obrigatório.
Bilingues EN+PT — logo, público da UE por construção.

O jogo **The Reveal** mostra o dossiê anonimizado de uma empresa real num ano real. A
biblioteca inicial tem 34 casos, entre eles Enron, WorldCom, Parmalat, Satyam, Tesco,
Valeant, e empresas cotadas hoje como Apple, Amazon e Coca-Cola.

---

## 2. Correções a uma versão anterior deste documento

Uma versão anterior deste briefing continha três afirmações que **não correspondem ao
código**. Ficam corrigidas aqui porque um parecer construído sobre elas responderia a
problemas que não existem — e deixaria por responder os que existem.

**(a) "A declaração aparece depois da decisão." Não é assim.** O terceiro cartão de
onboarding, mostrado antes de jogar, diz (`class-strings.php:338`):

> Every case is historical, at least five years past, and none of it is a view on that
> company today. At the end, each case tells you whether its figures came out of a
> published document or were reconstructed to show the pattern.

O que chega depois da decisão é o veredicto **por caso** — qual dos dois este era. O
comentário no próprio código regista a razão: *"A disclosure that arrives after the
decision is not a disclosure; this says the same thing while the player can still act on
it."* A pergunta certa não é se falta divulgação prévia; é se **esta** divulgação chega.

**(b) "Nada foi verificado contra um documento." Incompleto.** O modelo de dados já
distingue casos `verified` de `illustrative`, e um caso marcado verificado **não pode ser
publicado sem o URL do documento** (`class-case-admin.php:611`, `class-cpt.php:63`). Os 34
que vêm de origem são ilustrativos, mas a máquina para verificar existe e é usada pelo
mesmo ecrã de administração. Isto transforma a questão de *"isto é defensável?"* em
*"quais têm de passar a verificados, e segundo que critério?"*.

**(c) "A tabela pública precisa de opção de saída." Já tem uma, estrutural.** Só aparecem
no quadro os jogadores que escolheram uma alcunha (`class-leaderboard.php:208,229`), e quem
não escolhe tem `rank` 0 e não está em quadro nenhum (`:264`). **Escolher a alcunha é a
adesão.** O que falta verificar é mais estreito: se a pessoa é avisada, no momento em que
escolhe, de que aquilo fica público.

---

## 3. Os factos, verificados contra o código

Para o revisor não ter de acreditar em prosa.

| Facto | Onde |
|---|---|
| Cookie de identidade `hti_gp`, um UUID, **400 dias**, `HttpOnly`, `SameSite=Lax`, `Secure` quando há TLS | `class-player.php:66,77,202-215` |
| Justificação registada: estritamente necessário ao serviço pedido — um jogo por pessoa por dia não existe sem identificador | `class-player.php:21` |
| Razão declarada para os 400 dias: **é o teto que o Chrome impõe desde 2022** | `class-player.php:74-77` |
| A caixa obrigatória está tratada como **reconhecimento, nunca consentimento**, por a caixa obrigatória não ser livremente dada | `class-strings.php:207-214` |
| A versão do texto reconhecido é guardada (`ack_ver`, `ack_at`) | `class-store.php:179-180` |
| A tabela guarda: uuid, user_id, alcunha, reconhecimento, flag de newsletter, séries, capital, datas. **Sem email, sem IP** | `class-store.php:172-197`; `class-privacy.php` |
| O email, quando existe, vive em `wp_users` — dentro da cascata de eliminação do WordPress | `class-privacy.php` |
| Quatro caminhos de direitos do titular: hti-engine, ferramentas do core, `DELETE /games/me` para anónimos, e retenção por inatividade | `class-privacy.php:1-40` |
| Newsletter: caixa separada, **por omissão desligada**, genuinamente opcional | `class-strings.php:211-213` |
| Quadro público: só quem escolheu alcunha; sem alcunha não há classificação | `class-leaderboard.php:208,229,264` |
| Casos: `verified` exige URL do documento; `illustrative` é publicável como tal | `class-case-admin.php:611,921` |
| Cada caso tem de ser de um ano com uma antiguidade mínima | `class-case-admin.php:573` |
| Ranking normalizado ao risco, para não premiar tamanho de posição | `class-leaderboard.php` (`board_score`) |
| **Idade: não existe uma única linha sobre idade de utilizador em todo o plugin** | verificado por pesquisa em `includes/` |

---

## 4. Perguntas, por ordem de custo

Reescritas em aberto. A versão anterior trazia a conclusão dentro da pergunta — *"está
argumentado como estritamente necessário"*, *"Factos a dar-lhe: os prémios são dígitos numa
conta virtual"* — o que convida a validar a tese em vez de responder à questão.

### A — Bloqueiam o lançamento, mesmo sem anúncios

**Q1. Empresas reais com números reconstruídos.**
Os casos nomeiam empresas reais e anos reais. A empresa, o período e a direção do que
aconteceu a seguir são verdadeiros; os rácios, as médias de setor, as manchetes e os
retornos são reconstruções escritas para tornar o padrão legível. A divulgação prévia está
citada em §2(a) e a por-caso aparece no ecrã de resultado.

> **Que tratamento distinto exigem (i) empresas extintas com fraude julgada — Enron,
> WorldCom, Parmalat, Satyam — e (ii) emitentes cotados hoje — Apple, Amazon, Coca-Cola?
> Em particular: atribuir a um emitente cotado uma manchete de jornal inventada é
> diferente, em natureza e não só em grau, de lhe atribuir um rácio reconstruído?**

_Leitura minha, não jurídica:_ o risco não é uniforme e tratá-lo como bloco custa conteúdo
sem reduzir exposição. As manchetes fabricadas atribuídas a emitentes vivos são a peça que
eu retirava primeiro, porque lêem-se como afirmação de facto sobre a empresa. A separação
verificado/ilustrativo já existe no código para suportar a resposta, qualquer que ela seja.

**Q2. Base de licitude.**
A caixa obrigatória está tratada como reconhecimento e não consentimento. O documento
anterior dizia o que a base **não** é e nunca dizia o que ela **é**.

> **(a) Qual é a base de licitude do tratamento — execução de contrato (Art. 6(1)(b)) para
> o estado do jogo, interesse legítimo (6(1)(f)) para o quadro público, outra? Se houver
> 6(1)(f), que forma tem de ter a avaliação de interesse legítimo?**
>
> **(b) O cookie de identidade qualifica como estritamente necessário na aceção do
> Art. 5(3) ePrivacy, para um serviço cuja regra é um jogo por pessoa por dia?**
>
> **(c) Que duração é defensável para esse cookie? A duração atual é 400 dias e a razão
> registada no código é que é o teto que o Chrome impõe — um limite técnico, não uma
> justificação de necessidade. Impedir uma segunda jogada no mesmo dia precisa de horas;
> transportar a série e o capital entre dias precisa de mais. Que prazo suporta cada
> função?**

**Q3. Menores.**
Não existe verificação de idade nem menção a idade em lado nenhum, ao lado de um quadro
público, uma alcunha e uma caixa de newsletter.

> **(a) Que idade mínima deve constar dos termos, e é preciso um gate no onboarding ou
> basta a declaração?**
>
> **(b) A newsletter assenta em consentimento e o Art. 8 aplica-se. Qual é a idade
> relevante nos Estados-membros que o site serve, e que forma tem de ter a verificação?**
>
> **(c) O quadro público com alcunha escolhida pela própria pessoa muda a resposta?**

**Q4. Quadro público.**
A alcunha é escolhida pelo jogador, pode identificá-lo, e é publicada. A adesão é
estrutural: sem alcunha não há presença no quadro (§2(c)).

> **Sendo a escolha da alcunha a própria adesão, que informação tem de ser dada no momento
> em que ela é escolhida, e é suficiente para dispensar um consentimento autónomo?**

**Q5. Termos e política de privacidade.**
Têm marcadores `[●]` por preencher, nunca foram revistos, e não descrevem esta secção.
Não é uma pergunta — é trabalho identificado, e o mesmo achado já consta da auditoria de
30 ago.

### B — Bloqueiam os anúncios

**Nota antes das três:** existe aqui um portão que não é jurídico e que se verifica hoje
sem custo — **a política da própria plataforma**. As redes têm regras próprias para
produtos financeiros e um simulador de trading com quadro público pode ser recusado
independentemente de ser legal. Ler a política do destino responde a metade da Q6 antes de
alguém ser pago para a responder.

**Q6. Tráfego pago para o simulador.**

> **Um simulador de negociação alavancada com capital virtual e quadro público, sem
> qualquer relação com corretora, pode ser promovido por tráfego pago a público que inclui
> a UE? E que restrições recaem sobre a criativa — pode mostrar um resultado, uma posição
> no ranking, a palavra "trading", ou um valor monetário sem a palavra "virtual" ao lado?**

_Leitura minha:_ a regra barata e provavelmente suficiente é **"virtual" adjacente a todo o
valor monetário, sempre**.

**Q7. Séries, distintivos e classificação.**
Não há produto CFD nem corretora em ponto nenhum deste funil. O capital é virtual, sem
levantamento e sem valor transferível; não há sorteio; e o ranking é normalizado ao risco.

> **Estes elementos constituem "incentivos" na aceção das medidas de intervenção da ESMA
> quando não existe produto CFD nem corretora em nenhum ponto do funil? E se não
> constituem hoje, o que mudaria essa resposta?**

**Q8. Pixel publicitário.**
O banner de consentimento tem hoje duas categorias e nenhuma de marketing. A decisão
existente sobre o pixel do `/forex/` está registada como justificada por o tráfego ser fora
da UE, limitada àquela secção, e temporária.

> **O que tem de existir antes de um pixel publicitário correr numa secção virada à UE, e
> confirma-se que a justificação usada no `/forex/` não se transfere?**

**Q9. Duas secções no mesmo domínio.**
O mesmo domínio serve um funil com corretora (`/forex/`) e esta secção sem corretora. O
relógio do jogo é IST porque o tráfego pago existente é indiano.

> **A separação técnica é suficiente, ou é necessária separação editorial visível, marca
> distinta ou domínio distinto? Dito de outro modo: um regulador que olhe para o domínio
> inteiro vê duas secções ou um funil único que gamifica trading e vende registos em
> corretora?**

_Leitura minha:_ é a questão estrutural de maior risco do conjunto e estava escrita como
nota final. Os jogos fazem o funil parecer o destino mesmo sem um único link entre os dois.

### C — Confirmar

**Q10. Enquadramento como jogo.**
Capital virtual, sem entrada, sem prémio de valor transferível, sem sorteio; o resultado
depende de perícia e de dados históricos reais.

> **Cai em alguma definição de jogo a dinheiro ou de sorte nas jurisdições servidas?**

_Leitura minha:_ não. Falha a aposta e falha o prémio. É a pergunta mais barata do
conjunto — uma linha de confirmação, não uma análise.

---

## 5. O que faço independentemente da resposta

Itens baratos que não precisam de parecer para serem obviamente certos:

1. **Preencher os termos e a política**, e passar a descrever esta secção (Q5).
2. **Idade mínima nos termos** e decisão sobre o gate da newsletter (Q3).
3. **Dizer, no ecrã da alcunha, que ela fica pública** (Q4).
4. **Categoria de marketing no banner de consentimento**, antes de qualquer pixel (Q8).
5. **Rever a duração do cookie** contra a função que a justifica, em vez do teto do
   browser (Q2c).
6. **"Virtual" ao lado de todo o valor monetário**, na criativa e no produto (Q6).

## 6. O que não precisa de ir ao revisor

Já está decidido, implementado e defensável — vale registá-lo para não consumir tempo pago:

- A caixa obrigatória **não** é usada como base de licitude, e a razão está no código.
- O quadro público é **adesão por ação**, não saída por pedido.
- Existem **quatro** caminhos de direitos do titular, incluindo um para anónimos.
- A tabela **não guarda email nem IP**.
- A newsletter é separada, desligada por omissão e opcional.
- O ranking é **normalizado ao risco**, por desenho, para não premiar tamanho de posição.
