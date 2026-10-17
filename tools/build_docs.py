"""Build offline MVO PHP HTML docs. Development dependency: pip install markdown."""
from pathlib import Path
import base64, re, html, json
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

logo_data='data:image/png;base64,'+base64.b64encode((ROOT/'docs/assets/mvo-logo.png').read_bytes()).decode('ascii')
pages=[]
for group, entries in GROUPS.items():
    for id, title, source in entries:
        if source:
            raw=(ROOT/source).read_text(encoding='utf-8')
        else:
            raw='# API pública PHP\n\nReferência gerada das assinaturas públicas em `src/`. Consulte os guias para pré-condições, exemplos e comportamento. Métodos herdados não são repetidos; símbolos internos também aparecem para consulta técnica.\n'
            for file in sorted((ROOT/'src').rglob('*.php'),key=lambda path:path.relative_to(ROOT).as_posix()):
                code=file.read_text(encoding='utf-8')
                signatures=re.findall(r'public\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)\s*(?::\s*[^\s{;]+)?',code,re.S)
                if signatures:
                    ns=re.search(r'namespace\s+([^;]+);',code)
                    raw+='\n## '+(ns[1]+'\\' if ns else '')+file.stem+'\n\nFonte: `'+file.relative_to(ROOT).as_posix()+'`\n\n```php\n'+'\n'.join(' '.join(s.split()) for s in signatures)+'\n```\n'
        converter=markdown.Markdown(extensions=['fenced_code','tables','toc','sane_lists'])
        if source and source.endswith('.html'):
            body = raw
            outline = HtmlOutline()
            outline.feed(body)
            toc = outline.render()
        else:
            body = converter.convert(raw)
            toc = converter.toc
        # Keep the HTML portable/offline, including the supplied logo in the README.
        body=body.replace('src="docs/assets/mvo-logo.png"','data-mvo-logo')
        if source:
            body=re.sub(r'(<img\b[^>]*?\bsrc=")([^"]+)(")',lambda match: embed_local_image(match,source),body,flags=re.I)
        pages.append(dict(id=id,title=title,group=group,source=source,body=body,toc=toc))
template=(ROOT/'docs/template.html').read_text(encoding='utf-8')
payload=json.dumps(pages,ensure_ascii=False).replace('</','<\\/')
css=(ROOT/'docs/assets/docs.css').read_text(encoding='utf-8')
for weight in (400,600,800):
    font=base64.b64encode((ROOT/f'docs/assets/fonts/manrope-{weight}.ttf').read_bytes()).decode('ascii')
    css=css.replace(f'fonts/manrope-{weight}.ttf','data:font/ttf;base64,'+font)
app=(ROOT/'docs/assets/docs.js').read_text(encoding='utf-8')
output=template.replace('/*DOC_DATA*/',payload).replace('/*MVO_LOGO_DATA*/',json.dumps(logo_data)).replace('/*MVO_STYLES*/',css).replace('/*MVO_APP*/',app)
(ROOT/'docs/index.html').write_bytes(output.encode('utf-8'))
for filename in ('llms.txt','llms-full.txt'):
    (ROOT/'docs'/filename).write_bytes((ROOT/filename).read_text(encoding='utf-8').encode('utf-8'))
print(f'Built docs/index.html: {len(pages)} pages, {sum(len(p["body"]) for p in pages):,} characters of documentation')
