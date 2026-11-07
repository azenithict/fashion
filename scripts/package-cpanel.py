#!/usr/bin/env python3
"""Build a source-free public site plus private PHP runtime. Run after npm run build."""
import argparse, hashlib, json, pathlib, subprocess, tarfile, tempfile, shutil
p=argparse.ArgumentParser();p.add_argument('--allow-dirty',action='store_true');a=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[1]
commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
dirty=bool(subprocess.check_output(['git','status','--porcelain','--untracked-files=normal'],cwd=root,text=True).strip())
if dirty and not a.allow_dirty: raise SystemExit('Commit reviewed changes before creating a release package; use --allow-dirty only for local tests.')
if not (root/'out/index.html').is_file(): raise SystemExit('Run npm run build first.')
dest=root/'dist';dest.mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='zenith-release-') as tmp:
    stage=pathlib.Path(tmp)
    shutil.copytree(root/'out',stage/'public')
    (stage/'public/api/v1').mkdir(parents=True)
    for name in ['index.php','.htaccess']:shutil.copy2(root/'backend/public'/name,stage/'public/api/v1'/name)
    shutil.copy2(root/'scripts/public.htaccess',stage/'public/.htaccess')
    for name in ['src','migrations','bin']:shutil.copytree(root/'backend'/name,stage/'private/backend'/name)
    shutil.copy2(root/'backend/config.example.php',stage/'private/backend/config.example.php')
    shutil.copytree(root/'shared',stage/'private/shared')
    for name in ['DEPLOY_CPANEL.md','PRODUCTION_READINESS.md']:
        if (root/'docs'/name).is_file():
            (stage/'docs').mkdir(exist_ok=True);shutil.copy2(root/'docs'/name,stage/'docs'/name)
    checks={str(f.relative_to(stage)):hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(stage.rglob('*')) if f.is_file()}
    (stage/'release-manifest.json').write_text(json.dumps({'commit':commit,'dirty':dirty,'architecture':'static-next-php-mariadb','files':checks},indent=2)+'\n')
    name=dest/f'zenith-{commit[:12]}{"-dirty" if dirty else ""}.tar.gz'
    with tarfile.open(name,'w:gz') as tar:
        for item in stage.iterdir():tar.add(item,arcname=item.name,recursive=True)
    print(name)
