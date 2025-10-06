<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\MCP\DatasetMcpServer;

class McpServe extends Command {
  protected $signature = 'mcp:serve';
  protected $description = 'Run MCP server (stdio transport)';

  public function handle(): int {
    $server = app(DatasetMcpServer::class);
    $server->run();
    return self::SUCCESS;
  }
}
