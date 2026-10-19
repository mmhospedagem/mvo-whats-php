"""Build offline MVO PHP HTML docs. Development dependency: pip install markdown."""
from pathlib import Path
import base64, re, html, json, os
import markdown
from html.parser import HTMLParser


class HtmlOutline(HTMLParser):
    """Collect authored section headings without changing the HTML source."""
    def __init__(self):
        super().__init__()
        self.headings = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        if tag == 'h2':
            anchor = dict(attrs).get('id')
            self.current = (anchor, []) if anchor else None

    def handle_data(self, data):
        if self.current is not None:
            self.current[1].append(data)

    def handle_endtag(self, tag):
        if tag == 'h2' and self.current is not None:
            anchor, parts = self.current
            self.headings.append((anchor, ''.join(parts)))
            self.current = None

    def render(self):
        return '<ul>' + ''.join(
            '<li><a href="#' + html.escape(anchor, quote=True) + '">' + html.escape(title) + '</a></li>'
            for anchor, title in self.headings
        ) + '</ul>' if self.headings else ''

ROOT = Path(__file__).resolve().parents[1]
GROUPS = {
    'Começar': [('inicio','Comece por aqui','docs/INICIO.html'), ('introducao','Visão geral','README.md'), ('DESENVOLVIMENTO','Ambiente e testes','docs/DESENVOLVIMENTO.md'), ('CONEXAO','Conectar WhatsApp','docs/CONEXAO.md'), ('MENSAGENS','Enviar e receber','docs/MENSAGENS.md')],
    'Mensagens': [('PROTOBUF-FEATURES','Novos recursos de mensagens','docs/PROTOBUF_FEATURES.md'), ('TIPOS-MENSAGENS','Tipos de mensagens','docs/TIPOS-MENSAGENS.md'), ('MIDIA','Mídia e arquivos','docs/MIDIA.md'), ('COMPATIBILIDADE-BOTOES','Botões e interativos','docs/COMPATIBILIDADE-BOTOES.md'), ('FORMULARIOS','Formulários sem Meta','docs/FORMULARIOS.md'), ('FLOWS','WhatsApp Flows','docs/FLOWS.md'), ('HISTORICO','Conversas e histórico','docs/HISTORICO.md'), ('RECUPERACAO-MIDIA','Recuperação de mídia','docs/RECUPERACAO-MIDIA.md')],
    'Conta e estado': [('IDENTIDADES','Identidades PN e LID','docs/IDENTIDADES.md'), ('PRESENCE','Presença e indicadores','docs/PRESENCE.md'), ('SERVICOS','Grupos, perfil e serviços','docs/SERVICOS.md'), ('STATUS','Status do WhatsApp','docs/STATUS.md'), ('CANAIS','Canais do WhatsApp','docs/CANAIS.md'), ('COMUNIDADES','Comunidades','docs/COMUNIDADES.md'), ('BUSINESS','WhatsApp Business','docs/BUSINESS.md'), ('APPSTATE','Sincronização app-state','docs/APPSTATE.md'), ('BOTS','Bots e extensões','docs/BOTS.md'), ('BOT-RESPONSES','Respostas de bots','docs/BOT_RESPONSES.md'), ('MOBILE','Conexão mobile','docs/MOBILE.md'), ('STORES','Armazenamento','docs/STORES.md'), ('COORDENADORES','Configuração e chaves','docs/COORDENADORES.md')],
    'Chamadas': [('VOIP','Chamadas e mídia','docs/VOIP.md'), ('VOIP-GRUPOS','Chamadas em grupo','docs/VOIP-GRUPOS.md'), ('VOIP-MIDIA','Codecs e captura','docs/VOIP-MIDIA.md')],
    'Ferramentas': [('FAKE-WHATSAPP','WhatsApp local de teste','docs/FAKE-WHATSAPP.md'), ('FAKE-SERVER','Transporte de teste','docs/FAKE-SERVER.md'), ('MCP-WAM','MCP e telemetria','docs/MCP-WAM.md')],
    'Referência': [('api','API pública PHP',None), ('PROTOBUF-EXTENSIONS','Extensões do protocolo','docs/PROTOBUF_EXTENSIONS.md'), ('PROTOBUF','Schemas Protobuf','docs/PROTOBUF.md'), ('DESEMPENHO','Desempenho e benchmarks','docs/DESEMPENHO.md'), ('PARIDADE','Cobertura e limites','docs/PARIDADE.md'), ('ANALISE','Histórico técnico','docs/ANALISE.md'), ('licenca','Licenças e avisos','docs/LICENCAS.md')]
}

