<?php
declare(strict_types=1);

// Explicit inputs keep linked accounts, local runtimes and reference checkouts out of releases.
$root=dirname(__DIR__);
if (!class_exists(ZipArchive::class)) throw new RuntimeException('Packaging requires ext-zip');
$files=[];
foreach (['src','resources','examples','tests','tools','dev','docs','native','.vscode'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
        if (str_starts_with($relative,'dev/channels/')) continue;
        if (preg_match('~(?:^|/)(?:\.auth(?:-backups)?|\.cache|\.dev|vendor|node_modules|__pycache__)(?:/|$)|\.(?:sqlite(?:-wal|-shm)?|log|pyc|zip|apk|dll|so|dylib|pem|key|p12|pfx)$~i',$relative)) continue;
        if (in_array($relative,['docs/FLOW-PROTOCOL-EVIDENCE.json','.vscode/settings.json'],true)) continue;
        $files[$relative]=$file->getPathname();
    }
}
foreach (['autoload.php','composer.json','composer.lock','LICENSE','THIRD_PARTY_NOTICES.md','README.md','CONTRIBUTING.md','CODE_OF_CONDUCT.md','SECURITY.md','CHANGELOG.md','AGENTS.md','llms.txt','llms-full.txt','phpunit.xml.dist','phpstan.neon.dist','.editorconfig','.gitignore','.gitattributes','mvo-php.code-workspace'] as $file) if(is_file($root.'/'.$file))$files[$file]=$root.'/'.$file;
ksort($files,SORT_STRING);
$destination=$root.'/mvo-php.zip';
$zip=new ZipArchive(); $status=$zip->open($destination,ZipArchive::CREATE|ZipArchive::OVERWRITE);
if ($status!==true) throw new RuntimeException('Cannot create package: '.$status);
foreach ($files as $relative=>$path) if (!$zip->addFile($path,$relative)) throw new RuntimeException('Cannot add package file: '.$relative);
if (!$zip->close()) throw new RuntimeException('Cannot finalize package');
$check=new ZipArchive();
if ($check->open($destination,ZipArchive::CHECKCONS)!==true || $check->numFiles!==count($files)) throw new RuntimeException('Package verification failed');
foreach (array_keys($files) as $relative) if ($check->locateName($relative)===false) throw new RuntimeException('Package file missing: '.$relative);
$check->close();
echo json_encode(['package'=>'mvo-php.zip','files'=>count($files),'bytes'=>filesize($destination),'sha256'=>hash_file('sha256',$destination)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
