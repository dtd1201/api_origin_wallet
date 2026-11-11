<?php

require_once __DIR__.'/../vendor/autoload.php';

// Opt in without resolving facades: plain PHPUnit tests have no Laravel app.
// Tests\TestCase installs the guard after every application refresh.
\Tests\Support\NiumTransferOfflineHttpGuard::setEnabled(true);
