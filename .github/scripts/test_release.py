"""Integration tests: all mutations are confined to repositories under TMPDIR."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).with_name('release.py')


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=os.environ['TMPDIR'])
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name) / 'repo'
        self.repo.mkdir()
        self.env = dict(os.environ)
        for key in ('GITHUB_OUTPUT', 'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE',
                    'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME',
                    'GIT_COMMITTER_EMAIL'):
            self.env.pop(key, None)
        self.env.update(GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL=os.devnull,
                        GIT_TERMINAL_PROMPT='0')
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Test User')
        self.git('config', 'user.email', 'test@example.invalid')
        self.metadata('14.0.0')
        self.commit('Initial')

    def git(self, *args, cwd=None):
        return subprocess.run(['git', *args], cwd=cwd or self.repo, env=self.env,
                              text=True, capture_output=True, check=True).stdout.strip()

    def metadata(self, version, composer_version=None):
        (self.repo / 'composer.json').write_text(json.dumps({
            'name': 'test/mfa-email',
            'extra': {'typo3/cms': {'version': composer_version or version}}
        }, indent=4) + '\n')
        (self.repo / 'ext_emconf.php').write_text(
            "<?php\n$EM_CONF[$_EXTKEY] = [\n    'version' => '" + version + "',\n];\n")

    def commit(self, message='Change'):
        self.git('add', '.')
        self.git('commit', '--allow-empty', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def release(self, *args, branch=None, sha=None, ok=True, env=None):
        result = subprocess.run([
            sys.executable, str(SCRIPT), '--branch', branch or self.git('branch', '--show-current'),
            '--expected-sha', sha or self.git('rev-parse', 'HEAD'), *args
        ], cwd=self.repo, env=env or self.env, text=True, capture_output=True)
        if ok:
            self.assertEqual(result.returncode, 0, result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout)
        return result

    def test_first_release_each_branch_without_metadata_commit(self):
        for branch, major in [('main', 14), ('main-v13', 13), ('main-v12', 12)]:
            with self.subTest(branch=branch):
                self.git('checkout', '-B', branch)
                self.metadata(f'{major}.0.0')
                before = self.commit()
                result = self.release()
                self.assertEqual(result.stdout.strip(), f'v{major}.0.0')
                self.assertEqual(self.git('rev-parse', 'HEAD'), before)
                self.assertEqual(self.git('cat-file', '-t', f'v{major}.0.0'), 'tag')
                self.assertEqual(self.git('status', '--porcelain'), '')

    def test_patch_uses_greatest_numeric_tag_and_bot_metadata_only_commit(self):
        for tag in ('v14.0.2', 'v14.0.10', 'v13.9.99', 'v14.01.99',
                    'v14.0.999-rc1', '14.0.999', 'v14.bad.0'):
            self.git('tag', tag)
        self.metadata('14.0.10')
        before = self.commit()
        self.git('config', 'commit.gpgSign', 'true')
        self.git('config', 'tag.gpgSign', 'true')
        result = self.release()
        self.assertEqual(result.stdout.strip(), 'v14.0.11')
        self.assertEqual(self.git('rev-parse', 'HEAD^'), before)
        self.assertEqual(set(self.git('diff-tree', '--no-commit-id', '--name-only',
                                     '-r', 'HEAD').splitlines()),
                         {'composer.json', 'ext_emconf.php'})
        for identity in ('%an', '%cn'):
            self.assertEqual(self.git('log', '-1', f'--format={identity}'), 'github-actions[bot]')
        self.assertNotIn('Co-authored-by:', self.git('log', '-1', '--format=%B'))
        composer = json.loads((self.repo / 'composer.json').read_text())
        self.assertNotIn('version', composer)
        self.assertEqual(composer['extra']['typo3/cms']['version'], '14.0.11')
        self.assertIn("'version' => '14.0.11'", (self.repo / 'ext_emconf.php').read_text())
        self.assertEqual(self.git('status', '--porcelain'), '')

    def test_deliberate_minor_bump(self):
        self.git('tag', 'v14.0.5')
        self.metadata('14.1.0')
        before = self.commit()
        self.assertEqual(self.release().stdout.strip(), 'v14.1.0')
        self.assertEqual(self.git('rev-parse', 'HEAD'), before)

    def test_same_head_is_idempotent(self):
        self.release()
        before = self.git('show-ref')
        self.assertEqual(self.release().stdout.strip(), 'v14.0.0')
        self.assertEqual(self.git('show-ref'), before)

    def assert_refused_without_mutation(self, **kwargs):
        before_refs = self.git('show-ref')
        before_files = {name: (self.repo / name).read_bytes()
                        for name in ('composer.json', 'ext_emconf.php')}
        self.release(*kwargs.pop('args', []), ok=False, **kwargs)
        self.assertEqual(self.git('show-ref'), before_refs)
        for name, content in before_files.items():
            self.assertEqual((self.repo / name).read_bytes(), content)

    def test_wrong_branch_and_sha_refused(self):
        self.assert_refused_without_mutation(branch='main-v13')
        self.assert_refused_without_mutation(branch='feature')
        self.assert_refused_without_mutation(sha='0' * 40)
        self.assert_refused_without_mutation(sha='HEAD')
        self.git('checkout', '--detach')
        self.assert_refused_without_mutation(branch='main')

    def test_dirty_worktree_refused_even_dry_run(self):
        for state in ('untracked', 'tracked', 'staged'):
            with self.subTest(state=state):
                if state == 'untracked':
                    (self.repo / 'new.txt').write_text('new')
                else:
                    self.metadata('14.1.0')
                    if state == 'staged':
                        self.git('add', '.')
                self.assert_refused_without_mutation()
                self.assert_refused_without_mutation(args=['--dry-run'])
                self.git('reset', '--hard', 'HEAD')
                self.git('clean', '-fd')

    def test_invalid_metadata_refused(self):
        for php, composer in [('13.0.0', '13.0.0'), ('14.0.0', '14.1.0'),
                              ('14.00.0', '14.00.0'), ('14.0.0-rc1', '14.0.0-rc1')]:
            with self.subTest(php=php, composer=composer):
                self.metadata(php, composer)
                self.commit()
                self.assert_refused_without_mutation()

    def test_root_composer_version_refused(self):
        path = self.repo / 'composer.json'
        data = json.loads(path.read_text())
        data['version'] = '14.0.0'
        path.write_text(json.dumps(data))
        self.commit()
        self.assert_refused_without_mutation()

    def test_metadata_lower_than_latest_refused(self):
        self.git('tag', 'v14.1.0')
        self.commit()
        self.assert_refused_without_mutation()

    def test_dry_run_makes_zero_writes_including_github_output(self):
        self.git('tag', 'v14.0.0')
        self.commit()
        output = Path(self.tmp.name) / 'output'
        output.write_text('existing=value\n')
        env = dict(self.env, GITHUB_OUTPUT=str(output))
        before = self.git('show-ref')
        before_files = [(self.repo / name).read_bytes()
                        for name in ('composer.json', 'ext_emconf.php')]
        self.assertEqual(self.release('--dry-run', env=env).stdout.strip(), 'v14.0.1')
        self.assertEqual(output.read_text(), 'existing=value\n')
        self.assertEqual(self.git('show-ref'), before)
        self.assertEqual(before_files, [(self.repo / name).read_bytes()
                                       for name in ('composer.json', 'ext_emconf.php')])
        self.assertEqual(self.git('status', '--porcelain'), '')

    def test_github_output_written_on_success(self):
        output = Path(self.tmp.name) / 'output'
        env = dict(self.env, GITHUB_OUTPUT=str(output))
        self.release(env=env)
        self.assertEqual(output.read_text(), 'tag=v14.0.0\n')

    def remote(self):
        remote = Path(self.tmp.name) / 'remote.git'
        self.git('init', '--bare', str(remote))
        self.git('remote', 'add', 'origin', str(remote))
        self.git('push', 'origin', 'HEAD:refs/heads/main')
        return remote

    def test_push_publishes_atomic_branch_and_annotated_tag(self):
        remote = self.remote()
        self.git('tag', 'v14.0.0')
        self.commit()
        trigger = self.git('rev-parse', 'HEAD')
        self.git('push', 'origin', 'HEAD:refs/heads/main')
        self.assertEqual(self.release('--push').stdout.strip(), 'v14.0.1')
        self.assertNotEqual(self.git('rev-parse', 'HEAD'), trigger)
        for ref in ('refs/heads/main', 'refs/tags/v14.0.1'):
            self.assertEqual(self.git('rev-parse', ref, cwd=remote), self.git('rev-parse', ref))
        self.assertEqual(self.release('--push').stdout.strip(), 'v14.0.1')

    def test_failed_push_is_atomic_and_does_not_emit_output(self):
        remote = self.remote()
        hook = remote / 'hooks' / 'update'
        hook.write_text('#!/bin/sh\ncase "$1" in refs/tags/*) exit 1;; esac\nexit 0\n')
        hook.chmod(0o755)
        self.git('tag', 'v14.0.0')
        self.commit()
        self.git('push', 'origin', 'HEAD:refs/heads/main')
        before = self.git('show-ref', cwd=remote)
        output = Path(self.tmp.name) / 'output'
        self.release('--push', ok=False, env=dict(self.env, GITHUB_OUTPUT=str(output)))
        self.assertEqual(self.git('show-ref', cwd=remote), before)
        self.assertFalse(output.exists())

    def test_stale_trigger_cannot_overwrite_advanced_remote(self):
        remote = self.remote()
        trigger = self.git('rev-parse', 'HEAD')
        self.commit('Later change')
        self.git('push', 'origin', 'HEAD:refs/heads/main')
        before = self.git('show-ref', cwd=remote)
        self.git('reset', '--hard', trigger)
        self.release('--push', ok=False)
        self.assertEqual(self.git('show-ref', cwd=remote), before)

    def test_default_does_not_push(self):
        remote = self.remote()
        before = self.git('show-ref', cwd=remote)
        self.release()
        self.assertEqual(self.git('show-ref', cwd=remote), before)

    def test_release_identity_ignores_environment_and_commit_hooks(self):
        self.git('tag', 'v14.0.0')
        self.commit()
        hook = self.repo / '.git' / 'hooks' / 'prepare-commit-msg'
        hook.write_text('#!/bin/sh\nprintf "\\nCo-authored-by: Other <other@example.invalid>\\n" >> "$1"\n')
        hook.chmod(0o755)
        env = dict(self.env, GIT_AUTHOR_NAME='Other', GIT_AUTHOR_EMAIL='other@example.invalid',
                   GIT_COMMITTER_NAME='Other', GIT_COMMITTER_EMAIL='other@example.invalid')
        self.release(env=env)
        for field in ('%an', '%cn'):
            self.assertEqual(self.git('log', '-1', f'--format={field}'), 'github-actions[bot]')
        self.assertNotIn('Co-authored-by:', self.git('log', '-1', '--format=%B'))
        self.assertIn('github-actions[bot]', self.git('for-each-ref', '--format=%(taggername)',
                                                    'refs/tags/v14.0.1'))

    def test_original_trigger_rerun_after_release_commit_is_safe(self):
        remote = self.remote()
        self.git('tag', 'v14.0.0')
        trigger = self.commit()
        self.git('push', 'origin', 'HEAD:refs/heads/main')
        self.release('--push')
        before = self.git('show-ref', cwd=remote)
        self.git('reset', '--hard', trigger)
        self.assert_refused_without_mutation(args=['--push'])
        self.assertEqual(self.git('show-ref', cwd=remote), before)

    def test_duplicate_php_version_refused(self):
        path = self.repo / 'ext_emconf.php'
        path.write_text(path.read_text() + "\n'Version' => 'irrelevant',\n'version' => '14.0.0',\n")
        self.commit()
        self.assert_refused_without_mutation()

    def test_missing_extra_version_refused(self):
        (self.repo / 'composer.json').write_text('{}\n')
        self.commit()
        self.assert_refused_without_mutation()


if __name__ == '__main__':
    unittest.main()
