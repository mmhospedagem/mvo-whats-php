<?php
declare(strict_types=1);
namespace MVO\Mcp;
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status) {parent::__construct('MCP HTTP request failed with status '.$status);}
}
