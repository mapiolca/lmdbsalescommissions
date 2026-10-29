"""Analyse the workflow production files against real Dolibarr/optional module sources.

No ERP is booted and no PHPStan baseline or error exclusion is generated.
"""
from pathlib import Path
import argparse
import json
import os
import subprocess

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('core', type=Path, help='Complete Dolibarr htdocs source directory')
parser.add_argument('--version', required=True, help='Version of these native sources')
parser.add_argument('--zoning', required=True, type=Path, help='lmdbzoning source directory')
parser.add_argument('--propalpv', required=True, type=Path, help='lmdbpropalpv source directory')
parser.add_argument('--phpstan', type=Path, default=root / 'test/.tools/phpstan/vendor/phpstan/phpstan/phpstan.phar')
args = parser.parse_args()
core = args.core.resolve()
dependencies = [args.zoning.resolve() / 'class/lmdbzoningtravelservice.class.php',
                args.propalpv.resolve() / 'class/lmdbpropalpvcomplexsiteservice.class.php']
for path in [core / 'main.inc.php', core / 'api/class/api_access.class.php', args.phpstan, *dependencies]:
    if not path.is_file():
        parser.error(f'Required source/tool missing: {path}')

files = [
    'class/lmdbsalescommissionruleresolver.class.php',
    'class/actions_lmdbsalescommissions.class.php',
    'class/lmdbsalescommissionlineservice.class.php',
    'class/lmdbsalescommissionmargincase.class.php',
    'class/lmdbsalescommissionmarginguard.class.php',
    'class/lmdbsalescommissionmarginservice.class.php',
    'class/lmdbsalescommissionmarginview.class.php',
    'class/lmdbsalescommissionmarginworkflow.class.php',
    'class/lmdbsalescommissionmarginworkflowview.class.php',
    'class/lmdbsalescommissionpolicytooltip.class.php',
    'class/lmdbsalescommissionproposaldispatchservice.class.php',
    'class/lmdbsalescommissionproposalturnoverdispatchservice.class.php',
    'class/lmdbsalescommissionscompatibility.class.php',
    'class/lmdbsalescommissionworkflownotification.class.php',
    'core/modules/modLmdbSalesCommissions.class.php',
    'core/substitutions/functions_lmdbsalescommissions.lib.php',
    'core/triggers/interface_99_modLmdbSalesCommissions_LmdbSalesCommissionsTriggers.class.php',
    'proposal_dispatch.php', 'proposal_margin_request.php', 'proposal_margin_workflow.php',
]
scratch = root / 'test/.tools'
scratch.mkdir(exist_ok=True)

def quoted(path):
    return json.dumps(str(path).replace('\\', '/'))

config = ['parameters:', '    level: 5', '    phpVersion: 80000',
          '    tmpDir: ' + quoted(scratch / 'phpstan-workflow-cache'),
          '    bootstrapFiles:', '        - ' + quoted(root / 'test/phpstan_bootstrap.php'),
          '    scanDirectories:']
config += ['        - ' + quoted(p) for p in [core, root / 'class', root / 'lib', root / 'core/substitutions']]
config += ['    scanFiles:'] + ['        - ' + quoted(p) for p in dependencies]
config += ['    excludePaths:', '        analyseAndScan:']
config += ['            - ' + quoted(core / p) for p in ['install', 'modulebuilder/template']]
config += ['    paths:'] + ['        - ' + quoted(root / p) for p in files]
config_file = scratch / 'phpstan-workflow.neon'
config_file.write_text('\n'.join(config) + '\n', encoding='utf-8')
env = dict(os.environ, LSC_PHPSTAN_CORE=str(core), LSC_PHPSTAN_VERSION=args.version)
print(f'PHPStan level 5, PHP 8.0 target; {len(files)} production files; native sources {args.version}', flush=True)
raise SystemExit(subprocess.run(['php', str(args.phpstan), 'analyse', '-c', str(config_file),
                                '--memory-limit=1G', '--no-progress'], cwd=root, env=env).returncode)
