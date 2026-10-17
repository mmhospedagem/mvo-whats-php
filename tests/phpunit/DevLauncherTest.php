<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises only an isolated launcher copy and a fake PHPUnit executable. */
final class DevLauncherTest extends TestCase
{
    private string $directory;
    private string $ini;

    protected function setUp():void {
        $this->directory=sys_get_temp_dir().'/mvo-dev-launcher-'.bin2hex(random_bytes(8)).' with spaces';
        mkdir($this->directory.'/tools',0700,true);
        mkdir($this->directory.'/vendor/bin',0700,true);
        mkdir($this->directory.'/empty ini scan',0700,true);
        copy(dirname(__DIR__,2).'/tools/dev.php',$this->directory.'/tools/dev.php');
        $this->ini=$this->directory.'/selected config.ini';
        file_put_contents($this->ini,"memory_limit=73M\ndisplay_errors=stderr\n");
        file_put_contents($this->directory.'/vendor/bin/phpunit', <<<'PHP'
<?php
echo json_encode([
    'argv'=>$argv,
    'binary'=>PHP_BINARY,
    'ini'=>php_ini_loaded_file(),
    'scannedIni'=>php_ini_scanned_files(),
    'memoryLimit'=>ini_get('memory_limit'),
    'cwd'=>getcwd(),
    'marker'=>getenv('MVO_DEV_LAUNCHER_TEST_VALUE'),
    'xdebugMode'=>getenv('XDEBUG_MODE'),
],JSON_THROW_ON_ERROR),PHP_EOL;
exit((int)(getenv('MVO_DEV_LAUNCHER_TEST_EXIT') ?: '0'));
PHP);
    }

    protected function tearDown():void {
        $directory=realpath($this->directory);$temporaryRoot=realpath(sys_get_temp_dir());
        if($directory===false || $temporaryRoot===false || dirname($directory)!==$temporaryRoot || !str_starts_with(basename($directory),'mvo-dev-launcher-'))throw new \LogicException('Unexpected launcher test directory');
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($directory);
    }

    /** @return iterable<string,array{string,list<string>,list<string>,string,int}> */
    public static function commands():iterable {
        yield 'test with Composer separator'=>['test',['--','--filter','case with spaces','--literal=quotes "kept" & (literal) $value'],
            ['--filter','case with spaces','--literal=quotes "kept" & (literal) $value'],'off',0];
        yield 'test direct arguments and exit status'=>['test',['--filter=another case','path with spaces/Test.php'],
            ['--filter=another case','path with spaces/Test.php'],'off',7];
        yield 'coverage keeps its mode and arguments'=>['coverage',['--','--filter','coverage case'],
            ['--coverage-html','build/coverage','--coverage-clover','build/clover.xml','--filter','coverage case'],'coverage',0];
    }

    /** @param list<string> $arguments
     * @param list<string> $expectedArguments */
    #[DataProvider('commands')]
    public function testFreshCloneUsesCurrentPhpAndIniWithoutLocalConfiguration(string $command,array $arguments,array $expectedArguments,string $mode,int $expectedExit):void {
        self::assertFileDoesNotExist($this->directory.'/dev.local.json');
        $marker='environment value with spaces; "quotes" & (literal) $value';
        $environment=array_merge(getenv(),[
            // An empty variable can retain the compiled-in scan directory on Linux.
            // Use a real empty directory so runner-wide conf.d files cannot override this fixture.
            'PHP_INI_SCAN_DIR'=>$this->directory.'/empty ini scan',
            'MVO_DEV_LAUNCHER_TEST_VALUE'=>$marker,
            'MVO_DEV_LAUNCHER_TEST_EXIT'=>(string)$expectedExit,
            'XDEBUG_MODE'=>'debug,develop',
        ]);
        $process=proc_open([PHP_BINARY,'-c',$this->ini,$this->directory.'/tools/dev.php',$command,...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,sys_get_temp_dir(),$environment,
            ['bypass_shell'=>true,'create_no_window'=>true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        self::assertSame($expectedExit,$exit,$stderr);self::assertSame('',$stderr);
        self::assertIsString($stdout);$actual=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
        self::assertSame(PHP_BINARY,$actual['binary']);
        self::assertSame(realpath($this->ini),realpath($actual['ini']));
        self::assertEmpty($actual['scannedIni']);
        self::assertSame('73M',$actual['memoryLimit']);
        self::assertSame(realpath($this->directory),realpath($actual['cwd']));
        self::assertSame(['vendor/bin/phpunit',...$expectedArguments],$actual['argv']);
        self::assertSame($marker,$actual['marker']);self::assertSame($mode,$actual['xdebugMode']);
        self::assertFileDoesNotExist($this->directory.'/dev.local.json');
    }
}
