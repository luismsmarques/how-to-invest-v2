# Reels AI — Playbook da Fase 0 (validação manual)

**Objetivo:** validar em 1-2 semanas, com 3-5 reels feitos à mão, se o formato
"notícia → reel com b-roll AI + voz + legendas" gera alcance e cliques — **antes**
de investir semanas a construir a integração Higgsfield no `hti-social`.

**Decisões já tomadas:** formato b-roll AI + voz TTS + legendas grandes; PT + EN do
mesmo guião; canais IG, FB, YT Shorts, TikTok; publicação manual nesta fase; o dono
aprova sempre antes de publicar.

**Invariantes (aplicam-se a vídeo como a tudo o resto):** sem tickers/instrumentos/
corretoras nomeadas; linguagem condicional e educativa ("costuma", "pode"), nunca
imperativa ("compra", "investe já"); **disclaimer visível no end-card de todos os
reels**; sem promessas de retorno.

---

## Passo 1 — Escolher a notícia (2 min)

Das notícias publicadas em `/financial-news/`, escolhe as que têm:
- **Relevância imediata** (taxas, inflação, mercados a mexer, decisões do BCE) — não
  histórias que já morreram há 3 dias;
- **Ângulo explicável** — a notícia permite responder "o que é que isto significa
  para quem poupa/investe?" em 30 segundos;
- **Potencial visual** — dá para imaginar 4-5 cenas (banco central, gráfico, carteira,
  pessoa a pensar, cidade financeira).

## Passo 2 — Gerar o guião (5 min)

Cola o prompt abaixo no Gemini (AI Studio ou app), junto com o texto da notícia.
Revê o output à mão contra os invariantes antes de avançar.

```text
És um roteirista de reels educativos de literacia financeira para a marca
HowToInvest (howtoinvest.pro). A partir da notícia abaixo, escreve um guião de
reel vertical de 30-40 segundos, em JSON, com esta estrutura:

{
  "hook_pt": "...", "hook_en": "...",          // ≤3s, pergunta ou tensão, sem clickbait falso
  "beats": [                                    // 3 a 4 beats de 6-9s cada
    { "pt": "...", "en": "...", "visual": "..." }
  ],
  "cta_pt": "...", "cta_en": "...",            // educativo: "sabe mais em howtoinvest.pro" ou "descobre o teu perfil de investidor" — NUNCA "compra/investe"
  "caption_pt": "...", "caption_en": "...",    // caption para IG/TikTok com 3-5 hashtags
  "visual_style": "..."                        // 1 frase de direção visual consistente para todos os clips
}

Regras obrigatórias:
- Linguagem condicional e educativa ("costuma", "pode", "tende a"), nunca conselhos
  nem imperativas de investimento.
- PROIBIDO nomear tickers, ações individuais, fundos, corretoras ou criptomoedas
  específicas. Classes de ativos (ações globais, obrigações, liquidez…) são ok.
- Cada "visual" é um prompt de text-to-video em inglês para um clip de 5-8s,
  vertical 9:16, cinematográfico, SEM texto embutido, SEM logotipos, SEM caras de
  pessoas reais reconhecíveis.
- O beat final deve aterrar sempre em "o que isto significa para quem está a
  aprender a investir".
- Português de Portugal, tratamento por "tu".

NOTÍCIA:
[colar aqui o texto da notícia]
```

## Passo 3 — Gerar os clips no Higgsfield (10-15 min)

1. Conta free ou Starter ($15) em higgsfield.ai — suficiente para o teste.
2. Para cada `visual` do guião: text-to-video, **9:16**, 5-8s, modelo mais barato
   que fique decente (não gastes créditos premium na Fase 0).
3. Junta o `visual_style` ao fim de cada prompt para manter coerência entre clips.
4. 1 re-geração no máximo por clip — o objetivo é validar o formato, não a perfeição.
5. Descarrega os MP4.

## Passo 4 — Montar o reel (15-20 min)

Ferramenta livre nesta fase (CapCut é o mais rápido; iMovie/Premiere também servem):
1. Sequência: hook → beats → end-card. Cortes secos, sem transições fancy.
2. **Voz:** TTS do CapCut ou a tua voz. (Na Fase 1 será o TTS Gemini já existente no
   plugin.) Versão PT e versão EN a partir do mesmo guião.
3. **Legendas grandes** sempre visíveis (80% vê sem som), palavra-a-palavra ou por
   frase curta, dentro da zona segura (evitar os 15% do topo e do fundo).
4. **End-card com disclaimer:** gera um card 1080×1920 no **Social → Cards** do
   wp-admin (os templates já trazem o disclaimer da marca) e usa-o como última cena
   (2-3s): logo + "Conteúdo educativo, não é aconselhamento financeiro" + CTA.
5. Export 1080×1920 MP4.

## Passo 5 — Publicar com UTM (5 min por canal)

Link na bio/caption/comentário fixado, sempre com UTM para medirmos:

```
https://howtoinvest.pro/financial-news/<slug-da-noticia>/?utm_source=<canal>&utm_medium=reel&utm_campaign=news-reels-f0
```

`<canal>` = `instagram` | `facebook` | `youtube` | `tiktok`. (No IG o link só vive na
bio — usa o da bio com `utm_source=instagram` genérico.)

- **IG Reels** → ativar partilha automática para o **Facebook**.
- **YT Shorts** → título com a pergunta do hook; link na descrição.
- **TikTok** → mesmo vídeo; caption própria.
- Publicar PT e EN como posts separados (não misturar línguas no mesmo post).
- Melhor janela típica PT: 12h-14h ou 19h-21h. Consistência > hora perfeita.

## Passo 6 — Medir (folha de métricas)

Uma linha por reel por canal. Ver GA4 (Aquisição → utm_campaign=news-reels-f0) para
os cliques; views/retenção nas apps de cada rede após 24h e 7 dias.

| Data | Notícia (slug) | Canal | Língua | Views 24h | Views 7d | Retenção % | Likes | Partilhas | Seguidores +/- | Cliques UTM |
|---|---|---|---|---|---|---|---|---|---|---|
| | | | | | | | | | | |

Guarda também, por reel: custo em créditos Higgsfield e tempo total de produção —
são os números que decidem se a Fase 1 compensa.

## Gate — critérios para construir a Fase 1

Avançamos para a integração automática se, ao fim de 3-5 reels × 2 semanas:
- **Alcance:** pelo menos 1 reel claramente acima da média atual das tuas redes
  (ordem de grandeza, não +10%);
- **Retenção:** ≥40% de retenção média num dos canais (sinal de que o formato prende);
- **Custo/tempo viável:** ≤45 min de produção manual por reel e custo de créditos
  compatível com o plano que estás disposto a pagar (isto é o que a Fase 1 automatiza);
- **Zero incidentes** de invariantes (nada publicado com conselho/ticker/sem disclaimer).

Se falhar: iteramos o formato (hooks, estilo visual, duração) — ainda sem código.
Se passar: Fase 1 = guião automático + cliente Higgsfield + fila de aprovação no
`hti-social` (plano aprovado em sessão; ver skill `social-media`).

---

*Fase 0 não escreve código. A Fase 1 só arranca com o gate passado — decisão com
dados, não com entusiasmo.*
