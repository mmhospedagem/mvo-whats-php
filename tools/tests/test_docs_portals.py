"""Contract checks for isolated, portable WhatsApp/Facebook documentation portals.

Run after: python tools/build_docs.py
    python -m unittest discover -s tools/tests -p 'test_docs_portals.py'
"""
import importlib.util
import json
import re
import unittest
from pathlib import Path
from html.parser import HTMLParser

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('build_docs', ROOT / 'tools/build_docs.py')
build_docs = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(build_docs)


class PortalMarkup(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.links = []
        self.remote_assets = []
        self.switch_links = []
        self.in_switch = False
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'nav' and attrs.get('class') == 'product-switch':
            self.in_switch = True
        if tag == 'a':
            self.links.append(attrs)
            if self.in_switch:
                self.switch_links.append(attrs)
        if tag in ('script', 'img', 'link'):
            url = attrs.get('src') or attrs.get('href') or ''
            if url.startswith(('https:', 'http:', '//')):
                self.remote_assets.append(url)

    def handle_endtag(self, tag):
        if tag == 'nav':
            self.in_switch = False


def read_portal(path):
    text = (ROOT / path).read_text(encoding='utf-8')
    def constant(name):
        found = re.search(r'^const ' + name + r'=(.+);$', text, re.M)
        if found is None:
            raise AssertionError('Missing constant: ' + name)
        return json.loads(found[1])
    return text, constant('pages'), constant('docConfig'), constant('portalRoutes')


class DocsPortalTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.whatsapp = read_portal('docs/index.html')
        cls.facebook = read_portal('docs/facebook/index.html')

    def test_whatsapp_corpus_keeps_legacy_page_ids(self):
        pages = self.whatsapp[1]
        self.assertEqual(len(pages), 49)
        self.assertEqual(len({page['id'] for page in pages}), 49)
        expected = {page_id for entries in build_docs.GROUPS.values() for page_id, _, _ in entries}
        self.assertEqual({page['id'] for page in pages}, expected)
        self.assertTrue({'inicio', 'api', 'CONEXAO', 'MENSAGENS', 'FORMULARIOS', 'LLMS', 'licenca'} <= expected)
        self.assertFalse(any(page['source'] and page['source'].startswith('docs/facebook/') for page in pages))
        self.assertFalse(any('new FacebookConnection(' in page['body'] for page in pages), 'Facebook login examples must live in the Facebook portal')

    def test_facebook_corpus_is_complete_and_separate(self):
        pages = self.facebook[1]
        self.assertEqual(len(pages), 18)
        self.assertEqual({page['id'] for page in pages}, {
            'inicio', 'INSTALACAO', 'CONEXAO', 'PAGINAS', 'PUBLICACOES', 'COMENTARIOS-REACOES',
            'INBOX', 'ANUNCIOS', 'MONITORAMENTO', 'EXEMPLOS', 'REFERENCIA', 'MESSENGER', 'LLMS', 'LIMITES',
            'MIDIA', 'ADMINISTRACAO', 'INSIGHTS', 'DISPOSITIVO-E2EE',
        })
        self.assertTrue(all(page['source'].startswith('docs/facebook/') or page['source'] == 'docs/FACEBOOK-MESSENGER.md' for page in pages))
        body = ''.join(page['body'] for page in pages)
        self.assertIn('FacebookConnection', body)
        self.assertFalse('new WaClient(' in body, 'WhatsApp examples must live in the WhatsApp portal')
        self.assertNotIn('MVO\\Signal\\', body)

    def test_visible_switch_has_active_channel_and_relative_destination(self):
        for portal, target in [(self.whatsapp, 'facebook/index.html#inicio'), (self.facebook, '../index.html#inicio')]:
            markup = PortalMarkup(portal[0])
            self.assertEqual(len(markup.switch_links), 2)
            self.assertEqual(sum(link.get('aria-current') == 'page' for link in markup.switch_links), 1)
            self.assertIn(target, [link['href'] for link in markup.switch_links])
            self.assertFalse(markup.remote_assets)

    def test_each_published_portal_links_its_own_package_and_public_source(self):
        text, _, config, _ = self.facebook
        self.assertEqual(config['sourcePrefix'], '../../')
        self.assertEqual(config['githubBase'], 'https://github.com/mmhospedagem/mvo-whats-php/blob/main/')
        self.assertIn('href="https://packagist.org/packages/mmhospedagem/mvo-facebook-php"', text)
        self.assertIn('href="https://github.com/mmhospedagem/mvo-facebook-php"', text)
        self.assertNotIn('href="https://packagist.org/packages/mmhospedagem/mvo-whats-php"', text)
        self.assertIn('Ver fonte', text)
        self.assertEqual(self.whatsapp[2]['sourcePrefix'], '../')
        self.assertTrue(self.whatsapp[2]['githubBase'].startswith('https://github.com/'))

    def test_cross_portal_routes_preserve_distinct_page_ids(self):
        wa_routes = {item['source']: item['href'] for item in self.whatsapp[3]}
        fb_routes = {item['source']: item['href'] for item in self.facebook[3]}
        self.assertEqual(wa_routes['docs/facebook/CONEXAO.md'], 'facebook/index.html#CONEXAO')
        self.assertEqual(fb_routes['docs/CONEXAO.md'], '../index.html#CONEXAO')
        self.assertEqual(fb_routes['docs/facebook/CONEXAO.md'], '#CONEXAO')
        self.assertEqual(wa_routes['docs/FACEBOOK.md'], 'facebook/index.html#inicio')
        self.assertEqual(fb_routes['packages/facebook/README.md'], '#inicio')
        self.assertEqual(fb_routes['docs/FACEBOOK-MESSENGER.md'], '#MESSENGER')

    def test_all_assets_and_portal_configuration_are_embedded(self):
        for text, _, config, _ in (self.whatsapp, self.facebook):
            self.assertIn('data:font/ttf;base64,', text)
            self.assertIn('data:image/png;base64,', text)
            self.assertNotIn('/*DOC_DATA*/', text)
            self.assertNotIn('__DOC_', text)
            self.assertIn('const searchable = documents.map', text)
            self.assertIn('A busca inclui somente ' + config['label'], text)
            self.assertIn('aria-controls="sidebar"', text)


if __name__ == '__main__':
    unittest.main()
