#!/usr/bin/env bash
# Merge a series X.Y into develop (its fixes go back into the next versions), with the two conflicts that every such
# merge meets solved alone:
#   - core/modules/modInbox.class.php: the line of the version, develop always keeps its own (higher) one;
#   - ChangeLog.md: the entries added on both sides, all kept (develop first). Their order does not matter for the
#     notes of the releases, .github/release.sh picks the entries by number of version.
# Any other conflict stops the script, the merge left in progress to finish by hand (git merge --abort to give up).
#
# Usage (developer only, from the module directory, on develop with a clean tree):
#   dev/merge-series.sh 1.135
# Then: git push origin develop
set -euo pipefail

xy="${1:-}"
[[ "$xy" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Usage: dev/merge-series.sh X.Y" >&2; exit 2; }
cd "$(dirname "$0")/.."

descriptor=core/modules/modInbox.class.php
[ "$(git rev-parse --abbrev-ref HEAD)" = develop ] || { echo "Run it on the branch develop" >&2; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo "Commit or set aside your changes first" >&2; exit 1; }

git fetch -q origin "refs/heads/$xy:refs/remotes/origin/$xy"
if git merge-base --is-ancestor "origin/$xy" HEAD; then
	echo "develop already holds $xy, nothing to merge"
	exit 0
fi

if git merge --no-ff --no-commit -m "Merge branch '$xy' into develop" "origin/$xy"; then
	git commit -q --no-edit
	echo "Merged $xy without conflict. Next: git push origin develop"
	exit 0
fi

# Solve the known conflicts, leave the others
python3 - "$descriptor" <<'PY'
import re
import subprocess
import sys

descriptor = sys.argv[1]
unmerged = subprocess.run(['git', 'diff', '--name-only', '--diff-filter=U'], capture_output=True, text=True,
                          check=True).stdout.split()
VERSION = re.compile(r"^\s*\$this->version\s*=\s*'[0-9.]+';\s*$")


def blocks(text):
    """Split a file into plain text and conflict blocks (ours, theirs), diff3 style included"""
    out, ours, theirs, state = [], [], [], None
    for line in text.splitlines(keepends=True):
        if line.startswith('<<<<<<< '):
            state, ours, theirs = 'ours', [], []
        elif line.startswith('||||||| ') and state == 'ours':
            state = 'base'
        elif line.startswith('=======') and state in ('ours', 'base'):
            state = 'theirs'
        elif line.startswith('>>>>>>> ') and state == 'theirs':
            out.append((ours, theirs))
            state = None
        elif state == 'ours':
            ours.append(line)
        elif state == 'theirs':
            theirs.append(line)
        elif state is None:
            out.append(line)
    return out


for path in unmerged:
    if path not in (descriptor, 'ChangeLog.md'):
        continue
    with open(path, encoding='utf-8') as f:
        parts = blocks(f.read())
    result, solved = [], True
    for part in parts:
        if isinstance(part, str):
            result.append(part)
            continue
        ours, theirs = part
        if path == 'ChangeLog.md':
            # Both entries kept, a blank line between them when needed
            result.extend(ours)
            if ours and theirs and ours[-1].strip() and theirs[0].strip():
                result.append('\n')
            result.extend(theirs)
        elif all(VERSION.match(x) for x in ours + theirs if x.strip()):
            result.extend(ours)
        else:
            solved = False
            break
    if solved:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(''.join(result))
        subprocess.run(['git', 'add', path], check=True)
        print('solved: ' + path)
PY

left=$(git diff --name-only --diff-filter=U)
if [ -n "$left" ]; then
	echo "Conflicts left, to solve by hand then git commit (or git merge --abort):" >&2
	printf '  %s\n' $left >&2
	exit 1
fi
php -l "$descriptor" >/dev/null
git commit -q --no-edit
echo "Merged $xy, version and ChangeLog solved. Next: git push origin develop"
