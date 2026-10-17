# Documentação HTML

Abra `docs/index.html` no navegador. A página funciona offline e contém busca, navegação por guias, índice de seções, tema claro/escuro e cópia de exemplos.

O conteúdo é gerado do README, dos guias locais e das assinaturas públicas em `src/`. Para atualizar após alterar a biblioteca:

```sh
python -m pip install markdown
python tools/build_docs.py
```

Edite o layout em `docs/template.html`; `docs/index.html` é o resultado gerado. Python e Markdown são ferramentas de desenvolvimento, sem participação no runtime PHP ou na leitura do HTML.

A referência de assinaturas inclui símbolos internos e não substitui as instruções e pré-condições dos guias. O estado de implementação é o descrito nas fontes; tabelas históricas em ANALISE.md permanecem identificadas como diagnóstico inicial.
