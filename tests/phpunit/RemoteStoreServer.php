<?php
declare(strict_types=1);
// Isolated local RESP2 fixture. No user data or outside network connections.
$server=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorText);
if($server===false) exit(2);
$name=stream_socket_get_name($server,false);fwrite(STDOUT,substr($name,strrpos($name,':')+1)."\n");fflush(STDOUT);
$socket=stream_socket_accept($server,5);if($socket===false) exit(3);stream_set_timeout($socket,5);
for($request=0;$request<4;$request++) {
    $line=fgets($socket);if($line===false) break;$n=(int)substr($line,1);$args=[];
    for($i=0;$i<$n;$i++) { $len=(int)substr(fgets($socket),1);$value='';while(strlen($value)<$len) {$bytes=fread($socket,$len-strlen($value));if($bytes===false || $bytes==='') exit(4);$value.=$bytes;}fread($socket,2);$args[]=$value; }
    if($args[0]==='AUTH' || $args[0]==='SELECT') fwrite($socket,"+OK\r\n");
    elseif($args[0]==='ECHO') fwrite($socket,'$'.strlen($args[1])."\r\n".$args[1]."\r\n");
    elseif($args[0]==='ARRAY') fwrite($socket,"*3\r\n$5\r\nhello\r\n$-1\r\n:42\r\n");
    else fwrite($socket,"-ERR unexpected\r\n");
}
fclose($socket);fclose($server);
