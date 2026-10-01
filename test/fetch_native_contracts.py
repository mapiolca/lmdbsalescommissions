"""Fetch immutable official sources into the ignored, module-local test cache."""
import argparse
import concurrent.futures
from pathlib import Path
import urllib.error
import urllib.request

REVISIONS = {
    "20.0.0": "697bf01970740a3339cd99cf055b4428fc5e051c",
    "21.0.0": "fd970b582a4d8c5779a2958a4e9f4fce225cf085",
    "22.0.0": "49b9a6d19f3deb6d410c0e9b3310e95be4ea7710",
    "23.0.0": "57a1f05d490a7a80944a8232e9c613e6556d2704",
    "24.0.0": "769c7db907099643558e77d7002c109cfda919e5",
    "25.0.0-alpha": "ef6e5818b0a5626f928cb9bc66bddb8be43ac32f",
}
FILES = [
    "core/lib/functions.lib.php", "core/lib/ajax.lib.php",
    "core/ajax/onlineSign.php", "core/class/hookmanager.class.php",
    "core/class/html.formmargin.class.php", "core/class/html.form.class.php", "core/class/commonobject.class.php",
    "comm/propal/card.php", "comm/propal/list.php", "comm/propal/class/propal.class.php",
    "comm/propal/class/api_proposals.class.php", "api/index.php",
] + ["includes/restler/framework/Luracast/Restler/" + name + ".php"
     for name in ("Restler", "EventDispatcher", "Scope", "Defaults", "RestException")]
OPTIONAL = ["core/class/doldeprecationhandler.class.php", "core/class/commontrigger.class.php", "core/lib/html.lib.php", "comm/propal/class/propaleligne.class.php"]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version", choices=REVISIONS)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent / ".core-cache" / args.version / "htdocs"

    def fetch(name):
        url = "https://raw.githubusercontent.com/Dolibarr/dolibarr/" + REVISIONS[args.version] + "/htdocs/" + name
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                data = response.read()
        except urllib.error.HTTPError as error:
            if error.code == 404 and name in OPTIONAL:
                return
            raise
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)

    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        list(pool.map(fetch, FILES + OPTIONAL))
    print(args.version, REVISIONS[args.version], root)


if __name__ == "__main__":
    main()
