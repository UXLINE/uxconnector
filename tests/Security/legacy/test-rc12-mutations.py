"""Negative controls: selected tests must detect deliberately removed security barriers.
Each mutation exists only in a disposable copy; the release tree is never modified.
"""
from pathlib import Path
import shutil,subprocess,sys,tempfile
php,source=sys.argv[1:3];source=Path(source);tests=Path(__file__).resolve().parent
cases=[
('followup-entity-author','src/Infrastructure/Sync/Writer/GlpiItemWriter.php',
 '$data = (new GlpiFollowupReferencePolicy($this->db))->validate($data, $context->entitiesId, $linkedAuthor);',
 '$data = $data;', 'test-rc12-writer.php'),
('attachment-host','src/Infrastructure/Zendesk/ZendeskAttachmentHostPolicy.php',
 '        $parts = parse_url($url);','        return true;\n        $parts = parse_url($url);','test-rc12-network.php'),
('migration-entity','src/Infrastructure/GlpiRestV1/MigrationEntityScope.php',
 '        if ($entityId === null) {','        return $criteria;\n        if ($entityId === null) {','test-rc12-writer.php'),
('redacted-snapshot','src/Application/Sync/Service/SourceSnapshotSanitizer.php',
 '        foreach ($record as $value) {','        return true;\n        foreach ($record as $value) {','test-rc12-retry.php'),
('dns-pin','src/Infrastructure/Engine/Http/Security/PinnedHttpDestination.php',
 '        $parts = parse_url($this->url);','        return [$this->url];\n        $parts = parse_url($this->url);','test-rc12-https.py'),
]
for label,file,before,after,test in cases:
    with tempfile.TemporaryDirectory(prefix='uxc-mutation-') as temporary:
        root=Path(temporary)/'uxconnector';shutil.copytree(source,root,ignore=shutil.ignore_patterns('vendor','.git','.phpunit.cache'))
        path=root/file;text=path.read_text();assert text.count(before)==1,(label,'mutation anchor mismatch')
        path.write_text(text.replace(before,after,1))
        command=([sys.executable,str(tests/test),php,str(root)] if test.endswith('.py')
                 else [php,str(tests/test),str(root)])
        result=subprocess.run(command,capture_output=True,text=True,timeout=60)
        if result.returncode==0:raise RuntimeError('Test failed to detect removed barrier: '+label)
        print('OK negative control detected: '+label)
