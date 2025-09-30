#!/usr/bin/env php
<?php
chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use X2nx\WebmanMcp\Process\Server;

support\App::loadAllConfig(['route']);

(new Server())->stdioServer();
