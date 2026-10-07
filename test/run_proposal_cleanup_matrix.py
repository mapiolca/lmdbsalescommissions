"""Fetch immutable native sources into a module-local cache and run simulated regressions."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import hashlib
import json
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parent
REVISIONS = {
    "20.0.0": "697bf01970740a3339cd99cf055b4428fc5e051c",
    "21.0.0": "fd970b582a4d8c5779a2958a4e9f4fce225cf085",
    "22.0.0": "49b9a6d19f3deb6d410c0e9b3310e95be4ea7710",
    "23.0.0": "57a1f05d490a7a80944a8232e9c613e6556d2704",
    "24.0.0": "769c7db907099643558e77d7002c109cfda919e5",
    "25.0.0-alpha": "ef6e5818b0a5626f928cb9bc66bddb8be43ac32f",
}
FILES = [
    "htdocs/comm/propal/class/propal.class.php",
    "htdocs/core/class/commonobject.class.php",
    "htdocs/core/class/doldeprecationhandler.class.php",
    "htdocs/core/class/html.form.class.php",
    "htdocs/core/class/hookmanager.class.php",
    "htdocs/core/db/DoliDB.class.php",
]

def fetch(item):
    version, revision, name = item
    url = f"https://raw.githubusercontent.com/Dolibarr/dolibarr/{revision}/{name}"
    with urllib.request.urlopen(url, timeout=30) as response:
        data = response.read()
    destination = ROOT / ".cleanup-core" / version / name
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(data)
    return {"version": version, "revision": revision, "path": name, "sha256": hashlib.sha256(data).hexdigest()}

if __name__ == "__main__":
    jobs = [(v, r, name) for v, r in REVISIONS.items() for name in FILES]
    jobs.extend((v, REVISIONS[v], "htdocs/core/class/commontrigger.class.php") for v in ("23.0.0", "24.0.0", "25.0.0-alpha"))
    with ThreadPoolExecutor(max_workers=6) as pool:
        manifest = list(pool.map(fetch, jobs))
    (ROOT / ".cleanup-core" / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    for version, revision in REVISIONS.items():
        native = ROOT / ".cleanup-core" / version / "htdocs"
        print(f"{version} {revision}", flush=True)
        subprocess.run(["php", str(ROOT / "proposal_cleanup_test.php"), str(native), str(native / "core/db/DoliDB.class.php")], check=True, cwd=ROOT.parent)
