#!/usr/bin/env python3
"""Export one public SDK into a new, empty distribution directory.

This development tool performs no Git mutation or network request. It leaves a
partial directory in place if writing fails; it never removes or replaces data.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import posixpath
import re
import subprocess
from urllib.parse import quote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
MONOREPO = 'https://github.com/mmhospedagem/mvo-whats-php'
FACEBOOK_DOCS = 'https://mmhospedagem.github.io/mvo-whats-php/facebook/'
PACKAGES = {'facebook': 'mvo-facebook-php', 'messenger': 'mvo-messenger-php'}
PUBLIC_DIRECTORIES = {'src', 'resources', 'examples', 'tests'}
PUBLIC_FILES = {
    'composer.json', 'README.md', 'LICENSE', 'THIRD_PARTY_NOTICES.md',
    'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
    '.gitattributes', '.gitignore',
}
PUBLIC_SUFFIXES = {'.php', '.json', '.md', '.txt', '.xml', '.neon', '.dist', '.svg', '.png', '.jpg', '.jpeg', '.webp', '.gif'}
PRIVATE_NAMES = {
    'vendor', 'node_modules', '.git', '.cache', '__pycache__', '.phpunit.cache',
    '.phpunit.result.cache', '.auth', '.auth-backups', '.env', 'composer.lock',
}
RUNTIME_DIRECTORIES = {'storage', 'var', 'sessions', 'secrets', 'captures', 'build', 'private'}
PRIVATE_SUFFIXES = {'.sqlite', '.sqlite-wal', '.sqlite-shm', '.db', '.db-wal', '.db-shm', '.log', '.har', '.pcap', '.pcapng', '.trace', '.pem', '.key', '.zip'}
MARKDOWN_LINK = re.compile(r'(?P<open>!?\[[^\]\r\n]*\]\()(?P<url><[^>\r\n]+>|[^\s)]+)(?P<title>\s+["\'][^\r\n]*?["\'])?\)')


def _private(name: str, source_storage_namespace: bool = False) -> bool:
    lowered = name.lower()
    return (lowered in PRIVATE_NAMES
            or (lowered in RUNTIME_DIRECTORIES and not (source_storage_namespace and name == 'Storage'))
            or lowered.startswith('.env.')
            or any(lowered.endswith(suffix) for suffix in PRIVATE_SUFFIXES))


def _walk_public(directory: Path, relative: PurePosixPath):
    if directory.is_symlink():
        raise ValueError('Symlinks are not allowed in public SDK inputs.')
    for entry in sorted(directory.iterdir(), key=lambda item: item.name):
        # src/Storage is the public PHP namespace, not a runtime state directory.
        # Keep private/session/artifact exclusions active, even inside src/Storage.
        if _private(entry.name, source_storage_namespace=relative == PurePosixPath('src')) or entry.name.startswith('.'):
            continue
        if entry.is_symlink():
            raise ValueError('Symlinks are not allowed in public SDK inputs.')
        child = relative / entry.name
        if entry.is_dir():
            yield from _walk_public(entry, child)
        elif entry.is_file() and entry.suffix.lower() in PUBLIC_SUFFIXES:
            yield child, entry


def public_files(source: Path):
    """Enumerate the explicit package allowlist, never caches or session stores."""
    if source.is_symlink() or not source.is_dir():
        raise ValueError('The SDK source must be a real package directory.')
    for name in sorted(PUBLIC_FILES):
        entry = source / name
        if entry.is_symlink():
            raise ValueError('Symlinks are not allowed in public SDK inputs.')
        if entry.is_file():
            yield PurePosixPath(name), entry
    for name in sorted(PUBLIC_DIRECTORIES):
        directory = source / name
        if directory.is_symlink():
            raise ValueError('Symlinks are not allowed in public SDK inputs.')
        if directory.is_dir():
            yield from _walk_public(directory, PurePosixPath(name))


def portable_link(package: str, target: str, readme_path: PurePosixPath = PurePosixPath('README.md')) -> str:
    """Keep package-local links; map repository-relative links to public URLs."""
    wrapped = target.startswith('<') and target.endswith('>')
    raw = target[1:-1] if wrapped else target
    parsed = urlsplit(raw)
    if parsed.scheme or parsed.netloc or raw.startswith(('#', '/', '?')):
        return target
    source_prefix = f'packages/{package}/'
    resolved = posixpath.normpath(source_prefix + str(readme_path.parent) + '/' + parsed.path)
    if resolved == f'packages/{package}' or resolved.startswith(source_prefix):
        return target
    if resolved == '..' or resolved.startswith('../'):
        raise ValueError('A README link escapes the source repository.')
    fragment = '#' + parsed.fragment if parsed.fragment else ''
    query = '?' + parsed.query if parsed.query else ''
    if resolved.startswith('docs/facebook/') and resolved.endswith('.md'):
        page = PurePosixPath(resolved).stem
        result = FACEBOOK_DOCS + '#' + ('inicio' if page == 'INTRODUCAO' else page)
    elif resolved in {'docs/FACEBOOK.md', 'docs/FACEBOOK-MESSENGER.md'}:
        result = FACEBOOK_DOCS + ('#MESSENGER' if resolved.endswith('FACEBOOK-MESSENGER.md') else '#inicio')
    else:
        result = None
        for other, repository in PACKAGES.items():
            other_prefix = f'packages/{other}/'
            if resolved.startswith(other_prefix):
                suffix = resolved[len(other_prefix):]
                base = f'https://github.com/mmhospedagem/{repository}'
                result = base + fragment if suffix == 'README.md' else base + '/blob/main/' + quote(suffix, safe='/') + query + fragment
                break
        if result is None:
            result = MONOREPO + '/blob/main/' + quote(resolved, safe='/') + query + fragment
    return '<' + result + '>' if wrapped else result


def portable_commands(package: str, text: str) -> str:
    prefix = f'packages/{package}/'
    return re.sub(r'^[ \t]*(?:php|composer|python)[ \t]+[^\r\n]*', lambda found: found[0].replace(prefix, ''), text, flags=re.MULTILINE)


def portable_readme(package: str, text: str, readme_path: PurePosixPath = PurePosixPath('README.md')) -> str:
    text = MARKDOWN_LINK.sub(lambda found: found['open'] + portable_link(package, found['url'], readme_path) + (found['title'] or '') + ')', text)
    text = re.sub(
        r'^Os comandos abaixo partem da raiz do monorepo[^\r\n]*',
        'Os comandos abaixo partem da raiz deste repositório de distribuição. Execute `composer install` antes de usar os exemplos.',
        text, flags=re.MULTILINE,
    )
    return portable_commands(package, text)


def repository_commit(root: Path) -> str:
    completed = subprocess.run(['git', 'rev-parse', '--verify', 'HEAD'], cwd=root, check=True, capture_output=True, text=True)
    commit = completed.stdout.strip()
    if re.fullmatch(r'[0-9a-f]{40}|[0-9a-f]{64}', commit) is None:
        raise ValueError('The source repository has no valid Git commit.')
    return commit


def export_package(package: str, destination: Path, repository_root: Path = ROOT) -> dict:
    if package not in PACKAGES:
        raise ValueError('Only the facebook and messenger packages can be exported.')
    root = repository_root.resolve(strict=True)
    source = root / 'packages' / package
    destination = destination.expanduser()
    if os.path.lexists(destination):
        raise FileExistsError('The destination already exists; choose a new directory.')
    destination = destination.resolve(strict=False)
    if destination == source or source in destination.parents or destination in source.parents:
        raise ValueError('The destination must not overlap the SDK source.')
    if not destination.parent.is_dir():
        raise ValueError('The destination parent must already be an existing directory.')
    commit = repository_commit(root)
    payloads = {}
    for relative, path in public_files(source):
        content = path.read_bytes()
        if relative.name == 'README.md':
            content = portable_readme(package, content.decode('utf-8'), relative).encode('utf-8')
        elif relative.parts[0] == 'examples' and relative.suffix == '.php':
            content = portable_commands(package, content.decode('utf-8')).encode('utf-8')
        payloads[str(relative)] = content
    required = {'composer.json', 'README.md', 'LICENSE'}
    if not required <= payloads.keys() or not any(path.startswith('src/') for path in payloads):
        raise ValueError('The SDK is missing required public source, Composer, README or license files.')
    manifest = {
        'format_version': 1,
        'source_repository': MONOREPO,
        'source_path': f'packages/{package}',
        'source_commit': commit,
        'files': {path: hashlib.sha256(content).hexdigest() for path, content in sorted(payloads.items())},
    }
    # Exclusive mkdir/write also protect against changes after the preflight.
    destination.mkdir(exist_ok=False)
    for relative, content in sorted(payloads.items()):
        target = destination.joinpath(*PurePosixPath(relative).parts)
        target.parent.mkdir(parents=True, exist_ok=True)
        with target.open('xb') as output:
            output.write(content)
    with (destination / 'EXPORT_PROVENANCE.json').open('x', encoding='utf-8', newline='\n') as output:
        json.dump(manifest, output, indent=2, ensure_ascii=False)
        output.write('\n')
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', choices=sorted(PACKAGES))
    parser.add_argument('destination', type=Path, help='New directory; its parent must already exist.')
    arguments = parser.parse_args()
    try:
        manifest = export_package(arguments.package, arguments.destination)
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, f'Export failed: {type(error).__name__}. No existing destination is replaced.\n')
    print(json.dumps({'package': arguments.package, 'files': len(manifest['files']), 'source_commit': manifest['source_commit']}))


if __name__ == '__main__':
    main()
