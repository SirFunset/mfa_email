#!/usr/bin/env python3
"""Release branch-specific versions using only Python's standard library.

Run from a clean repository root on an attached supported branch. CI must fetch
all history/tags and attach the requested branch at the exact triggering SHA.
Without --push only the local repository changes. --dry-run never writes,
including GITHUB_OUTPUT. Output on success is exactly the selected tag.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

BRANCH_MAJORS = {'main': 14, 'main-v13': 13, 'main-v12': 12}
BOT_NAME = 'github-actions[bot]'
BOT_EMAIL = '41898282+github-actions[bot]@users.noreply.github.com'


def git(*args, bot=False):
    env = dict(os.environ)
    if bot:
        env.update(GIT_AUTHOR_NAME=BOT_NAME, GIT_AUTHOR_EMAIL=BOT_EMAIL,
                   GIT_COMMITTER_NAME=BOT_NAME, GIT_COMMITTER_EMAIL=BOT_EMAIL)
    result = subprocess.run(['git', *args], text=True, capture_output=True, env=env)
    if result.returncode:
        raise ValueError(f"git {args[0]} failed: {result.stderr.strip()}")
    return result.stdout.strip()


VERSION_RE = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)')
PHP_VERSION_RE = re.compile(r"(['\"])version\1\s*=>\s*(['\"])([^'\"]*)\2")


def version_tuple(value):
    if not isinstance(value, str) or not VERSION_RE.fullmatch(value):
        raise ValueError('versions must be canonical MAJOR.MINOR.PATCH strings')
    return tuple(map(int, value.split('.')))


def publish(args, tag):
    if args.push:
        # The explicit lease rejects racing/stale workflows, even when our
        # proposed update would happen to be a fast-forward. The ancestry
        # check ensures the lease is never used to rewrite branch history.
        git('merge-base', '--is-ancestor', args.expected_sha, 'HEAD')
        git('-c', 'push.followTags=false', 'push', '--atomic',
            '--recurse-submodules=no',
            f'--force-with-lease=refs/heads/{args.branch}:{args.expected_sha}',
            'origin', f'HEAD:refs/heads/{args.branch}',
            f'refs/tags/{tag}:refs/tags/{tag}')
        refs = dict(line.split()[::-1] for line in git(
            'ls-remote', '--refs', 'origin', f'refs/heads/{args.branch}',
            f'refs/tags/{tag}').splitlines())
        for ref, expected in ((f'refs/heads/{args.branch}', git('rev-parse', 'HEAD')),
                              (f'refs/tags/{tag}', git('rev-parse', f'refs/tags/{tag}'))):
            if refs.get(ref) != expected:
                raise ValueError('remote refs changed or could not be verified after push')
    return tag


def release(args):
    # Do not refresh the index even on the read-only validation path.
    if git('--no-optional-locks', 'status', '--porcelain', '--untracked-files=all'):
        raise ValueError('working tree and index must be clean')
    if args.branch not in BRANCH_MAJORS:
        raise ValueError('unsupported branch')
    if git('branch', '--show-current') != args.branch:
        raise ValueError('checkout must be attached to the requested branch')
    if Path(git('rev-parse', '--show-toplevel')).resolve() != Path.cwd().resolve():
        raise ValueError('run from the repository root')
    head = git('rev-parse', 'HEAD')
    if not re.fullmatch(r'(?:[0-9a-f]{40}|[0-9a-f]{64})', args.expected_sha):
        raise ValueError('expected SHA must be a full lowercase hexadecimal object ID')
    if head != args.expected_sha:
        raise ValueError('HEAD does not equal the expected trigger SHA')
    if git('rev-parse', '--is-shallow-repository') == 'true':
        raise ValueError('full history and tags required (checkout fetch-depth: 0)')
    composer_path = Path('composer.json')
    php_path = Path('ext_emconf.php')
    for path in (composer_path, php_path):
        if path.is_symlink() or not path.is_file():
            raise ValueError(f'{path} must be a regular tracked file')
        git('ls-files', '--error-unmatch', '--', str(path))
    composer = json.loads(composer_path.read_text())
    if 'version' in composer:
        raise ValueError('remove root composer.version; Composer versions come from tags')
    metadata = version_tuple(composer['extra']['typo3/cms']['version'])
    php_text = php_path.read_text()
    php_versions = list(PHP_VERSION_RE.finditer(php_text))
    if len(php_versions) != 1 or version_tuple(php_versions[0].group(3)) != metadata:
        raise ValueError('exactly one ext_emconf.php version must match Composer extra version')
    if metadata[0] != BRANCH_MAJORS[args.branch]:
        raise ValueError('metadata version major does not match branch')
    tags = {}
    for tag in git('tag', '--list').splitlines():
        if tag.startswith('v') and VERSION_RE.fullmatch(tag[1:]):
            version = version_tuple(tag[1:])
            if version[0] == BRANCH_MAJORS[args.branch]:
                tags[version] = tag
    head = git('rev-parse', 'HEAD')
    exact = [version for version, tag in tags.items()
             if git('rev-parse', f'refs/tags/{tag}^{{commit}}') == head]
    if exact and metadata in exact:
        return tags[metadata] if args.dry_run else publish(args, tags[metadata])
    latest = max(tags) if tags else None
    if latest is not None and metadata < latest:
        raise ValueError('metadata version is lower than the latest release tag')
    version = metadata if latest is None or metadata > latest else (
        latest[0], latest[1], latest[2] + 1)
    version_text = '.'.join(map(str, version))
    tag = f'v{version_text}'
    if args.dry_run:
        return tag
    if version != metadata:
        composer['extra']['typo3/cms']['version'] = version_text
        composer_path.write_text(json.dumps(composer, indent=4, ensure_ascii=False) + '\n')
        php_path.write_text(PHP_VERSION_RE.sub(
            lambda match: match.group(0).replace(match.group(3), version_text), php_text))
        git('add', '--', 'composer.json', 'ext_emconf.php')
        git('-c', f'user.name={BOT_NAME}', '-c', f'user.email={BOT_EMAIL}',
            '-c', f'core.hooksPath={os.devnull}', '-c', 'commit.gpgSign=false',
            'commit', '-m', f'chore: release {tag}', bot=True)
    git('-c', f'user.name={BOT_NAME}', '-c', f'user.email={BOT_EMAIL}',
        '-c', 'tag.gpgSign=false', 'tag', '-a', tag, '-m', f'Release {tag}', bot=True)
    return publish(args, tag)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--branch', required=True)
    parser.add_argument('--expected-sha', required=True)
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--push', action='store_true')
    args = parser.parse_args()
    try:
        tag = release(args)
        if not args.dry_run and os.environ.get('GITHUB_OUTPUT'):
            with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
                output.write(f'tag={tag}\n')
        print(tag)
    except (ValueError, OSError, KeyError, TypeError) as exc:
        print(f'Release refused: {exc}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
