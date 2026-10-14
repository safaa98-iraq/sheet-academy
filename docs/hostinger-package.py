#!/usr/bin/env python3
"""Build a split Hostinger archive; Laravel stays outside public_html."""
import argparse
import pathlib
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True)
args = parser.parse_args()
for required in ('vendor/autoload.php', 'public/build/manifest.json'):
    if not (ROOT / required).is_file():
        raise SystemExit(f'Missing {required}: run composer install --no-dev and npm ci && npm run build first.')
output = pathlib.Path(args.output).resolve()
output.parent.mkdir(parents=True, exist_ok=True)
allowed = ['app', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'lang', 'resources', 'routes', 'vendor', 'public']
files = [ROOT / name for name in ('artisan', 'composer.json', 'composer.lock')]
for directory in allowed:
    files.extend(path for path in (ROOT / directory).rglob('*') if path.is_file() and not path.is_symlink())
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(set(files)):
        relative = path.relative_to(ROOT)
        if relative.parts[:2] == ('bootstrap', 'cache'):
            continue
        if relative.parts[0] == 'public' and (relative.parts[1] in ('hot', 'storage') or path.suffix == '.php' and relative.name != 'index.php'):
            continue
        archive.write(path, f'sheet-academy/{relative}')
        if relative.parts[0] == 'public':
            name = pathlib.Path('public_html', *relative.parts[1:])
            if relative.name == 'index.php':
                content = path.read_text().replace("__DIR__.'/../", "__DIR__.'/../sheet-academy/")
                content = content.replace('$app->handleRequest', '$app->usePublicPath(__DIR__);\n\n$app->handleRequest')
                archive.writestr(str(name), content)
            else:
                archive.write(path, str(name))
    for directory in ['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']:
        archive.writestr(f'sheet-academy/{directory}/', '')
    archive.write(ROOT / 'docs/hostinger.env.example', 'sheet-academy/.env.example')
    archive.write(ROOT / 'docs/hostinger-finalize.sh', 'sheet-academy/hostinger-finalize.sh')
    archive.write(ROOT / 'docs/hostinger-cron.sh', 'sheet-academy/hostinger-cron.sh')
    archive.writestr('sheet-academy/.htaccess', 'Require all denied\n')
    archive.writestr('public_html/.user.ini', 'display_errors=Off\nlog_errors=On\nupload_max_filesize=100M\npost_max_size=110M\nmax_execution_time=120\nmemory_limit=256M\n')
print(output)
