#!/usr/bin/env python3
"""Two checks that must not be eyeballed, across the code we wrote.

1. Every admin_post* registration -> does its handler check capability AND referer?
2. Every unprepared $wpdb call -> which variables are interpolated into it?

Run from the repository root:

    python3 .claude/skills/security-audit/scripts/audit-handlers.py

Exit code is 0 always: this reports, it does not gate. Read the table.
"""
import io
import os
import re
import sys

SCOPE = [
    'wp-content/themes/howtoinvest',
    'wp-content/plugins/hti-engine',
    'wp-content/plugins/hti-forex',
    'wp-content/plugins/hti-rss-ai',
    'wp-content/plugins/hti-social',
]


def php_files():
    for d in SCOPE:
        for root, _, names in os.walk(d):
            if '/tests' in root or '/vendor' in root:
                continue
            for n in names:
                if n.endswith('.php'):
                    yield os.path.join(root, n)


def read(path):
    return io.open(path, encoding='utf-8', errors='replace').read()


def check_handlers(files):
    """admin_post registrations and whether the handler gates itself."""
    reg = re.compile(
        r"add_action\(\s*'admin_post(_nopriv)?_(?:([a-z0-9_]+)'|'\s*\.\s*self::ACTION)"
        r"[^,]*,\s*array\(\s*__CLASS__\s*,\s*'([a-z0-9_]+)'"
    )
    print('=== admin_post handlers ===\n')
    gaps = 0
    for f in sorted(files):
        src = read(f)
        for m in reg.finditer(src):
            nopriv, action, method = m.group(1), m.group(2) or '(self::ACTION)', m.group(3)
            fn = re.search(r"function\s+" + re.escape(method) + r"\s*\([^)]*\)[^{]*\{", src)
            if not fn:
                print(f"  ?? {os.path.basename(f)} {method} — handler not found")
                gaps += 1
                continue
            body = src[fn.end():fn.end() + 1400]
            cap = bool(re.search(r"current_user_can|is_user_logged_in", body))
            ref = bool(re.search(r"check_admin_referer|wp_verify_nonce|check_ajax_referer", body))
            tok = bool(re.search(r"hash_equals", body))
            line = src[:fn.start()].count('\n') + 1
            ok = (cap and ref) or (nopriv and (ref or tok))
            if not ok:
                gaps += 1
            flag = '' if ok else '   <-- REVIEW'
            print(
                f"  {'PUBLIC ' if nopriv else '       '}"
                f"{'cap+' if cap else 'cap-'}{'nonce' if ref else 'NONCE'}"
                f"{'+hmac' if tok else ''}  "
                f"{os.path.basename(f)}:{line} {method} ({action}){flag}"
            )
    print(f"\n  {gaps} needing review\n")


def check_sql(files):
    """Unprepared $wpdb calls, and what gets interpolated into them."""
    call = re.compile(r"\$wpdb->(query|get_var|get_row|get_col|get_results|prepare)\s*\(")
    print('=== unprepared $wpdb calls ===\n')
    risky = 0
    for f in sorted(files):
        src = read(f)
        for m in call.finditer(src):
            if m.group(1) == 'prepare':
                continue
            depth, out = 1, []
            for ch in src[m.end():m.end() + 900]:
                if ch == '(':
                    depth += 1
                elif ch == ')':
                    depth -= 1
                    if depth == 0:
                        break
                out.append(ch)
            arg = ''.join(out)
            if '$wpdb->prepare' in arg:
                continue
            names = {v for v in re.findall(r"\$\{?([A-Za-z_][A-Za-z0-9_]*)", arg) if v != 'wpdb'}
            # Only a table name derived from $wpdb->prefix is safe unprepared.
            bad = sorted(n for n in names if not re.search(r"table|prefix", n, re.I))
            line = src[:m.start()].count('\n') + 1
            if bad:
                risky += 1
            print(
                f"  {os.path.basename(f)}:{line} {m.group(1)} "
                f"vars={sorted(names)}{'   <-- REVIEW: ' + ', '.join(bad) if bad else ''}"
            )
    print(f"\n  {risky} needing review\n")


if __name__ == '__main__':
    if not os.path.isdir('wp-content'):
        sys.exit('Run me from the repository root.')
    files = list(php_files())
    print(f"{len(files)} PHP files in scope (tests and vendor excluded)\n")
    check_handlers(files)
    check_sql(files)