GROUPS['Mensagens'].insert(1, ('MESSAGE-EXTENSIONS','Carrossel e novos formatos','docs/MESSAGE_EXTENSIONS.md'))
GROUPS['Mensagens'].append(('HISTORY-EXTENSIONS','Histórico e interações','docs/HISTORY_EXTENSIONS.md'))
GROUPS['Mensagens'].append(('MESSAGE-OBSERVATIONS','Reações, revisões e observações','docs/MESSAGE_OBSERVATIONS.md'))
GROUPS['Conta e estado'].append(('ACCOUNT-EXTENSIONS','Preferências e novos recursos da conta','docs/ACCOUNT_EXTENSIONS.md'))
GROUPS['Conta e estado'].extend([('ACCOUNT-FEATURES','Atendentes e organização','docs/ACCOUNT_FEATURES.md'), ('AGENDAMENTOS','Agendar mensagens','docs/AGENDAMENTOS.md')])
GROUPS['Começar'][1:1] = [('INSTALLATION','Instalar a biblioteca','docs/INSTALLATION.md'), ('GETTING-STARTED','Primeira integração','docs/GETTING_STARTED.md'), ('EXAMPLES','Exemplos em PHP','docs/EXAMPLES.md')]
GROUPS['Ferramentas'].insert(0, ('LLMS','LLMs e agentes MCP','docs/LLMS.md'))
GROUPS['Começar'][0] = ('inicio','Introdução','docs/INICIO.html')

FACEBOOK_GROUPS = {
    'Começar': [('inicio', 'Introdução', 'docs/facebook/INTRODUCAO.md'), ('INSTALACAO', 'Instalar a biblioteca', 'docs/facebook/INSTALACAO.md'), ('CONEXAO', 'Login e sessão persistente', 'docs/facebook/CONEXAO.md')],
    'Facebook': [('PAGINAS', 'Fanpages e seleção de contas', 'docs/facebook/PAGINAS.md'), ('PUBLICACOES', 'Publicações', 'docs/facebook/PUBLICACOES.md'), ('COMENTARIOS-REACOES', 'Comentários e reações', 'docs/facebook/COMENTARIOS-REACOES.md'), ('INBOX', 'Inbox de Página', 'docs/facebook/INBOX.md'), ('ANUNCIOS', 'Contas de anúncios', 'docs/facebook/ANUNCIOS.md'), ('MONITORAMENTO', 'Monitoramento local', 'docs/facebook/MONITORAMENTO.md')],
    'Avançado': [('MESSENGER', 'Messenger e sincronização', 'docs/FACEBOOK-MESSENGER.md')],
    'Referência': [('EXEMPLOS', 'Exemplos em PHP', 'docs/facebook/EXEMPLOS.md'), ('REFERENCIA', 'Referência PHP', 'docs/facebook/REFERENCIA.md'), ('LLMS', 'LLMs e agentes', 'docs/facebook/LLMS.md'), ('LIMITES', 'Cobertura e limites', 'docs/facebook/LIMITES.md')],
}
FACEBOOK_GROUPS['Facebook'].insert(1, ('ADMINISTRACAO', 'Pessoas e acesso', 'docs/facebook/ADMINISTRACAO.md'))
FACEBOOK_GROUPS['Facebook'].insert(3, ('MIDIA', 'Fotos e upload', 'docs/facebook/MIDIA.md'))
FACEBOOK_GROUPS['Facebook'].insert(6, ('INSIGHTS', 'Insights e métricas', 'docs/facebook/INSIGHTS.md'))
FACEBOOK_GROUPS['Avançado'].insert(0, ('DISPOSITIVO-E2EE', 'Dispositivo E2EE', 'docs/facebook/DISPOSITIVO-E2EE.md'))

PORTALS = [
    dict(key='whatsapp', label='WhatsApp', title='MVO WHATS PHP', output='docs/index.html', groups=GROUPS,
         description='Documentação MVO WhatsApp para PHP. Conecte sua sessão, receba eventos e envie mensagens pela sua aplicação.',
         reference='api', footer='Licenças e avisos', footer_href='#licenca',
         outline='Integre à sua aplicação', outline_href='#CONEXAO/gatilhos-publicos-para-integrar-uma-api',
         github='https://github.com/mmhospedagem/mvo-whats-php/blob/main/',
         external=[('GitHub', 'https://github.com/mmhospedagem/mvo-whats-php'), ('Packagist', 'https://packagist.org/packages/mmhospedagem/mvo-whats-php')],
         search_hint='Tente “conexão”, “mídia” ou “sendMessage”.'),
    dict(key='facebook', label='Facebook', title='MVO Facebook PHP', output='docs/facebook/index.html', groups=FACEBOOK_GROUPS,
         description='Biblioteca PHP Facebook: autenticação, sessão persistente, Fanpages, publicações, inbox e monitoramento local. Desenvolvimento experimental local.',
         reference='REFERENCIA', footer='Cobertura e limites', footer_href='#LIMITES',
         outline='Conecte e restaure sua sessão', outline_href='#CONEXAO',
         github='https://github.com/mmhospedagem/mvo-whats-php/blob/main/',
         external=[('GitHub', 'https://github.com/mmhospedagem/mvo-facebook-php'), ('Packagist', 'https://packagist.org/packages/mmhospedagem/mvo-facebook-php')],
         search_hint='Tente “sessão”, “Fanpage” ou “FacebookConnection”.'),
]

