<?php
declare(strict_types=1);

// Try multiple autoload locations to support both local dev (vendor next to
// the project) and composer-installed usage (vendor levels up via the bin-
// proxy). Mirrors the mailhelper/simplemcp install patterns.
foreach (
    [
        __DIR__ . '/../vendor/autoload.php',     // local development
        __DIR__ . '/../../../autoload.php',      // installed via composer
        __DIR__ . '/../../../../autoload.php'    // alternative composer path
    ]
    as $autoload_path
) {
    if (is_file($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}

use vielhuber\simplemcp\simplemcp;

// simplemcp's Dotenv loader throws if the .env is missing — auto-touch an
// empty one on first boot so the server runs out of the box. Users can
// later populate MCP_TOKEN by editing the file (template in .env.example).
// Fail loudly on write errors so the subprocess host gets a clear signal
// instead of an opaque Dotenv crash downstream.
$env_path = __DIR__ . '/.env';
if (!is_file($env_path) && @file_put_contents($env_path, "MCP_TOKEN=\n") === false) {
    fwrite(STDERR, 'ppthelper-mcp-server: failed to create ' . $env_path . ' (check permissions on the src/ directory)' . PHP_EOL);
    exit(1);
}

new simplemcp(
    name: 'ppthelper-mcp-server',
    log: 'mcp-server.log',
    discovery: '.', // src/ — where ppthelper.php with #[McpTool] lives
    auth: 'static', // 'static'|'totp'
    env: '.env'
);
