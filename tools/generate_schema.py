"""Compile the checked-in WAProto.proto into a PHP-runtime JSON descriptor.

Development tool only; the library never invokes Python or Node.js.
"""
from pathlib import Path
import argparse
import hashlib
import json
import re


ROOT = Path(__file__).resolve().parents[1]
SCALARS = set('double float int32 int64 uint32 uint64 sint32 sint64 fixed32 fixed64 sfixed32 sfixed64 bool string bytes'.split())
MAP_KEYS = set('int32 int64 uint32 uint64 sint32 sint64 fixed32 fixed64 sfixed32 sfixed64 bool string'.split())
# Match quoted strings before comments, so e.g. an option URL is not truncated.
TOKEN = re.compile(r'''"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|/\*.*?\*/|//[^\n]*|\.?[A-Za-z_][\w.]*|-?\d+|[^\s]''', re.S)


class Parser:
    def __init__(self, source: str):
        self.tokens = [token for token in TOKEN.findall(source) if not token.startswith(('//', '/*'))]
        self.pos = 0
        self.messages = {}
        self.enums = {}
        self.package = ''
        self.syntax = 'proto2'

    def peek(self):
        return self.tokens[self.pos] if self.pos < len(self.tokens) else None

    def take(self, expected=None):
        value = self.peek()
        if value is None:
            raise ValueError('Unexpected end of protobuf source')
        self.pos += 1
        if expected is not None and value != expected:
            raise ValueError(f'Expected {expected}, found {value} at token {self.pos}')
        return value

    def skip(self):
        while self.take() != ';':
            pass

    def field(self, owner, fields, label, oneof=None):
        repeated = label == 'repeated'
        if oneof is not None and label in ('optional', 'required', 'repeated', 'map'):
            raise ValueError(f'Invalid oneof field in {owner}.{oneof}')
        field_type = self.take() if label in ('optional', 'required', 'repeated') else label
        map_key = None
        if field_type == 'map':
            if repeated or label in ('optional', 'required'):
                raise ValueError(f'Invalid map label in {owner}')
            self.take('<')
            map_key = self.take()
            if map_key not in MAP_KEYS:
                raise ValueError(f'Invalid map key type {map_key} in {owner}')
            self.take(',')
            field_type = self.take()
            self.take('>')
        name = self.take()
        self.take('=')
        number = int(self.take())
        if not 1 <= number <= 536870911 or 19000 <= number <= 19999:
            raise ValueError(f'Invalid field number {number} in {owner}.{name}')
        if name in fields or any(field['id'] == number for field in fields.values()):
            raise ValueError(f'Duplicate field name or number in {owner}.{name}')
        options = []
        while self.peek() != ';':
            options.append(self.take())
        self.take(';')
        packed = None
        for index, option in enumerate(options):
            if option == 'packed' and options[index:index + 2] == ['packed', '=']:
                setting = options[index + 2:index + 3]
                if packed is not None or setting not in (['true'], ['false']):
                    raise ValueError(f'Invalid packed option in {owner}.{name}')
                packed = setting == ['true']
        field = {'id': number, 'type': field_type, 'repeated': repeated,
                 'packed': repeated and field_type not in ('bytes', 'string') and
                           (packed if packed is not None else self.syntax == 'proto3')}
        if map_key is not None:
            field['mapKey'] = map_key
        if oneof is not None:
            field['oneof'] = oneof
        fields[name] = field

    def body(self, parent='', is_enum=False):
        name = self.take()
        full = f'{parent}.{name}' if parent else name
        if full in self.messages or full in self.enums:
            raise ValueError(f'Duplicate protobuf type {full}')
        self.take('{')
        fields, values, oneofs = {}, {}, set()
        while self.peek() != '}':
            label = self.take()
            if label in ('message', 'enum') and not is_enum:
                self.body(full, label == 'enum')
            elif label in ('option', 'reserved', 'extensions'):
                self.skip()
            elif is_enum:
                self.take('=')
                if label in values:
                    raise ValueError(f'Duplicate enum value {full}.{label}')
                values[label] = int(self.take())
                self.skip()
            elif label == 'oneof':
                group = self.take()
                if group in oneofs:
                    raise ValueError(f'Duplicate oneof {full}.{group}')
                oneofs.add(group)
                self.take('{')
                start = len(fields)
                while self.peek() != '}':
                    member = self.take()
                    if member == 'option':
                        self.skip()
                    else:
                        self.field(full, fields, member, group)
                self.take('}')
                if len(fields) == start:
                    raise ValueError(f'Empty oneof {full}.{group}')
            else:
                self.field(full, fields, label)
        self.take('}')
        if is_enum:
            self.enums[full] = values
        else:
            self.messages[full] = fields

    def parse(self):
        while self.peek() is not None:
            keyword = self.take()
            if keyword in ('message', 'enum'):
                self.body(is_enum=keyword == 'enum')
            elif keyword == 'syntax':
                self.take('=')
                self.syntax = json.loads(self.take())
                if self.syntax not in ('proto2', 'proto3'):
                    raise ValueError(f'Unsupported protobuf syntax {self.syntax}')
                self.take(';')
            elif keyword == 'package':
                self.package = self.take()
                self.take(';')
            elif keyword in ('option', 'import'):
                self.skip()
            else:
                raise ValueError(f'Unsupported protobuf declaration {keyword}')
        for owner, fields in self.messages.items():
            for field in fields.values():
                target = field['type']
                if target in SCALARS:
                    continue
                absolute = target.startswith('.')
                target = target.lstrip('.')
                if self.package and target.startswith(self.package + '.'):
                    target = target[len(self.package) + 1:]
                    absolute = True
                if not absolute:
                    scopes = owner.split('.')
                    while scopes:
                        candidate = '.'.join(scopes) + '.' + target
                        if candidate in self.messages or candidate in self.enums:
                            target = candidate
                            break
                        scopes.pop()
                if target not in self.messages and target not in self.enums:
                    raise ValueError(f'Unresolved type {owner}.{target}')
                field['type'] = target
                field['packed'] = field['packed'] and target in self.enums
        return self.messages, self.enums


