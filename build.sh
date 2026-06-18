#!/bin/bash
#
# Build a distributable FreePBX module tarball for "loneworker", and optionally
# self-sign it (creates module.sig like Sangoma-signed modules).
#
# Usage:
#   ./build.sh                      # compile .mo + build /tmp/loneworker-<version>.tgz
#   ./build.sh --sign <GPG_KEYID>   # also create a signed module.sig before packaging
#
# Install the resulting tarball on any PBX with:
#   fwconsole ma downloadinstall <url-or-path-to>/loneworker-<version>.tgz
#   (or GUI: Admin -> Module Admin -> Upload modules)

set -e
cd "$(dirname "$0")"
RAW="loneworker"
VER="$(grep -oP '(?<=<version>)[^<]+' module.xml | head -1)"

# 1) Compile the Italian translation (.po -> .mo) so the package is self-contained.
if command -v msgfmt >/dev/null 2>&1; then
	mkdir -p i18n/it_IT/LC_MESSAGES i18n/it/LC_MESSAGES
	msgfmt i18n/loneworker.po -o i18n/it_IT/LC_MESSAGES/loneworker.mo
	cp i18n/it_IT/LC_MESSAGES/loneworker.mo i18n/it/LC_MESSAGES/loneworker.mo
	echo "compiled i18n/it_IT/LC_MESSAGES/loneworker.mo"
fi

# 2) Optional self-sign: build module.sig (clearsigned manifest of SHA256 file hashes).
if [ "$1" = "--sign" ]; then
	KEYID="$2"
	[ -n "$KEYID" ] || { echo "usage: ./build.sh --sign <GPG_KEYID>"; exit 1; }
	SIGNEDBY="$(gpg --list-keys --with-colons "$KEYID" | awk -F: '/^uid:/{print $10; exit}')"
	TS="$(date +%s).0000"
	MAN="$(mktemp)"
	{
		echo ";################################################"
		echo ";#        FreePBX Module Signature File         #"
		echo ";################################################"
		echo "[config]"
		echo "version=1"
		echo "hash=sha256"
		echo "signedwith=${KEYID}"
		echo "signedby='${SIGNEDBY}'"
		echo "repo=local"
		echo "timestamp=${TS}"
		echo "[hashes]"
		# every file except VCS, build artifacts and module.sig itself
		find . -type f \
			! -path './.git/*' ! -name 'module.sig' ! -name '*.tgz' ! -name '*.tar.gz' \
			! -name 'build.sh' ! -name '.gitignore' \
			| sed 's|^\./||' | sort \
			| while read -r f; do echo "$f = $(sha256sum "$f" | cut -d' ' -f1)"; done
	} > "$MAN"
	gpg --batch --yes --default-key "$KEYID" --clearsign -o module.sig "$MAN"
	rm -f "$MAN"
	echo "signed -> module.sig (key ${KEYID})"
fi

# 3) Package: the tarball must contain a top-level <RAW>/ directory.
OUT="/tmp/${RAW}-${VER}.tgz"
cd ..
tar czf "$OUT" --exclude='.git' --exclude='*.tgz' --exclude='*.tar.gz' "$RAW"
echo "built $OUT"
