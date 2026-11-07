#!/usr/bin/env python3
"""Exercise actual package staging and ensure untrusted verifier PHP is never executed."""
import hashlib, io, json, pathlib, subprocess, sys, tarfile, tempfile, zipfile
root=pathlib.Path(__file__).resolve().parents[1]
archive=pathlib.Path(sys.argv[1]).resolve()
with tempfile.TemporaryDirectory(prefix='zenith-stage-test-') as tmp:
    tmp=pathlib.Path(tmp);private=tmp/'fashion-private';outer=tmp/'release.zip'
    with zipfile.ZipFile(outer,'w',zipfile.ZIP_DEFLATED) as z:z.write(archive,archive.name)
    digest=hashlib.sha256(outer.read_bytes()).hexdigest()
    run=lambda path,sha:subprocess.run(['bash',str(root/'scripts/stage-cpanel-release.sh'),str(path),str(private),sha],capture_output=True,text=True)
    result=run(outer,digest)
    assert result.returncode==0,result.stderr
    with tarfile.open(archive) as tar:manifest=json.load(tar.extractfile('release-manifest.json'))
    namespace='preview-releases' if manifest.get('variant')=='preview' else 'releases'
    staged=private/namespace/manifest['commit'];assert (staged/'release-manifest.json').is_file()
    assert not (private/'current').exists()
    assert run(outer,'0'*64).returncode!=0,'Checksum mismatch must fail closed'
    canary=tmp/'untrusted-code-executed';bad_tar=tmp/archive.name
    with tarfile.open(archive) as original,tarfile.open(bad_tar,'w:gz') as changed:
        for entry in original:
            if entry.isfile():
                data=original.extractfile(entry).read()
                if entry.name=='private/backend/bin/verify-release.php':data=('<?php file_put_contents('+json.dumps(str(canary))+',"BAD");').encode();entry.size=len(data)
                changed.addfile(entry,io.BytesIO(data))
            else:changed.addfile(entry)
    malicious=tmp/'tampered.zip'
    with zipfile.ZipFile(malicious,'w') as z:z.write(bad_tar,bad_tar.name)
    result=run(malicious,hashlib.sha256(malicious.read_bytes()).hexdigest())
    assert result.returncode!=0 and not canary.exists(),'Package verifier must be authenticated before execution'
    assert not list((private/'releases').glob('.stage-*')),'Rejected staging scratch must be cleaned'
    print('PASS '+namespace+' artifact digest, private staging, no activation, mismatch rejection and verifier-execution protection.')
