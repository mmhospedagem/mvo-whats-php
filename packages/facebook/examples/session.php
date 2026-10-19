<?php
declare(strict_types=1);

use MVO\Facebook\FacebookConnection;
use MVO\Messenger\MessengerException;

ini_set('zend.exception_ignore_args','1');
ini_set('display_errors','0');
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$options=getopt('',['help','session:','auth-dir:','data-dir:','login','seconds:']);
if(isset($options['help'])){
    echo <<<'HELP'
MVO Facebook PHP — login, sessão persistente e restauração

Uso a partir do checkout:
  php packages/facebook/examples/session.php --session=demo --auth-dir=/privado/auth --data-dir=/privado/facebook --login
  php packages/facebook/examples/session.php --session=demo --auth-dir=/privado/auth --data-dir=/privado/facebook --seconds=30

--session   ID local da conta, obrigatório; use outro ID para outra conta Facebook.
--auth-dir  Pasta privada para autenticação, obrigatória e fora da raiz HTTP.
--data-dir  Pasta privada para serviços Facebook, obrigatória e fora da raiz HTTP.
--login     Primeiro login ou renovação explícita. Recebe MVO_FACEBOOK_EMAIL e
            MVO_FACEBOOK_PASSWORD do ambiente privado do processo, nunca da CLI.
            Não informe credenciais no chat ou nos argumentos deste programa.
--seconds   Tempo do loop conectado de 0 a 3600 segundos; padrão 30.
--help      Exibe ajuda sem carregar pacotes, criar arquivos ou conectar.

Sem --login, connect() restaura a sessão salva sem pedir e-mail/senha novamente.
stop() libera o lock e preserva a sessão; não há logout automático.
Não execute dois donos da mesma sessão. O exemplo não envia/publica mensagens.

2FA/CAPTCHA: o exemplo projeta o desafio e encerra a tentativa sem persistir um
login incompleto. Para continuar a verificação na aplicação, mantenha o mesmo
FacebookConnection vivo e use loginChallenge()/confirmLogin() ou a continuação
humana privada documentada; stop() e reinício cancelam o desafio pendente.

metadata_connected não significa Messenger E2EE pronto. Sessões podem expirar
ou ser revogadas pelo Facebook e exigir autenticação explícita novamente.
HELP;
    echo PHP_EOL;exit(0);
}

$connection=null;$status=0;
try{
    foreach(['session','auth-dir','data-dir'] as $name){
        if(!is_string($options[$name]??null)||trim($options[$name])==='')throw new InvalidArgumentException('Missing local configuration');
    }
    $seconds=$options['seconds']??'30';
    if(!is_string($seconds)||!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$seconds)||strlen($seconds)>12||(float)$seconds>3600)throw new InvalidArgumentException('Invalid loop duration');
    $package=dirname(__DIR__);
    if(is_file($package.'/vendor/autoload.php'))require_once $package.'/vendor/autoload.php';
    spl_autoload_register(static function(string $class)use($package):void{
        foreach(['MVO\\Facebook\\'=>$package.'/src/','MVO\\Messenger\\'=>dirname($package).'/messenger/src/'] as $prefix=>$directory){
            if(!str_starts_with($class,$prefix))continue;
            $file=$directory.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if(is_file($file))require_once $file;return;
        }
    });
    $connection=new FacebookConnection($options['session'],$options['auth-dir'],$options['data-dir']);
    if(isset($options['login'])){
        $email=getenv('MVO_FACEBOOK_EMAIL');$password=getenv('MVO_FACEBOOK_PASSWORD');
        if(!is_string($email)||$email===''||!is_string($password)||$password==='')throw new InvalidArgumentException('Missing private login input');
        try{$connection->login($email,$password);}
        finally{if($password!=='')sodium_memzero($password);unset($email,$password);}
    }
    $connection->connect();
    $account=$connection->accountInfo();
    echo json_encode(['state'=>$connection->getState(),'connected'=>$connection->isConnected(),
        'has_saved_session'=>$account['has_saved_session']??false,'e2ee_ready'=>$account['e2ee_ready']??false],JSON_THROW_ON_ERROR).PHP_EOL;
    $deadline=microtime(true)+(float)$seconds;
    while($connection->isConnected()&&($remaining=$deadline-microtime(true))>0)$connection->poll(min(0.25,$remaining));
}catch(MessengerException $error){
    $code=preg_match('/^[a-z0-9_]{1,80}$/D',$error->reason)?$error->reason:'facebook_connection_failed';
    $challenge=$connection?->loginChallenge();
    fwrite(STDERR,json_encode(['error'=>true,'code'=>$code,'login_challenge'=>$challenge,
        'message'=>$challenge===null?'A conexão não foi confirmada. Verifique o estado da sessão e a autenticação.':'A conta exige verificação. Mantenha o objeto vivo na aplicação para continuar; este exemplo encerra a tentativa.'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL);
    $status=1;
}catch(InvalidArgumentException){
    fwrite(STDERR,"Configuração local ou entrada privada ausente/inválida; consulte --help.\n");$status=1;
}catch(Throwable){
    fwrite(STDERR,"Falha local. Verifique extensões, diretórios privados e lock da sessão. Segredos e stack traces foram omitidos.\n");$status=1;
}finally{
    try{$connection?->stop();}catch(Throwable){fwrite(STDERR,"Não foi possível concluir o encerramento local da sessão.\n");$status=1;}
}
exit($status);
