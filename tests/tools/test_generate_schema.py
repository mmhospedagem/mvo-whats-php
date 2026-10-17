"""Generator checks run with: python -m unittest discover -s tests/tools."""
from pathlib import Path
import hashlib
import importlib.util
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('generate_schema', ROOT / 'tools/generate_schema.py')
GENERATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GENERATOR)


class GenerateSchemaTest(unittest.TestCase):
    def compile(self, body):
        return GENERATOR.compile_schema(('syntax = "proto3"; package waproto; ' + body).encode())

    def test_oneof_retains_membership_and_nested_type_resolution(self):
        schema = self.compile('''message Envelope {
            message Child { string text = 1; }
            oneof content { string label = 1; Child child = 2; bytes raw = 3; }
            optional bool visible = 4;
        }''')
        fields = schema['messages']['Envelope']
        self.assertEqual({'id': 2, 'type': 'Envelope.Child', 'repeated': False, 'packed': False,
                          'oneof': 'content'}, fields['child'])
        self.assertEqual('content', fields['label']['oneof'])
        self.assertNotIn('oneof', fields['visible'])

    def test_only_explicit_packed_option_changes_proto3_default(self):
        fields = self.compile('''message Example {
            repeated uint32 ids = 1 [deprecated=false];
            repeated uint32 unpacked = 2 [packed=false];
            repeated string labels = 3;
            repeated Example children = 4;
            enum Kind { UNKNOWN = 0; KNOWN = 1; }
            repeated Kind kinds = 5;
        }''')['messages']['Example']
        self.assertTrue(fields['ids']['packed'])
        self.assertFalse(fields['unpacked']['packed'])
        self.assertFalse(fields['labels']['packed'])
        self.assertFalse(fields['children']['packed'])
        self.assertTrue(fields['kinds']['packed'])

    def test_source_digest_hashes_original_bytes_including_crlf(self):
        source = b'syntax = "proto3";\r\nmessage Empty {}\r\n'
        schema = GENERATOR.compile_schema(source)
        self.assertEqual(hashlib.sha256(source).hexdigest(), schema['sourceSha256'])
        self.assertEqual(schema, GENERATOR.compile_schema(source))

    def test_absolute_package_types_and_map_values_resolve(self):
        schema = self.compile('''message Child { bytes raw = 1; }
            message Example { map<string, .waproto.Child> children = 1; }''')
        self.assertEqual({'id': 1, 'type': 'Child', 'repeated': False, 'packed': False,
                          'mapKey': 'string'}, schema['messages']['Example']['children'])

    def test_comments_in_quoted_options_do_not_hide_following_fields(self):
        schema = self.compile('''option java_package = "https://example.test/path";
            // Comment with a fake declaration: message Broken {
            message Example { /* hidden */ string text = 1; }''')
        self.assertEqual({'text'}, set(schema['messages']['Example']))

    def test_corrupt_descriptors_fail_instead_of_silently_overwriting_fields(self):
        for body in [
            'message Example { string first = 1; bytes second = 1; }',
            'message Example { string value = 1; string value = 2; }',
            'message Example { Missing value = 1; }',
            'message Example { string value = 0; }',
            'message Example { string value = 19000; }',
            'message Example { string value = 536870912; }',
            'message Example { oneof content { repeated string values = 1; } }',
            'message Example { map<bytes, string> values = 1; }',
            'message Example { string value = 1;',
        ]:
            with self.subTest(body=body), self.assertRaises(ValueError):
                self.compile(body)

    def test_legacy_wire_exceptions_are_explicit_validated_and_hashed(self):
        source = b'syntax = "proto3"; message Wallpaper { fixed32 color = 1; }'
        settings = b'{"legacyWireTypes":{"Wallpaper":{"1":[2]}}}'
        descriptor = GENERATOR.compile_schema(source, settings)
        self.assertEqual({'Wallpaper': {'1': [2]}}, descriptor['legacyWireTypes'])
        self.assertEqual(hashlib.sha256(settings).hexdigest(), descriptor['compatibilitySha256'])
        for invalid in [
            b'{"legacyWireTypes":{"Missing":{"1":[2]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"2":[2]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"1":[5]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"1":[3]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"1":[true]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"1":[]}}}',
            b'{"legacyWireTypes":{"Wallpaper":{"1":[2,2]}}}',
        ]:
            with self.subTest(settings=invalid), self.assertRaises(ValueError):
                GENERATOR.compile_schema(source, invalid)

    def test_checked_in_schema_is_reproducible_and_has_unique_field_numbers(self):
        schema = GENERATOR.compile_schema((ROOT / 'resources/WAProto.proto').read_bytes())
        self.assertGreater(len(schema['messages']), 600)
        for name, fields in schema['messages'].items():
            numbers = [field['id'] for field in fields.values()]
            self.assertEqual(len(numbers), len(set(numbers)), name)


if __name__ == '__main__':
    unittest.main()
