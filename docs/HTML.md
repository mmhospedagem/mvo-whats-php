# Documentação HTML

As bibliotecas têm portais separados, com alternância no cabeçalho:

- **WhatsApp:** [docs/index.html](index.html#inicio), preservando os links e âncoras existentes.
- **Facebook:** [docs/facebook/index.html](facebook/index.html#inicio), com login, sessão persistente, Páginas, publicações, comentários/reações, inbox, anúncios, monitoramento, exemplos, referência e limites.

Os portais publicados são [WhatsApp](https://mmhospedagem.github.io/mvo-whats-php/) e [Facebook](https://mmhospedagem.github.io/mvo-whats-php/facebook/). As fontes e os resultados HTML ficam neste repositório; os pacotes Composer mantêm suas próprias dependências, sem misturar os canais.

Cada portal funciona offline e tem a própria busca e navegação, índice de seções, tema claro/escuro e cópia de exemplos. Uma busca no Facebook consulta somente seus guias; a documentação WhatsApp mantém seus próprios resultados.

O conteúdo WhatsApp é gerado do README, dos guias locais e das assinaturas em `src/`. O Facebook usa os guias de `docs/facebook/`, conferidos com os contratos de `packages/facebook/` e do runtime compartilhado `packages/messenger/`. Para atualizar os dois portais:

```sh
python -m pip install markdown
python tools/build_docs.py
```

Edite o layout compartilhado em `docs/template.html`, os estilos/scripts em `docs/assets/` e o mapa dos guias em `tools/build_docs.py`. `docs/index.html` e `docs/facebook/index.html` são resultados gerados. Os contextos para modelos também são separados: `docs/llms.txt`/`docs/llms-full.txt` para WhatsApp e `docs/facebook/llms.txt`/`docs/facebook/llms-full.txt` para Facebook. Python e Markdown são ferramentas de desenvolvimento, sem participação no runtime PHP ou na leitura do HTML.

A referência de assinaturas inclui símbolos internos e não substitui as instruções e pré-condições dos guias. O estado de implementação é o descrito nas fontes; tabelas históricas em ANALISE.md permanecem identificadas como diagnóstico inicial.
