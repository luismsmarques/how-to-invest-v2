"""Build the news-card query string from the environment build.sh sets.

Its own file because quoting a URL-encoded string through two layers of shell
is how a stray & silently truncates a headline.
"""
import os
import urllib.parse

FIELDS = ('eyebrow', 'head', 'value', 'label', 'sub', 'note', 'vs', 'vslabel')
pairs = {k: os.environ.get(k.upper(), '') for k in FIELDS}
print(urllib.parse.urlencode({k: v for k, v in pairs.items() if v != ''}))
