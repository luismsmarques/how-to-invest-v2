# Como subir isto para o GitHub (primeiro commit)

O repositório `how-to-invest-v2` está vazio. Para o povoar com este pacote:

## Opção A — via terminal (recomendado)

1. Descompacta o zip numa pasta local.
2. Dentro dessa pasta:

```bash
git init
git add .
git commit -m "chore: project scaffold, specs, skills and Claude Code context"
git branch -M main
git remote add origin https://github.com/luismsmarques/how-to-invest-v2.git
git push -u origin main
```

## Opção B — pela interface do GitHub

Arrasta os ficheiros/pastas para o repositório no browser (Add file → Upload files).
Nota: a interface web às vezes não preserva pastas começadas por ponto (`.claude`, `.gitignore`).
Se isso acontecer, usa a Opção A, que preserva tudo.

## Depois do push

1. Abre o repositório no **Claude Code**.
2. Primeiro pedido sugerido:
   > "Lê o START_HERE.md e o CLAUDE.md e diz-me o plano para a Fase 1 antes de começarmos."
3. O Claude Code vai encontrar automaticamente as skills em `.claude/skills/` e o contexto em `CLAUDE.md`.

## Verifica que estes ficheiros/pastas subiram

- `START_HERE.md`, `README.md`, `CLAUDE.md`, `.gitignore`
- `docs/` (7 documentos)
- `.claude/skills/` (9 skills) ← confirma que esta pasta oculta subiu
- `wp-content/themes/howtoinvest/` e `wp-content/plugins/hti-engine/`

## Nota sobre o .gitignore

O `.gitignore` está preparado para, mais tarde, ignorares o core do WordPress e
versionares apenas o **teu** código (tema + plugin). Neste primeiro commit ainda
não há core do WP — só o esqueleto e as specs — por isso sobe tudo sem problema.
