"""Index a separately selected reference checkout; not used by the PHP runtime."""
from pathlib import Path
import argparse
import json
import os
import re

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True, help='Path to an existing reference checkout')
    parser.add_argument('--output', type=Path, default=ROOT / 'resources/source-map.json')
    args = parser.parse_args()
    source = args.source.resolve(strict=True)
    rows = []
    for directory in ['src', 'packages', 'spec', 'examples']:
        for base, directories, files in os.walk(source / directory, followlinks=False):
            directories[:] = sorted(name for name in directories
                                    if name not in {'node_modules', '.git'}
                                    and not (Path(base) / name).is_symlink()
                                    and not getattr(Path(base) / name, 'is_junction', lambda: False)())
            for name in sorted(files):
                file = Path(base) / name
                if not file.is_symlink() and file.suffix in {'.ts', '.rs', '.proto', '.json', '.md'}:
                    text = file.read_text(encoding='utf-8', errors='replace')
                    rows.append({'path': file.relative_to(source).as_posix(), 'lines': len(text.splitlines()),
                                 'exports': re.findall(r'^export\s+(?:async\s+)?(?:class|function|const|interface|type|enum)\s+(\w+)', text, re.M)})
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(rows, indent=2), encoding='utf-8')
    print(json.dumps({'indexed_files': len(rows), 'follow_junctions': False}))


if __name__ == '__main__':
    main()
