"""Archive the live source, including untracked files and verification evidence."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import zipfile


root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("output", nargs="?", type=Path,
                    default=root.parent / "Outputs" / "nexus-enterprise-import-export-FINAL-v2.zip")
output = parser.parse_args().output.resolve()
excluded = {"vendor", ".git", ".phpunit.cache", "build", "storage", "node_modules", "__pycache__"}
manifest_path = root / "verification" / "archive-manifest.json"


def source_files():
    for parent, directories, names in os.walk(root):
        directories[:] = sorted(d for d in directories if d not in excluded)
        for name in sorted(names):
            path = Path(parent) / name
            if path.is_symlink() or path == output or name.endswith((".zip", ".pyc")):
                continue
            yield path


# The full archive includes untracked source; the patch supplements that source.
if (root / ".git").exists():
    patch = subprocess.check_output(["git", "diff", "HEAD", "--", "."], cwd=root)
    (root / "changes.patch").write_bytes(patch)
    status = subprocess.check_output(["git", "status", "--short", "--untracked-files=all"], cwd=root)
    (root / "verification" / "git-status.txt").write_bytes(status)

entries = []
for path in source_files():
    if path == manifest_path:
        continue
    data = path.read_bytes()
    entries.append({"path": path.relative_to(root).as_posix(), "bytes": len(data),
                    "sha256": hashlib.sha256(data).hexdigest()})
manifest_path.write_text(json.dumps({"source": "current live working tree",
                                   "verification": "docs/VERIFICATION.md",
                                   "files": entries}, indent=2) + "\n")

output.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for path in source_files():
        archive.write(path, Path(root.name) / path.relative_to(root))

with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None, "Archive CRC verification failed"
    for entry in entries:
        data = archive.read(root.name + "/" + entry["path"])
        assert hashlib.sha256(data).hexdigest() == entry["sha256"], entry["path"]

print(json.dumps({"archive": str(output), "files": len(entries) + 1,
                  "bytes": output.stat().st_size,
                  "sha256": hashlib.sha256(output.read_bytes()).hexdigest()}, indent=2))
