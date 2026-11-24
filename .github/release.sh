#!/usr/bin/env bash
# Publication of a version of the module, run when a tag vX.Y.Z is pushed: GitHub release with the zip installable
# from Dolibarr (Setup > Modules > Deploy an external module), branch stable moved on it when it is the highest version.
#
# Branches (doc/dev/development.md):
#   develop  daily work, versions raised at each FIX / NEW, nothing published
#   X.Y      a series: created from develop at the freeze (git push origin develop:X.Y), release candidate then
#            published series. FIX only, each one merged back into develop (dev/merge-series.sh)
#   stable   pointer on the highest version published, moved here (fast-forward only), never a branch of work
#   rc, edge pointer on the newest X.Y (.github/workflows/rc.yml), build of develop (deploy.yml)
# Publish: git tag vX.Y.Z origin/X.Y && git push origin vX.Y.Z. The tag must be the version of the descriptor and its
# commit must be on the branch X.Y of the series.
#
# Run by .github/workflows/release.yml. Needs MODULE (dir of the module in the zip), DESCRIPTOR (path of the
# module descriptor), TITLE (name of the module in the release title) and GH_TOKEN.
set -euo pipefail

: "${MODULE:?}" "${DESCRIPTOR:?}" "${TITLE:?}" "${GH_TOKEN:?}"
tag="${GITHUB_REF_NAME:?}"
sha="${GITHUB_SHA:?}"

fail() {
	echo "::error::$*"
	exit 1
}

[ "${GITHUB_REF_TYPE:-}" = tag ] || fail "A version is published by a tag vX.Y.Z, not from the branch $tag"

