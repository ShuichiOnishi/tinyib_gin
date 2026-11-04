#!/usr/bin/env python3
"""Build an overwrite-only archive without configuration or live board data."""
from pathlib import Path
import hashlib
import zipfile

root = Path(__file__).resolve().parents[1]
output = root / 'dist' / 'tinyib-php85-update.zip'
files = [root / name for name in ('imgboard.php', 'settings.default.php', 'LICENSE',
    'favicon.ico', 'lock.png', 'sticky.png', 'swf_thumbnail.png', 'video_overlay.png')]
for directory, suffixes in {'inc': {'.php', '.ttf'}, 'locale': {'.po'},
                            'css': {'.css'}, 'js': {'.js'}}.items():
    files.extend(p for p in (root / directory).rglob('*') if p.is_file() and
                 (p.suffix in suffixes or p.name == 'LICENSE'))
files.append(root / 'docs' / 'UPDATE-PHP85.md')
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(files):
        if path.is_symlink():
            raise ValueError(f'Symlink is not a release source: {path}')
        archive.write(path, path.relative_to(root).as_posix())
with zipfile.ZipFile(output) as archive:
    names = archive.namelist()
    assert len(names) == len(set(names))
    assert 'inc/gettext.php' in names and 'inc/gettext/src/Translator.php' in names
    assert 'inc/dateformat.php' in names
    assert all(not n.startswith(('src/', 'thumb/', 'res/', '.git/', 'tests/')) for n in names)
    assert not any(Path(n).name.startswith('.') or Path(n).name == 'settings.php' for n in names)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(f'{digest}  {output.name}\n')
print(f'{output}: {len(names)} files, SHA256 {digest}')