def embed_local_image(match, source):
    """Embed documentation images so the generated HTML also works offline."""
    url = html.unescape(match.group(2))
    if re.match(r'(?:[a-z][a-z0-9+.-]*:|//|#|/)', url, re.I):
        return match.group(0)
    path = ((ROOT / source).parent / url).resolve()
    if not path.is_relative_to((ROOT / 'docs').resolve()):
        raise ValueError(f'Image outside docs: {url}')
    media = {'.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.svg': 'image/svg+xml', '.webp': 'image/webp'}.get(path.suffix.lower())
    if media is None:
        raise ValueError(f'Unsupported documentation image: {url}')
    # SVG is tracked as text: Git can check it out with CRLF on Windows and LF
    # on Linux. Normalize its line endings before embedding so both builds agree.
    image_bytes = path.read_text(encoding='utf-8').encode('utf-8') if media == 'image/svg+xml' else path.read_bytes()
    data = base64.b64encode(image_bytes).decode('ascii')
    return match.group(1) + 'data:' + media + ';base64,' + data + match.group(3)

def build_pages(groups):
    pages = []
    for group, entries in groups.items():
        for page_id, title, source in entries:
            if source:
                raw = (ROOT / source).read_text(encoding='utf-8')
            else:
                raw = '# API pública PHP\n\nReferência gerada das assinaturas públicas em `src/`. Consulte os guias para pré-condições, exemplos e comportamento. Métodos herdados não são repetidos; símbolos internos também aparecem para consulta técnica.\n'
                for file in sorted((ROOT / 'src').rglob('*.php'), key=lambda path: path.relative_to(ROOT).as_posix()):
                    code = file.read_text(encoding='utf-8')
                    signatures = re.findall(r'public\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)\s*(?::\s*[^\s{;]+)?', code, re.S)
                    if signatures:
                        ns = re.search(r'namespace\s+([^;]+);', code)
                        raw += '\n## ' + (ns[1] + '\\' if ns else '') + file.stem + '\n\nFonte: `' + file.relative_to(ROOT).as_posix() + '`\n\n```php\n' + '\n'.join(' '.join(signature.split()) for signature in signatures) + '\n```\n'
            converter = markdown.Markdown(extensions=['fenced_code', 'tables', 'toc', 'sane_lists'])
            if source and source.endswith('.html'):
                body = raw
                outline = HtmlOutline()
                outline.feed(body)
                toc = outline.render()
            else:
                body = converter.convert(raw)
                toc = converter.toc
            body = body.replace('src="docs/assets/mvo-logo.png"', 'data-mvo-logo')
            if source:
                body = re.sub(r'(<img\b[^>]*?\bsrc=")([^"]+)(")', lambda match: embed_local_image(match, source), body, flags=re.I)
            pages.append(dict(id=page_id, title=title, group=group, source=source, body=body, toc=toc))
    return pages


def relative_url(target, output):
    return os.path.relpath(ROOT / target, (ROOT / output).parent).replace(os.sep, '/')


def portal_routes(portal):
    """Only navigation metadata crosses portals; the search/content never does."""
    routes = [dict(source=source, id=page_id,
                 href=('#' if other['key'] == portal['key'] else relative_url(other['output'], portal['output']) + '#') + page_id)
            for other in PORTALS for entries in other['groups'].values()
            for page_id, title, source in entries if source]
    facebook = next(other for other in PORTALS if other['key'] == 'facebook')
    prefix = '#' if portal['key'] == 'facebook' else relative_url(facebook['output'], portal['output']) + '#'
    routes.extend(dict(source=source, id=page_id, href=prefix + page_id) for source, page_id in {
        'docs/FACEBOOK.md': 'inicio', 'packages/facebook/README.md': 'inicio',
    }.items())
    return routes


def script_json(value):
    return json.dumps(value, ensure_ascii=False).replace('</', '<\\/')


