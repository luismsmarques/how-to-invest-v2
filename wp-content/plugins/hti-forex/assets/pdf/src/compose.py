"""Compose the render source for build.sh: inject the optional partner images.

Kept as its own file rather than a heredoc inside build.sh — a Python program
nested in a shell heredoc inside another heredoc is how the terminators start
eating each other.
"""
import os
import sys

src, tmp = sys.argv[1], sys.argv[2]
banner = os.environ['BANNER_FILE']
logo = os.environ['LOGO_FILE']
html = open(src, encoding='utf-8').read()

if os.path.isfile(banner):
    banner_markup = (
        '<div class="adslot">'
        '<span class="adslot__label">Partner &middot; Ad</span>'
        '<a href="https://howtoinvest.pro/forex/go/cheatsheet-banner/">'
        f'<img src="{banner}" width="600" height="90" alt="Advertisement">'
        '</a>'
        '<span class="adslot__risk">Advertising link &mdash; we may be paid if you open an account, '
        'at no cost to you. Forex and CFDs are high-risk leveraged products; most retail accounts lose money.</span>'
        '</div>'
    )
    print(f'Banner: {banner} found - injected on page 1.')
else:
    banner_markup = ''
    print(f'Banner: no {banner} - building without it (text partner block only).')

if os.path.isfile(logo):
    logo_markup = f'<img class="partner__logo" src="{logo}" alt="XM">'
    print(f'Logo:   {logo} found - injected in the page-2 partner block.')
else:
    logo_markup = ''
    print(f'Logo:   no {logo} - the partner block stays unnamed.')

html = html.replace('<!--XM_BANNER-->', banner_markup).replace('<!--XM_LOGO-->', logo_markup)
open(tmp, 'w', encoding='utf-8').write(html)
