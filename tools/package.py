#!/usr/bin/env python3
"""Create an installable Moodle plugin ZIP; exclude archives, credentials and test outputs."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
release = re.search(r"\$plugin->release = '([^']+)'", (root / "version.php").read_text()).group(1)
output = root / "build" / f"tomb-{release}.zip"
output.parent.mkdir(exist_ok=True)
allowed = {"classes", "db", "lang", "assets", "cli", "docs", "tests"}
# Decorative README artwork is kept in Git but omitted from the installable ZIP.
readme_only_assets = {"docs/images/tomb-memoria.png"}
files = sorted(p for p in root.rglob("*") if p.is_file() and
               p.relative_to(root).as_posix() not in readme_only_assets and
               (p.relative_to(root).parts[0] in allowed or
                (p.parent == root and (p.suffix in {".php", ".css"} or p.name in {"README.md", "LICENSE"}))))
with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for path in files:
        archivepath = "tomb/" + path.relative_to(root).as_posix()
        if path == root / "README.md":
            readme = re.sub(r"<!-- README-only:start -->.*?<!-- README-only:end -->\n\n",
                            "", path.read_text(encoding="utf-8"), flags=re.DOTALL)
            archive.writestr(archivepath, readme.encode("utf-8"))
        else:
            archive.write(path, archivepath)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix(".zip.sha256").write_text(f"{digest}  {output.name}\n", encoding="ascii")
print(f"{output.name}: {len(files)} files, {output.stat().st_size} bytes, SHA-256 {digest}")