def build_portal(portal, template, css, app, logo_data, brand_data):
    pages = build_pages(portal['groups'])
    config = dict(key=portal['key'], label=portal['label'], title=portal['title'], home='inicio',
                  reference=portal['reference'], githubBase=portal['github'],
                  sourcePrefix=relative_url('.', portal['output']).rstrip('/') + '/',
                  searchHint=portal['search_hint'])
    switch = '<nav class="product-switch" aria-label="Escolher biblioteca">' + ''.join(
        '<a href="' + html.escape(relative_url(other['output'], portal['output']) + '#inicio', quote=True) + '"'
        + (' class="current" aria-current="page"' if other['key'] == portal['key'] else '')
        + '>' + html.escape(other['label']) + '</a>' for other in PORTALS) + '</nav>'
    external = ''.join('<a class="website-link" href="' + html.escape(href, quote=True) + '"'
                       + (' target="_blank" rel="noopener noreferrer"' if href.startswith('https://') else '')
                       + '>' + html.escape(label) + '<svg class="icon" aria-hidden="true"><use href="#i-external"/></svg></a>'
                       for label, href in portal['external'])
    replacements = {
        '/*DOC_DATA*/': script_json(pages), '/*DOC_CONFIG*/': script_json(config),
        '/*PORTAL_ROUTES*/': script_json(portal_routes(portal)),
        '/*MVO_LOGO_DATA*/': script_json(logo_data), '/*MVO_BRAND_DATA*/': script_json(brand_data),
        '/*MVO_STYLES*/': css, '/*MVO_APP*/': app,
        '__DOC_TITLE__': html.escape(portal['title']), '__DOC_DESCRIPTION__': html.escape(portal['description'], quote=True),
        '__DOC_PRODUCT__': html.escape(portal['label']), '__DOC_REFERENCE__': html.escape(portal['reference'], quote=True),
        '__DOC_FOOTER__': html.escape(portal['footer']), '__DOC_FOOTER_HREF__': html.escape(portal['footer_href'], quote=True),
        '__DOC_OUTLINE__': html.escape(portal['outline']), '__DOC_OUTLINE_HREF__': html.escape(portal['outline_href'], quote=True),
        '__PRODUCT_SWITCH__': switch, '__EXTERNAL_LINKS__': external,
        '__LLMS_LABEL__': 'LLMs e MCP' if portal['key'] == 'whatsapp' else 'LLMs e agentes',
        '__SOURCE_LABEL__': 'Ver fonte' if portal['github'] else 'Ver fonte local',
    }
    # One pass prevents a placeholder-like string inside authored docs from being
    # interpreted as template markup on a later replacement.
    output = re.sub('|'.join(re.escape(key) for key in replacements), lambda match: replacements[match.group(0)], template)
    destination = ROOT / portal['output']
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(output.encode('utf-8'))
    print(f'Built {portal["output"]}: {len(pages)} pages, {sum(len(page["body"]) for page in pages):,} characters of documentation')


def main():
    logo_data = 'data:image/png;base64,' + base64.b64encode((ROOT / 'docs/assets/mvo-logo.png').read_bytes()).decode('ascii')
    brand_data = 'data:image/png;base64,' + base64.b64encode((ROOT / 'docs/assets/mvo-brand.png').read_bytes()).decode('ascii')
    template = (ROOT / 'docs/template.html').read_text(encoding='utf-8')
    css = (ROOT / 'docs/assets/docs.css').read_text(encoding='utf-8')
    for weight in (400, 600, 800):
        font = base64.b64encode((ROOT / f'docs/assets/fonts/manrope-{weight}.ttf').read_bytes()).decode('ascii')
        css = css.replace(f'fonts/manrope-{weight}.ttf', 'data:font/ttf;base64,' + font)
    app = (ROOT / 'docs/assets/docs.js').read_text(encoding='utf-8')
    for portal in PORTALS:
        build_portal(portal, template, css, app, logo_data, brand_data)
    for filename in ('llms.txt', 'llms-full.txt'):
        (ROOT / 'docs' / filename).write_bytes((ROOT / filename).read_text(encoding='utf-8').encode('utf-8'))
    facebook_context = '# MVO Facebook PHP — contexto completo local\n\n'
    facebook_context += 'Contexto independente da biblioteca Facebook. Não substitui os contratos do SDK WhatsApp.\n\n'
    for source in ('LLMS.md', 'REFERENCIA.md', 'LIMITES.md'):
        facebook_context += (ROOT / 'docs/facebook' / source).read_text(encoding='utf-8').rstrip() + '\n\n'
    (ROOT / 'docs/facebook/llms-full.txt').write_bytes((facebook_context.rstrip() + '\n').encode('utf-8'))


if __name__ == '__main__':
    main()