def compile_schema(source: bytes, compatibility: bytes | None = None):
    messages, enums = Parser(source.decode('utf-8')).parse()
    descriptor = {'sourceSha256': hashlib.sha256(source).hexdigest(), 'messages': messages, 'enums': enums}
    if compatibility is not None:
        settings = json.loads(compatibility)
        if not isinstance(settings, dict) or set(settings) != {'legacyWireTypes'}:
            raise ValueError('Compatibility settings must contain only legacyWireTypes')
        legacy = settings['legacyWireTypes']
        if not isinstance(legacy, dict):
            raise ValueError('legacyWireTypes must be an object')
        for owner, by_number in legacy.items():
            if owner not in messages or not isinstance(by_number, dict):
                raise ValueError(f'Unknown legacy message or invalid fields: {owner}')
            fields = {str(field['id']): field for field in messages[owner].values()}
            for number, wires in by_number.items():
                if number not in fields:
                    raise ValueError(f'Unknown legacy field {owner}#{number}')
                field = fields[number]
                target = field['type']
                current = (2 if 'mapKey' in field or target in messages or target in ('string', 'bytes') else
                           1 if target in ('double', 'fixed64', 'sfixed64') else
                           5 if target in ('float', 'fixed32', 'sfixed32') else 0)
                if (not isinstance(wires, list) or not wires or
                        any(type(wire) is not int or wire not in (0, 1, 2, 5) or wire == current for wire in wires) or
                        len(wires) != len(set(wires))):
                    raise ValueError(f'Invalid legacy wire types for {owner}#{number}')
                if field['repeated'] and current != 2 and 2 in wires:
                    raise ValueError(f'Packed wire type is already valid for {owner}#{number}')
        descriptor['legacyWireTypes'] = legacy
        descriptor['compatibilitySha256'] = hashlib.sha256(compatibility).hexdigest()
    return descriptor


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=ROOT / 'resources/WAProto.proto')
    parser.add_argument('--output', type=Path, default=ROOT / 'resources/WAProto.schema.json')
    parser.add_argument('--compatibility', type=Path, help='Optional explicit legacy wire exceptions')
    args = parser.parse_args()
    compatibility_path = args.compatibility or args.source.with_name('WAProto.compatibility.json')
    compatibility = compatibility_path.read_bytes() if args.compatibility or compatibility_path.exists() else None
    descriptor = compile_schema(args.source.read_bytes(), compatibility)
    args.output.write_text(json.dumps(descriptor, separators=(',', ':')), encoding='utf-8', newline='\n')
    print(f"{len(descriptor['messages'])} messages, {len(descriptor['enums'])} enums; generated {args.output.name}")


if __name__ == '__main__':
    main()
