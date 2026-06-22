# Google News — Checklist de ativação (PT + EN)

O código já trata da parte técnica. Falta a configuração operacional, que só
pode ser feita por ti (não há API para isto).

## O que o código já faz (hti-engine)

- **Sitemap de notícias** em `https://howtoinvest.pro/news-sitemap.xml`
  - Lista apenas artigos `news` das últimas 48h (regra do Google News).
  - Cada artigo declara o seu próprio idioma (`<news:language>` = `pt` ou `en`)
    via Polylang — um único sitemap cobre as duas edições.
  - Anunciado automaticamente no `robots.txt`.
  - Cache de 5 min; é invalidado sempre que publicas/editas uma notícia.
- **Schema `NewsArticle`** (JSON-LD) em cada notícia, emitido de forma fiável
  (mesmo com RankMath/Yoast ativos), com:
  - `inLanguage` correto por post (`pt-PT` / `en-US`).
  - `publisher.logo` (ImageObject) — usa o logo do tema, senão o site icon.
  - `headline` ≤ 110 caracteres, `image` ImageObject, `author`, datas W3C.

> Logo do publisher: garante que tens **Personalizar → Identidade do site →
> Logótipo** definido (ou um Site Icon). Recomendado ≥ 112px de altura, fundo
> claro. Podes forçar outro via filtro `hti_publisher_logo_url`.

## Tarefas tuas no Google Publisher Center

1. Entra em https://publishercenter.google.com/ e cria a publicação
   **HowToInvest**.
2. **Cria duas edições** (uma por idioma):
   - Edição **Inglês** → idioma EN, conteúdo de `https://howtoinvest.pro/`.
   - Edição **Português** → idioma PT, conteúdo de `https://howtoinvest.pro/pt/`.
3. Em cada edição, adiciona as secções (ex.: a archive de notícias do idioma).
4. Preenche logótipo, descrição e dados de contacto.
5. Submete a publicação para revisão.

## Search Console

1. Confirma que a propriedade `howtoinvest.pro` está verificada.
2. Em **Sitemaps**, submete `https://howtoinvest.pro/news-sitemap.xml`.
3. Verifica que as duas secções (EN e `/pt/`) estão indexadas.

## Boas práticas para entrar/manter-se no Google News

- Cadência de publicação regular (frescura conta muito).
- Cada notícia com data visível, autor e imagem destacada.
- Páginas de política presentes (about, contact, privacy, terms) — já existem.
- Sem conteúdo duplicado entre EN e PT (são traduções, ligadas por hreflang —
  o Polylang já trata).

## Como validar tecnicamente

- Abre `https://howtoinvest.pro/news-sitemap.xml` e confirma `<news:news>` com o
  idioma certo por artigo.
- Testa uma notícia no Rich Results Test (NewsArticle válido, sem erros):
  https://search.google.com/test/rich-results
- Confirma a linha `Sitemap:` em `https://howtoinvest.pro/robots.txt`.
