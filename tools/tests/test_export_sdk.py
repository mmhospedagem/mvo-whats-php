"""Public distribution allowlist, path safety and portable SDK documentation."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('export_sdk', Path(__file__).resolve().parents[1] / 'export_sdk.py')
export_sdk = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(export_sdk)


class ExportSdkTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.source = self.root / 'packages/facebook'
        self.source.mkdir(parents=True)
        self.write('composer.json', '{"name":"mmhospedagem/mvo-facebook-php"}')
        self.write('README.md', '# Public SDK\n')
        self.write('LICENSE', 'MIT\n')
        self.write('src/Client.php', '<?php // Public original code\n')
        self.commit = patch.object(export_sdk, 'repository_commit', return_value='a' * 40)
        self.commit.start()

    def tearDown(self):
        self.commit.stop()
        self.temporary.cleanup()

    def write(self, relative, content):
        target = self.source / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding='utf-8')

    def export(self, destination=None):
        return export_sdk.export_package('facebook', destination or self.root / 'distribution', self.root)

    def test_public_allowlist_excludes_runtime_data_even_under_allowed_directories(self):
        for relative in ['vendor/secret.php', '.cache/secret.json', '.phpunit.cache/secret.php', 'composer.lock',
                         'private.json', 'examples/.env', 'src/private/secret.php', 'tests/sessions/cookies.json',
                         'resources/account.sqlite', 'resources/account.sqlite-wal', 'resources/account.db',
                         'resources/debug.har', 'resources/session.log', 'resources/server.key', 'tests/.auth/state.json']:
            self.write(relative, 'PRIVATE-MUST-NOT-EXPORT')
        self.write('resources/documents.json', '{"document":"public"}')
        self.write('tests/ClientTest.php', '<?php // Synthetic test\n')
        self.write('phpunit.xml', '<phpunit/>')
        self.write('phpstan.neon.dist', 'parameters: {}')
        self.write('THIRD_PARTY_NOTICES.md', 'Notices\n')
        self.write('.gitattributes', '* text=auto\n')
        self.write('.gitignore', '/vendor\n')
        manifest = self.export()
        self.assertEqual(set(manifest['files']), {'composer.json', 'README.md', 'LICENSE', 'src/Client.php',
                         'resources/documents.json', 'tests/ClientTest.php', 'phpunit.xml', 'phpstan.neon.dist',
                         'THIRD_PARTY_NOTICES.md', '.gitattributes', '.gitignore'})
        for path in (self.root / 'distribution').rglob('*'):
            if path.is_file():
                self.assertNotIn(b'PRIVATE-MUST-NOT-EXPORT', path.read_bytes())

    def test_existing_destination_is_never_overwritten_or_deleted(self):
        destination = self.root / 'existing'
        destination.mkdir()
        marker = destination / 'important.txt'
        marker.write_bytes(b'keep-me')
        with self.assertRaises(FileExistsError):
            self.export(destination)
        self.assertEqual(marker.read_bytes(), b'keep-me')

    def test_destination_cannot_overlap_source_or_create_missing_parents(self):
        with self.assertRaises(ValueError):
            self.export(self.source / 'export')
        with self.assertRaises(ValueError):
            self.export(self.root / 'missing' / 'export')
        self.assertFalse((self.root / 'missing').exists())

    def test_readme_rewrites_external_relative_links_and_commands_only(self):
        self.write('README.md', '\n'.join([
            '[Local](src/Client.php)', '[Messenger](../messenger/README.md#sessão)',
            '[Facebook](../../docs/facebook/CONEXAO.md)', '[Runtime](../../docs/FACEBOOK-MESSENGER.md)',
            '[Shared](../../docs/MULTICANAL.md)', '[Remote](https://example.test/a)',
            'php packages/facebook/examples/session.php --help',
            'php vendor/bin/phpunit --configuration packages/facebook/phpunit.xml',
            '"url": "../mvo-whats-php/packages/facebook"',
        ]))
        self.write('examples/session.php', "<?php\n// Runtime path remains packages/facebook/src/\necho <<<'HELP'\n  php packages/facebook/examples/session.php --help\nHELP;\n")
        self.write('src/Monitoring/README.md', '[Example](../../examples/session.php)\n[Guide](../../../../docs/facebook/MONITORAMENTO.md)\nphp packages/facebook/examples/session.php --help\n')
        original = (self.source / 'README.md').read_bytes()
        self.export()
        text = (self.root / 'distribution/README.md').read_text(encoding='utf-8')
        self.assertIn('[Local](src/Client.php)', text)
        self.assertIn('https://github.com/mmhospedagem/mvo-messenger-php#sessão', text)
        self.assertIn(export_sdk.FACEBOOK_DOCS + '#CONEXAO', text)
        self.assertIn(export_sdk.FACEBOOK_DOCS + '#MESSENGER', text)
        self.assertIn(export_sdk.MONOREPO + '/blob/main/docs/MULTICANAL.md', text)
        self.assertIn('[Remote](https://example.test/a)', text)
        self.assertIn('php examples/session.php --help', text)
        self.assertIn('--configuration phpunit.xml', text)
        self.assertIn('"url": "../mvo-whats-php/packages/facebook"', text)
        self.assertEqual((self.source / 'README.md').read_bytes(), original)
        example = (self.root / 'distribution/examples/session.php').read_text(encoding='utf-8')
        self.assertIn('// Runtime path remains packages/facebook/src/', example)
        self.assertIn('  php examples/session.php --help', example)
        nested = (self.root / 'distribution/src/Monitoring/README.md').read_text(encoding='utf-8')
        self.assertIn('[Example](../../examples/session.php)', nested)
        self.assertIn(export_sdk.FACEBOOK_DOCS + '#MONITORAMENTO', nested)
        self.assertIn('php examples/session.php --help', nested)

    def test_provenance_hashes_exact_exported_content_and_uses_no_local_paths(self):
        manifest = self.export()
        saved = json.loads((self.root / 'distribution/EXPORT_PROVENANCE.json').read_text())
        self.assertEqual(saved, manifest)
        self.assertEqual(saved['source_path'], 'packages/facebook')
        self.assertEqual(saved['source_commit'], 'a' * 40)
        self.assertNotIn(str(self.root), json.dumps(saved))
        self.assertNotIn('EXPORT_PROVENANCE.json', saved['files'])
        for name, digest in saved['files'].items():
            self.assertEqual(hashlib.sha256((self.root / 'distribution' / name).read_bytes()).hexdigest(), digest)

    def test_missing_license_and_unknown_package_fail_before_destination_creation(self):
        (self.source / 'LICENSE').unlink()
        with self.assertRaises(ValueError):
            self.export()
        self.assertFalse((self.root / 'distribution').exists())
        with self.assertRaises(ValueError):
            export_sdk.export_package('whatsapp', self.root / 'distribution', self.root)

    def test_symlink_source_is_rejected_without_following_it(self):
        link = self.source / 'src/Linked.php'
        try:
            link.symlink_to(self.source / 'LICENSE')
        except (OSError, NotImplementedError):
            self.skipTest('Creating symlinks is unavailable in this environment.')
        with self.assertRaises(ValueError):
            self.export()
        self.assertFalse((self.root / 'distribution').exists())

    def test_escaping_readme_link_fails_before_export(self):
        self.write('README.md', '[Private](../../../outside.txt)')
        with self.assertRaises(ValueError):
            self.export()
        self.assertFalse((self.root / 'distribution').exists())


if __name__ == '__main__':
    unittest.main()
