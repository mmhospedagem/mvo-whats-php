<?php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$localPath = $root . '/dev.local.json';
// A fresh clone uses the PHP CLI already selected by the caller on any OS.
// The optional Windows development setup retains its isolated interpreter/ini.
$local = is_file($localPath)
    ? json_decode(file_get_contents($localPath),true,512,JSON_THROW_ON_ERROR)
    : ['php'=>PHP_BINARY,'ini'=>php_ini_loaded_file() ?: null];
$command = $argv[1] ?? 'help';
if ($command === 'connect-qr' && PHP_OS_FAMILY === 'Windows' && stream_isatty(STDOUT)) {
    // The child streams through a pipe; enable the real terminal at the launcher boundary.
    if (function_exists('sapi_windows_cp_set')) sapi_windows_cp_set(65001);
    if (function_exists('sapi_windows_vt100_support')) sapi_windows_vt100_support(STDOUT,true);
    if (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(static function(int $event):void {
            if ($event===PHP_WINDOWS_EVENT_CTRL_C || $event===PHP_WINDOWS_EVENT_CTRL_BREAK) {
                fwrite(STDOUT,"\033[0m\033[?25h\033[?1049l");
            }
        });
    }
}
$extra = array_slice($argv,2);
if ($extra !== [] && $extra[0] === '--') array_shift($extra);
$run = static function (array $args, string $mode = 'off', array $env = []) use ($root,$local): int {
    $process = proc_open(
        [$local['php'],...(is_string($local['ini']??null)&&$local['ini']!=='' ? ['-c',$local['ini']] : []),...$args],
        [0=>STDIN,1=>['pipe','w'],2=>['redirect',1]],$pipes,$root,
        array_merge(getenv(),['XDEBUG_MODE'=>$mode],$env)
    );
    if (!is_resource($process)) throw new RuntimeException('Nao foi possivel iniciar PHP.');
    stream_copy_to_stream($pipes[1],STDOUT);
    fclose($pipes[1]);
    return proc_close($process);
};
$tasks = [
    'doctor'=>fn()=> $run(['tools/doctor.php'],'develop,debug'),
    'voip-doctor'=>fn()=> $run(['tools/voip-doctor.php']),
    'test'=>fn()=> $run(['vendor/bin/phpunit',...$extra]),
    'legacy'=>fn()=> $run(['tests/run.php']),
    'analyse'=>fn()=> $run(['vendor/bin/phpstan','analyse','--configuration=phpstan.neon.dist','--no-progress','--memory-limit=512M',...$extra]),
    'coverage'=>fn()=> $run(['vendor/bin/phpunit','--coverage-html','build/coverage','--coverage-clover','build/clover.xml',...$extra],'coverage'),
    'example'=>fn()=> $run(['examples/protocol.php',...$extra]),
    'debug'=>fn()=> $run(['examples/debug.php',...$extra],'debug,develop',['XDEBUG_TRIGGER'=>'mvo']),
    'debug-verify'=>fn()=> $run(['tools/verify-debugger.php']),
    'connect-qr'=>fn()=> $run(['examples/connect.php','--mode=qr',...$extra]),
    'connect-code'=>fn()=> $run(['examples/connect.php','--mode=code',...$extra]),
    'live-test'=>fn()=> $run(['tests/integration/whatsapp.php'], 'off', ['MVO_LIVE_TEST'=>'1']),
    'session-status'=>fn()=> $run(['tools/session-status.php',...$extra]),
    'message-send'=>fn()=> $run(['examples/send.php',...$extra]),
    'resume-test'=>fn()=> $run(['tests/integration/resume.php',...$extra]),
    'session-relink'=>fn()=> $run(['tools/session-relink.php',...$extra]),
    'package'=>fn()=> $run(['tools/package.php']),
    'lint'=>static function () use ($root,$run): int {
        $paths = [$root.'/autoload.php'];
        foreach (['src','tests','examples','tools'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        foreach ($paths as $file) { $code = $run(['-l',$file]); if ($code !== 0) return $code; }
        return 0;
    }
];
if ($command === 'check') {
    foreach (['doctor','lint','legacy','test','analyse'] as $task) {
        echo PHP_EOL . '=== ' . $task . ' ===' . PHP_EOL;
        $exit = $tasks[$task]();
        if ($exit !== 0) exit($exit);
    }
    exit(0);
}
if (!isset($tasks[$command])) {
    echo 'Tarefas: '.implode(', ',array_keys($tasks)).', check'.PHP_EOL;
    exit($command === 'help' ? 0 : 1);
}
exit($tasks[$command]());
