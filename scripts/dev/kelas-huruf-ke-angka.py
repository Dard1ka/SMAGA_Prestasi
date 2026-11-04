# smaga_server (D-175, lead 4 Nov 2026): lettered class names back to numbers, X-A -> X-1 ... XII-L -> XII-12, also the
# student letter format "XI - D" -> "XI - 4". Run it after copying code from Smaga4 (which keeps the letters of D-165):
#
#   python -I scripts/dev/kelas-huruf-ke-angka.py            (dry run: counts only)
#   python -I scripts/dev/kelas-huruf-ke-angka.py --apply
#
# Left alone: migrations, the test of the D-165 migrations (KelasHurufTest), the test of the renumbering itself
# (KelasNomorTest), ClassroomSeeder (written by hand) and KelasTest (the Admin types arbitrary suffixes such as "K"
# there). Lowercase names in tests ("xi-d") need a hand fix.
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DIRS = ['app', 'resources/views', 'database/seeders', 'database/factories', 'tests', 'routes', 'lang']
SKIP = {
    'tests/Feature/DataLayer/KelasHurufTest.php',
    'tests/Feature/Admin/KelasTest.php',
    'tests/Feature/DataLayer/KelasNomorTest.php',
    'database/seeders/ClassroomSeeder.php',
}
EXT = ('.php', '.json')

dash = re.compile(r'\b(XII|XI|X)-([A-L])\b')
spaced = re.compile(r'\b(XII|XI|X) - ([A-L])\b')


def number(match, sep):
    return f'{match.group(1)}{sep}{ord(match.group(2)) - 64}'


apply = len(sys.argv) > 1 and sys.argv[1] == '--apply'
files = hits = 0
for d in DIRS:
    for base, _, names in os.walk(os.path.join(ROOT, d)):
        for name in names:
            if not name.endswith(EXT):
                continue
            path = os.path.join(base, name)
            rel = os.path.relpath(path, ROOT).replace(os.sep, '/')
            if rel in SKIP:
                continue
            text = open(path, encoding='utf-8', newline='').read()
            new, n1 = dash.subn(lambda m: number(m, '-'), text)
            new, n2 = spaced.subn(lambda m: number(m, ' - '), new)
            if n1 + n2:
                files += 1
                hits += n1 + n2
                if apply:
                    open(path, 'w', encoding='utf-8', newline='').write(new)
print(('applied' if apply else 'dry run'), files, 'files,', hits, 'replacements')
