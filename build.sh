#!/bin/bash
#
# Build a distributable FreePBX module tarball for "loneworker", and optionally
# self-sign it (creates module.sig like Sangoma-signed modules).
#
# The output tarball always contains a top-level "loneworker/" folder (the FreePBX
# rawname), regardless of the name of the folder this repo is checked out into — so
# it works both locally and in CI (where the checkout dir is the repo name).
#
# Usage:
#   ./build.sh                      # compile .mo + build ./loneworker-<version>.tgz
#   ./build.sh --sign <GPG_KEYID>   # also create a signed module.sig before packaging
#
# Install the resulting tarball on any PBX with:
#   fwconsole ma downloadinstall <url-or-path>/loneworker-<version>.tgz && fwconsole reload

set -e
cd "$(dirname "$0")"
ROOT="$PWD"
RAW="loneworker"
VER="$(grep -oP '(?<=<version>)[^<]+' module.xml | head -1)"
OUT="$ROOT/${RAW}-${VER}.tgz"

# 1) Compile the Italian translation (.po -> .mo) so the package is self-contained.
if command -v msgfmt >/dev/null 2>&1; then
	mkdir -p i18n/it_IT/LC_MESSAGES i18n/it/LC_MESSAGES
	msgfmt i18n/loneworker.po -o i18n/it_IT/LC_MESSAGES/loneworker.mo
	cp i18n/it_IT/LC_MESSAGES/loneworker.mo i18n/it/LC_MESSAGES/loneworker.mo
	echo "compiled i18n/*/LC_MESSAGES/loneworker.mo"
fi

# 2) Stage the files into <tmp>/loneworker/, excluding VCS/CI and build artifacts.
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/$RAW"
tar -cf - \
	--exclude='./.git' --exclude='./.github' --exclude='./.gitignore' \
	--exclude='./.gitattributes' --exclude='./build.sh' \
	--exclude='*.tgz' --exclude='*.tar.gz' \
	-C "$ROOT" . | tar -xf - -C "$STAGE/$RAW"

# 3) Optional self-sign: build module.sig (clearsigned manifest of SHA256 file hashes).
if [ "$1" = "--sign" ]; then
	KEYID="$2"
	[ -n "$KEYID" ] || { echo "usage: ./build.sh --sign <GPG_KEYID>"; exit 1; }
	SIGNEDBY="$(gpg --list-keys --with-colons "$KEYID" | awk -F: '/^uid:/{print $10; exit}')"
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
		echo "timestamp=$(date +%s).0000"
		echo "[hashes]"
		( cd "$STAGE/$RAW" && find . -type f ! -name 'module.sig' | sed 's|^\./||' | sort \
			| while read -r f; do echo "$f = $(sha256sum "$f" | cut -d' ' -f1)"; done )
	} > "$MAN"
	gpg --batch --yes --default-key "$KEYID" --clearsign -o "$STAGE/$RAW/module.sig" "$MAN"
	rm -f "$MAN"
	echo "signed -> module.sig (key ${KEYID})"
fi

# 4) Package and clean up.
tar -C "$STAGE" -czf "$OUT" "$RAW"
rm -rf "$STAGE"
echo "built $OUT"
