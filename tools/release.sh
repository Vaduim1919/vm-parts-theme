#!/bin/bash
# Випуск нової версії теми: tools/release.sh 1.1.1 "Що нового"
# Збирає releases/vm_parts-<версія>.ocmod.zip з src/upload і оновлює latest.json
set -e
cd "$(dirname "$0")/.."
VER="$1"; NOTES="$2"
[ -z "$VER" ] && { echo "Вкажіть версію: tools/release.sh 1.1.1 \"Що нового\""; exit 1; }
echo "$VER" > src/upload/catalog/view/theme/vm_parts/VERSION
ZIP="releases/vm_parts-$VER.ocmod.zip"
rm -f "$ZIP"
(cd src && zip -qr "../$ZIP" upload)
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1)
python3 - "$VER" "$NOTES" "$ZIP" "$SHA" > latest.json <<'PY'
import json, sys, datetime
v, notes, z, sha = sys.argv[1:5]
print(json.dumps({"version": v, "date": datetime.date.today().isoformat(), "notes": notes, "zip": z, "sha256": sha}, ensure_ascii=False, indent=2))
PY
echo "Готово: $ZIP ($SHA). Далі: git add -A && git commit -m \"Release $VER\" && git push"
