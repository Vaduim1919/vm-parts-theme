#!/bin/bash
# Випуск модуля V&M Каталог: tools/release-catalog.sh 1.0.1 "Що нового"
set -e
cd "$(dirname "$0")/.."
V="$1"; NOTES="$2"
[ -z "$V" ] && { echo "usage: $0 <version> \"notes\""; exit 1; }
echo "$V" > catalog/src/upload/admin/model/extension/module/vm_catalog_data/VERSION
find catalog/src/upload -name '*.php' -exec php -l {} \; | grep -v "No syntax errors" || true
mkdir -p catalog/releases
ZIP="catalog/releases/vm_catalog-$V.ocmod.zip"
rm -f "$ZIP"
(cd catalog/src && zip -qr "../../$ZIP" upload)
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1)
python3 - "$V" "$NOTES" "releases/vm_catalog-$V.ocmod.zip" "$SHA" <<'PY'
import json,sys,datetime
v,notes,zip_,sha=sys.argv[1:]
json.dump({"version":v,"date":datetime.date.today().isoformat(),"notes":notes,"zip":zip_,"sha256":sha},open('catalog/latest.json','w'),ensure_ascii=False,indent=2)
PY
echo "Готово: $ZIP ($SHA)"
