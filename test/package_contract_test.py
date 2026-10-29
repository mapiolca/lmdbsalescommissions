"""Check a candidate archive and execute entry-point bootstraps; no ERP is installed."""
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

root = Path(__file__).resolve().parent.parent
core = Path(sys.argv[1]).resolve()
descriptor = (root / 'core/modules/modLmdbSalesCommissions.class.php').read_text(encoding='utf-8')
version = re.search(r"\$this->version = '([0-9.]+)'", descriptor).group(1)
name = 'module_lmdbsalescommissions-' + version + '.zip'
sources = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
files = [p for p in sources if p and not p.startswith(('.', 'test/'))]
out = root / 'test/.tools/candidate'
out.mkdir(parents=True, exist_ok=True)
archive = out / name
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
    for path in files:
        package.write(root / path, 'lmdbsalescommissions/' + path)
with zipfile.ZipFile(archive) as package:
    assert package.testzip() is None
    assert all(n.startswith('lmdbsalescommissions/') and '/test/' not in n and '/.git' not in n for n in package.namelist())
    assert 'lmdbsalescommissions/modulebuilder.txt' in package.namelist()
    assert 'lmdbsalescommissions/ChangeLog.md' in package.namelist()
    assert "'" + version + "'" in package.read('lmdbsalescommissions/core/modules/modLmdbSalesCommissions.class.php').decode()
    entries = {}
    for path in files:
        if not path.endswith('.php'):
            continue
        text = package.read('lmdbsalescommissions/' + path).decode('utf-8')
        loads = set(re.findall(r"(?:include|require)(?:_once)?\s*\(?\s*['\"]([^'\"]*(?:main|master)\.inc\.php)['\"]", text))
        loads.update(re.findall(r"(?:include|require)(?:_once)?\s+(dirname\(__DIR__(?:,\s*2)?\)\s*\.\s*['\"]/(?:main|master)\.inc\.php['\"])", text))
        if loads:
            assert len(loads) >= 2, (path, loads)
            entries[path] = text

# Execute the current core's actual PHP name expression, including a rejected dev suffix.
installer = (core / 'admin/modules.php').read_text(encoding='utf-8')
expressions = re.findall(r"preg_match\(('(?:\\.|[^'\\])*'),\s*\$original_file\)", installer)
pattern = next(p for p in expressions if 'module' in p and 'zip' in p)
php = '$original_file=$argv[1];exit(preg_match(' + pattern + ',$original_file)===1?0:1);'
assert subprocess.run(['php', '-r', php, '--', name], capture_output=True).returncode == 0
assert subprocess.run(['php', '-r', php, '--', name.replace('.zip', '-dev.zip')], capture_output=True).returncode == 1

count = 0
scratch = root / 'test/.tools'
with tempfile.TemporaryDirectory(prefix='bootstrap-', dir=scratch) as temporary:
    folder = Path(temporary).resolve()
    assert folder.is_relative_to(scratch.resolve())
    for layout in ('direct', 'custom', 'absent'):
        htdocs = folder / layout / 'htdocs'
        module = htdocs / ('custom/lmdbsalescommissions' if layout == 'custom' else 'lmdbsalescommissions')
        module.mkdir(parents=True)
        if layout != 'absent':
            for include in ('main.inc.php', 'master.inc.php'):
                (htdocs / include).write_text("<?php echo 'NATIVE_BOOTSTRAP_FOUND'; exit;", encoding='utf-8')
        for path, text in entries.items():
            target = module / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(text, encoding='utf-8')
            result = subprocess.run(['php', target.name], cwd=target.parent, capture_output=True, text=True)
            assert result.returncode == 0 and not result.stderr, (layout, path, result.stderr)
            expected = 'Include of main fails' if layout == 'absent' else 'NATIVE_BOOTSTRAP_FOUND'
            assert result.stdout == expected, (layout, path, result.stdout)
            count += 1
            if 'dirname(__DIR__' in text:
                result = subprocess.run(['php', str(target)], cwd=folder, capture_output=True, text=True)
                assert result.returncode == 0 and not result.stderr and result.stdout == expected, (layout, path, 'foreign cwd', result.stderr)
                count += 1
print(f'Candidate archive: {len(files)} files; native filename checks; {count} bootstrap paths passed ({len(entries)} entry points).')
print('This does not prove native module installation, complete ERP execution or Dolistore acceptance.')
