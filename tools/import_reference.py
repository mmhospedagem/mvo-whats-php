"""Import data from an explicitly selected development reference.

Does not execute the source implementation or modify licenses/notices.
"""
from pathlib import Path
import argparse
import json
import os
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def source_files(directory):
    for base, directories, files in os.walk(directory, followlinks=False):
        directories[:] = sorted(name for name in directories
                                if name not in {'node_modules', '.git'}
                                and not (Path(base) / name).is_symlink()
                                and not getattr(Path(base) / name, 'is_junction', lambda: False)())
        for name in sorted(files):
            file = Path(base) / name
            if file.suffix == '.ts' and not file.is_symlink():
                yield file


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True,
                        help='Path to an existing reference checkout; never downloaded automatically')
    parser.add_argument('--output', type=Path, default=ROOT / 'resources',
                        help='Directory for generated data only (default: project resources/)')
    args = parser.parse_args()
    source = args.source.resolve(strict=True)
    token_source = (source / 'src/transport/binary/tokens.ts').read_text(encoding='utf-8')
    tables = []
    for name in ['SINGLE_BYTE_TOKENS'] + [f'DICTIONARY_{index}_TOKENS' for index in range(4)]:
        match = re.search(r'export const ' + name + r' = Object.freeze\(\[(.*?)\] as const\)', token_source, re.S)
        if match is None:
            raise ValueError(f'Missing token table: {name}')
        tables.append(re.findall(r"'((?:[^'\\]|\\.)*)'", match[1]))
    proto = (source / 'spec/proto/WAProto.proto').read_bytes()
    modules = {}
    files = list(source_files(source / 'src'))
    for file in files:
        modules.setdefault(file.relative_to(source / 'src').parts[0], []).append(file.relative_to(source).as_posix())
    revision = subprocess.run(['git', '-C', str(source), 'rev-parse', 'HEAD'],
                              capture_output=True, text=True, check=False)
    inventory = {'commit': revision.stdout.strip() if revision.returncode == 0 else None,
                 'modules': modules}
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    (output / 'tokens.json').write_text(json.dumps({'single': tables[0], 'dictionaries': tables[1:]}, ensure_ascii=False), encoding='utf-8')
    (output / 'WAProto.proto').write_bytes(proto)
    (output / 'upstream-inventory.json').write_text(json.dumps(inventory, indent=2), encoding='utf-8')
    print(json.dumps({'token_tables': [len(table) for table in tables], 'source_files': len(files), 'license_untouched': True}))


if __name__ == '__main__':
    main()
