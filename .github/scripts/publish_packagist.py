#!/usr/bin/env python3
"""Request a Packagist refresh and verify the release's exact source commit."""

import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

PACKAGE = 'funset/mfa-email'


def request_json(url, payload=None):
    data = None if payload is None else json.dumps(payload).encode('utf-8')
    request = urllib.request.Request(url, data=data, headers={
        'Content-Type': 'application/json',
        'User-Agent': 'funset-mfa-email-release',
    })
    # Do not print request URLs: the update endpoint includes an API token.
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def publish(username, token, tag, expected_sha, attempts=30):
    if not re.fullmatch(r'v(?:12|13|14)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)', tag):
        raise ValueError('Not a supported release tag')
    if not username or not token or not expected_sha:
        raise ValueError('Packagist credentials and source SHA are required')
    query = urllib.parse.urlencode({'username': username, 'apiToken': token})
    result = request_json('https://packagist.org/api/update-package?' + query, {
        'repository': {'url': 'https://packagist.org/packages/' + PACKAGE},
    })
    if result.get('status') != 'success':
        raise RuntimeError('Packagist rejected the update request')
    for attempt in range(attempts):
        try:
            # A cache-busting query prevents observing a stale CDN response.
            data = request_json('https://repo.packagist.org/p2/' + PACKAGE + '.json?release=' + str(time.time_ns()))
            for version in data.get('packages', {}).get(PACKAGE, []):
                if version.get('version', '').lstrip('v') == tag[1:] and version.get('source', {}).get('reference') == expected_sha:
                    print('Packagist indexed ' + PACKAGE + ' ' + tag + ' at ' + expected_sha)
                    return
        except urllib.error.HTTPError as error:
            if error.code not in (404, 429, 500, 502, 503, 504):
                raise
        except urllib.error.URLError:
            pass
        if attempt + 1 < attempts:
            time.sleep(10)
    raise RuntimeError('Packagist has not indexed the expected release commit; retry the workflow')


def main():
    try:
        tag = os.environ['RELEASE_TAG']
        sha = subprocess.check_output(['git', 'rev-parse', '--verify', tag + '^{commit}'], text=True).strip()
        publish(os.environ['PACKAGIST_USERNAME'], os.environ['PACKAGIST_API_TOKEN'], tag, sha)
    except (KeyError, ValueError, RuntimeError, subprocess.CalledProcessError, urllib.error.URLError) as error:
        # Network exceptions may embed a credential-bearing URL. Print only type.
        print('Packagist publish failed (' + type(error).__name__ + '). Check credentials, package registration, and indexing; retry the workflow.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