# true when the version $1 is higher than the version $2
vgt() {
	[ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
}

v=$(grep -oP "\\\$this->version\s*=\s*'\K[0-9]+\.[0-9]+\.[0-9]+(?=')" "$DESCRIPTOR" || true)
[ -n "$v" ] || fail "No version X.Y.Z found in $DESCRIPTOR"
xy="${v%.*}"

[ "$tag" = "v$v" ] || fail "Tag $tag but version $v in $DESCRIPTOR: the tag must be v$v (version raised and committed?)"

if gh release view "v$v" >/dev/null 2>&1; then
	echo "::notice::v$v already published, nothing to do"
	exit 0
fi

# The commit of the tag must be on the branch of its series (freeze first: git push origin develop:$xy)
git ls-remote --exit-code --heads origin "$xy" >/dev/null \
	|| fail "No branch $xy: a version is published from the branch of its series (freeze: git push origin develop:$xy)"
git fetch -q origin "refs/heads/$xy"
git merge-base --is-ancestor "$sha" FETCH_HEAD \
	|| fail "The commit of $tag is not on the branch $xy: tag a commit of $xy"

# Versions already published (the tags but this one), lowest first
tags=$(git tag -l 'v[0-9]*' | sed -n 's/^v\([0-9]\+\.[0-9]\+\.[0-9]\+\)$/\1/p' | grep -vx "${v//./\\.}" | sort -V || true)
last=$(printf '%s\n' "$tags" | tail -n1)
lastxy=$(printf '%s\n' "$tags" | grep "^${xy//./\\.}\." | tail -n1 || true)

if [ -n "$lastxy" ] && ! vgt "$v" "$lastxy"; then
	fail "Version $v is not higher than the last one published in $xy ($lastxy)"
fi

latest=false
if [ -z "$last" ] || vgt "$v" "$last"; then
	latest=true
fi
# Notes: since the previous release of the whole module when this one is the highest, else of its series
if [ "$latest" = true ]; then
	from="$last"
else
	from="$lastxy"
fi

work=$(mktemp -d)

# Dolibarr wants the dir of the module at the root of the zip and a name module_xxx-x.y.z.zip
# (entries export-ignore of .gitattributes left out)
zip="$work/module_${MODULE}-$v.zip"
git archive --format=zip --prefix="$MODULE/" -o "$zip" "$sha"

# Notes: entries of the ChangeLog (« - NEW: ... :   X.Y.Z » and their sub-items) published since the previous
# release of this line, up to $v; without a previous release, the entries of the series X.Y.
# Works with or without sections « ## X.Y ».
awk -v hi="$v" -v lo="$from" -v first="$xy.0" '
	function cmp(a, b,    i, x, y) {
		split(a, x, ".")
		split(b, y, ".")
		for (i = 1; i <= 3; i++) {
			if (x[i] + 0 != y[i] + 0) {
				return (x[i] + 0 > y[i] + 0) ? 1 : -1
			}
		}
		return 0
	}
	function flush() {
		if (entry != "" && ver != "" && cmp(ver, hi) <= 0 && (lo == "" ? cmp(ver, first) >= 0 : cmp(ver, lo) > 0)) {
			out = out entry "\n"
		}
		entry = ""
		ver = ""
	}
	function version() {
		if (ver == "" && $0 ~ /:[ \t]+[0-9]+\.[0-9]+\.[0-9]+[ \t]*$/ && match($0, /[0-9]+\.[0-9]+\.[0-9]+[ \t]*$/)) {
			ver = substr($0, RSTART, RLENGTH)
			gsub(/[ \t]/, "", ver)
		}
	}
	/^#/ { flush(); next }
	/^- / { flush(); entry = $0 "\n"; version(); next }
	/^[ \t]*$/ { next }
	entry != "" { entry = entry $0 "\n"; version() }
	END { flush(); printf "%s", out }
' ChangeLog.md > "$work/notes.md"
[ -s "$work/notes.md" ] || echo "$TITLE $v" > "$work/notes.md"

gh release create "v$v" "$zip" --title "$TITLE $v" --notes-file "$work/notes.md" --target "$sha" --latest="$latest"
echo "::notice::Published v$v (latest: $latest)"

# The branch stable follows the highest version published, fast-forward only: a Git deployment on stable always
# moves forward with git pull. Never forced: a warning when it cannot follow
if [ "$latest" = true ]; then
	if ! git ls-remote --exit-code --heads origin stable >/dev/null; then
		git push -q origin "$sha:refs/heads/stable"
		echo "::notice::Branch stable created on v$v"
	else
		git fetch -q origin refs/heads/stable
		if git merge-base --is-ancestor FETCH_HEAD "$sha"; then
			git push -q origin "$sha:refs/heads/stable"
			echo "::notice::Branch stable moved on v$v"
		else
			echo "::warning::Branch stable not moved: v$v does not hold its last commit (the series $xy does not start from a develop that holds stable). Merge the previous series into develop before the next freeze."
		fi
	fi
fi

# Optional copy on SourceForge (download stats): only the releases marked Latest (never the fixes of an older series),
# and only when SF_PROJECT is set. The zip goes by rsync over SSH
# (SF_USER + private key SF_SSH_KEY) into the folder X.Y.Z of the project files; when the release is Latest, the
# Release API key SF_API_KEY (optional) makes it the default download of every platform.
# Never fatal: the GitHub release is already published, a failure only leaves a warning with the command to replay.
sf_publish() {
	local key="$work/sf_key" dest="/home/frs/project/$SF_PROJECT/$v/"
	# A missing setting gives the warning below, never the end of the script (${VAR:?} would exit the whole shell)
	if [ -z "${SF_USER:-}" ] || [ -z "${SF_SSH_KEY:-}" ]; then
		echo "::warning::SourceForge: the variable SF_USER (Settings > Secrets and variables > Actions > Variables) or the secret SF_SSH_KEY is empty"
		return 1
	fi
	(umask 077 && printf '%s\n' "$SF_SSH_KEY" > "$key") || return 1
	# The host key of SourceForge is the one kept in the repository: never accepted unseen (audit INBX-109)
	rsync -e "ssh -i $key -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$(cd "$(dirname "$0")" && pwd)/sourceforge_known_hosts" \
		"$zip" "$SF_USER@frs.sourceforge.net:$dest" || return 1
	if [ "$latest" = true ] && [ -n "${SF_API_KEY:-}" ]; then
		# The API key read from a file, never on the command line (seen in the list of the processes, audit INBX-110)
		(umask 077 && printf '%s' "$SF_API_KEY" > "$work/sf_api") || return 1
		curl -fsS -X PUT -H 'Accept: application/json' \
			-d default=windows -d default=mac -d default=linux -d default=bsd -d default=solaris -d default=others \
			--data-urlencode "api_key@$work/sf_api" \
			"https://sourceforge.net/projects/$SF_PROJECT/files/$v/$(basename "$zip")" >/dev/null || { rm -f "$work/sf_api"; return 1; }
		rm -f "$work/sf_api"
	fi
}
if [ "$latest" = true ] && [ -n "${SF_PROJECT:-}" ]; then
	if sf_publish; then
		echo "::notice::Copied on SourceForge: https://sourceforge.net/projects/$SF_PROJECT/files/$v/"
	else
		echo "::warning::SourceForge copy failed, replay by hand: rsync $(basename "$zip") <user>@frs.sourceforge.net:/home/frs/project/$SF_PROJECT/$v/"
	fi
fi
rm -f "$work/sf_key" "$work/sf_api"
