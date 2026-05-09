<?php

/**
 * Worktree-specific autoload bootstrap.
 *
 * The git worktree cannot have its own vendor directory installed (disk space).
 * This bootstrap loads the main repo's autoloader then patches both the PSR-4
 * loader and the classmap to ensure all App\ and Tests\ classes are resolved
 * from the worktree's own directories, so worktree-specific implementations
 * (e.g. CardEntitlementService) take precedence over the main repo's stubs.
 */

$mainRepoRoot = '/Users/Lihle/Development/Coding/maphapay-backoffice';
$worktreeRoot = dirname(__FILE__);

require $mainRepoRoot . '/vendor/autoload.php';

/** @var \Composer\Autoload\ClassLoader|null $loader */
$loader = null;
foreach (spl_autoload_functions() as $fn) {
    if (is_array($fn) && isset($fn[0]) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
        $loader = $fn[0];
        break;
    }
}

if ($loader !== null) {
    // 1. Prepend worktree app/ and tests/ in PSR-4 so they are checked first.
    $loader->addPsr4('App\\', [$worktreeRoot . '/app'], true);
    $loader->addPsr4('Database\\', [$worktreeRoot . '/database'], true);
    $loader->addPsr4('Tests\\', [$worktreeRoot . '/tests'], true);

    // 2. Patch the classmap to redirect any App\ class file that exists
    //    in the worktree's app/ directory. The classmap wins over PSR-4,
    //    so we must remove or overwrite entries for worktree-specific files.
    //
    //    Strategy: iterate the existing classmap; for every entry whose path
    //    sits under $mainRepoRoot/app/, check if an equivalent file exists
    //    under $worktreeRoot/app/. If so, overwrite the classmap entry.
    $classMap = $loader->getClassMap();
    // Classmap paths may use vendor/composer/../../app form; resolve them first.
    $mainAppPrefix = realpath($mainRepoRoot . '/app') . DIRECTORY_SEPARATOR;
    $worktreeAppPrefix = $worktreeRoot . '/app/';

    $patched = [];
    foreach ($classMap as $class => $path) {
        $resolved = realpath($path);
        if ($resolved === false) {
            continue;
        }
        if (str_starts_with($resolved, $mainAppPrefix)) {
            $relative = substr($resolved, strlen($mainAppPrefix));
            $worktreePath = $worktreeAppPrefix . $relative;
            if (file_exists($worktreePath)) {
                $patched[$class] = $worktreePath;
            }
        }
    }

    if (!empty($patched)) {
        $loader->addClassMap($patched);
        fwrite(STDERR, '[worktree-bootstrap] Patched ' . count($patched) . ' classmap entries.' . PHP_EOL);
    }

    // Verify the key class is mapped correctly.
    $key = 'App\\Domain\\CardSubscriptions\\Services\\CardEntitlementService';
    $mapped = $loader->getClassMap()[$key] ?? '(not in classmap)';
    fwrite(STDERR, '[worktree-bootstrap] CardEntitlementService -> ' . $mapped . PHP_EOL);
}
