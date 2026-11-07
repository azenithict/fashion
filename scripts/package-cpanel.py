#!/usr/bin/env python3
"""Build a source-free public site plus private PHP runtime. Run after npm run build."""
import argparse, hashlib, json, pathlib, subprocess, tarfile, tempfile, shutil, os, re
p=argparse.ArgumentParser();p.add_argument('--allow-dirty',action='store_true');p.add_argument('--variant',choices=['production','preview'],default='production');a=p.parse_args()
base_path=os.environ.get('NEXT_PUBLIC_BASE_PATH','')
if a.variant=='preview' and not re.fullmatch(r'/[A-Za-z0-9_-]+',base_path): raise SystemExit('Preview requires a validated NEXT_PUBLIC_BASE_PATH.')
if a.variant=='production' and base_path: raise SystemExit('Production package must have an empty base path.')
root=pathlib.Path(__file__).resolve().parents[1]
commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
dirty=bool(subprocess.check_output(['git','status','--porcelain','--untracked-files=normal'],cwd=root,text=True).strip())
if dirty and not a.allow_dirty: raise SystemExit('Commit reviewed changes before creating a release package; use --allow-dirty only for local tests.')
if not (root/'out/index.html').is_file(): raise SystemExit('Run npm run build first.')
if not re.search(r'(?:src|href)="'+re.escape(base_path)+r'/_next/',(root/'out/index.html').read_text()): raise SystemExit('Build does not match the selected base path; rebuild first.')
dest=root/'dist';dest.mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='zenith-release-') as tmp:
    stage=pathlib.Path(tmp)
    shutil.copytree(root/'out',stage/'public')
    (stage/'public/api/v1').mkdir(parents=True)
    for name in ['index.php','.htaccess']:shutil.copy2(root/'backend/public'/name,stage/'public/api/v1'/name)
    shutil.copy2(root/'scripts/public.htaccess',stage/'public/.htaccess')
    for name in ['src','migrations','bin','public']:shutil.copytree(root/'backend'/name,stage/'private/backend'/name)
    shutil.copy2(root/'backend/config.example.php',stage/'private/backend/config.example.php')
    shutil.copytree(root/'shared',stage/'private/shared')
    for name in ['DEPLOY_CPANEL.md','PRODUCTION_READINESS.md']:
        if (root/'docs'/name).is_file():
            (stage/'docs').mkdir(exist_ok=True);shutil.copy2(root/'docs'/name,stage/'docs'/name)
    checks={str(f.relative_to(stage)):hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(stage.rglob('*')) if f.is_file()}
    (stage/'release-manifest.json').write_text(json.dumps({'commit':commit,'dirty':dirty,'architecture':'static-next-php-mariadb','variant':a.variant,'base_path':base_path,'files':checks},indent=2)+'\n')
    name=dest/f'zenith-{commit[:12]}{"-preview" if a.variant=="preview" else ""}{"-dirty" if dirty else ""}.tar.gz'
    with tarfile.open(name,'w:gz') as tar:
        for item in stage.iterdir():tar.add(item,arcname=item.name,recursive=True)
    print(name)
