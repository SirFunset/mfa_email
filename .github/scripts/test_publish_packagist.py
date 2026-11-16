import importlib.util

from pathlib import Path
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location('publish_packagist', Path(__file__).with_name('publish_packagist.py'))


class PublishPackagistTest(unittest.TestCase):
    def load_module(self):
        assert SPEC is not None and SPEC.loader is not None
        module = importlib.util.module_from_spec(SPEC)
        SPEC.loader.exec_module(module)
        return module

    def test_notifies_then_requires_exact_indexed_source(self):
        module = self.load_module()
        calls = []
        responses = iter([
            {'status': 'success'},
            {'packages': {'funset/mfa-email': [{'version': 'v14.0.0', 'source': {'reference': 'old'}}]}},
            {'packages': {'funset/mfa-email': [{'version': 'v14.0.0', 'source': {'reference': 'abc123'}}]}},
        ])
        def request(url, payload=None):
            calls.append((url, payload))
            return next(responses)
        with patch.object(module, 'request_json', side_effect=request), patch.object(module.time, 'sleep'):
            module.publish('user', 'secret', 'v14.0.0', 'abc123', attempts=2)
        self.assertEqual(3, len(calls))
        self.assertEqual({'repository': {'url': 'https://packagist.org/packages/funset/mfa-email'}}, calls[0][1])
        self.assertIn('username=user&apiToken=secret', calls[0][0])

    def test_rejected_update_fails(self):
        module = self.load_module()
        with patch.object(module, 'request_json', return_value={'status': 'error'}):
            with self.assertRaises(RuntimeError):
                module.publish('user', 'secret', 'v12.0.0', 'abc123', attempts=1)

    def test_missing_indexed_version_fails(self):
        module = self.load_module()
        with patch.object(module, 'request_json', side_effect=[{'status': 'success'}, {'packages': {}}]):
            with self.assertRaises(RuntimeError):
                module.publish('user', 'secret', 'v13.0.0', 'abc123', attempts=1)

    def test_bad_tag_rejected_before_network(self):
        module = self.load_module()
        with patch.object(module, 'request_json') as request:
            with self.assertRaises(ValueError):
                module.publish('user', 'secret', 'v2.0.1', 'abc123')
            request.assert_not_called()


if __name__ == '__main__':
    unittest.main()
