"""Build a reproducible install archive without development tools or retired utilities."""
import argparse
import gzip
import hashlib
import io
import os
from pathlib import Path
import subprocess
import tarfile
import json
from xml.etree import ElementTree

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ("front", "inc", "src", "sql", "templates", "resources", "marketplace", "js")
FILES = ("VERSION", "LICENSE", "README.md", "CHANGELOG.md", "composer.json", "composer.lock",
         "setup.php", "hook.php", "uxconnector.xml", "tools/rekey-credential-vault.php")
DOCUMENTS = (
    'docs/EasyVista_Service_Manager.md',
    'docs/README_Matrix42_UXConnector.md',
    'docs/SECURITY_RC11.md',
    'docs/SECURITY_RC12.md',
    'docs/releases/RELEASE_NOTES_2.18.13.md',
    'docs/releases/RELEASE_NOTES_2.18.14.md',
    'docs/releases/RELEASE_NOTES_2.18.15.md',
    'docs/releases/RELEASE_NOTES_2.18.17.md',
    'docs/releases/RELEASE_NOTES_2.18.18.md',
    'docs/releases/RELEASE_NOTES_2.18.19.md',
    'docs/releases/RELEASE_NOTES_2.18.20.md',
    'docs/releases/RELEASE_NOTES_2.18.21.md',
    'docs/releases/RELEASE_NOTES_2.18.22.md',
    'docs/releases/RELEASE_NOTES_2.18.23.md',
    'docs/releases/RELEASE_NOTES_2.18.24.md',
    'docs/releases/RELEASE_NOTES_2.18.27.md',
    'docs/releases/RELEASE_NOTES_2.18.28.md',
    'docs/releases/RELEASE_NOTES_2.18.29.md',
    'docs/releases/RELEASE_NOTES_2.18.30.md',
    'docs/releases/RELEASE_NOTES_2.18.31.md',
    'docs/releases/RELEASE_NOTES_2.18.32.md',
    'docs/releases/RELEASE_NOTES_2.18.33.md',
    'docs/releases/RELEASE_NOTES_2.18.34.md',
    'docs/releases/RELEASE_NOTES_2.18.35.md',
    'docs/releases/RELEASE_NOTES_2.18.36.md',
    'docs/releases/RELEASE_NOTES_2.18.37.md',
    'docs/releases/RELEASE_NOTES_2.18.38.md',
    'docs/releases/RELEASE_NOTES_2.18.38_RC13.md',
    'docs/SECURITY_2.18.39.md',
    'docs/SECURITY_2.18.39_FOLLOWUP.md',
    'docs/releases/RELEASE_NOTES_2.18.39.md',
)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output", type=Path)
    options = parser.parse_args()
    version = (ROOT / "VERSION").read_text().strip()
    descriptor = ElementTree.parse(ROOT / "uxconnector.xml")
    assert descriptor.findtext("./versions/version/num") == version
    assert json.loads((ROOT / "marketplace/metadata.json").read_text())["version"] == version
    filename = f"uxconnector-{version}.tar.gz"
    assert descriptor.findtext("./versions/version/download_url").endswith(f"/v{version}/{filename}")
    files = {ROOT / name for name in (*FILES, *DOCUMENTS)}
    for directory in RUNTIME:
        files.update(p for p in (ROOT / directory).rglob("*") if p.is_file())
    epoch = int(os.environ.get("SOURCE_DATE_EPOCH") or subprocess.check_output(
        ["git", "log", "-1", "--format=%ct"], cwd=ROOT, text=True).strip())
    options.output.mkdir(parents=True, exist_ok=True)
    target = options.output / filename
    with target.open("wb") as stream, gzip.GzipFile(filename="", mode="wb", fileobj=stream, mtime=epoch) as compressed:
        with tarfile.open(fileobj=compressed, mode="w", format=tarfile.PAX_FORMAT) as archive:
            for path in sorted(files):
                if path.is_symlink() or not path.is_file():
                    raise ValueError(f"Not a regular release file: {path}")
                data = path.read_bytes()
                entry = tarfile.TarInfo("uxconnector/" + path.relative_to(ROOT).as_posix())
                entry.size, entry.mode, entry.mtime = len(data), 0o644, epoch
                archive.addfile(entry, io.BytesIO(data))
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix(target.suffix + ".sha256").write_text(f"{digest}  {filename}\n")
    print(f"{target}: {len(files)} files, SHA256 {digest}")


if __name__ == "__main__":
    main()
