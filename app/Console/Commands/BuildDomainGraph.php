<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class BuildDomainGraph extends Command
{
    protected $signature = 'graph:build {--repo=backoffice : Which repo graph to build}';

    protected $description = 'Scan the codebase and write a SQLite dependency graph to domain-graph.sqlite';

    private PDO $db;

    private string $repo;

    public function handle(): int
    {
        $this->repo = (string) $this->option('repo');
        $dbPath = base_path('domain-graph.sqlite');

        $this->initDatabase($dbPath);

        $scanRoots = [
            base_path('app/Domain'),
            base_path('app'),
        ];

        $files = $this->collectPhpFiles($scanRoots);
        $this->info('Scanning app/... found ' . count($files) . ' files');

        $nodes = [];
        $edges = [];

        foreach ($files as $filePath) {
            $parsed = $this->parseFile($filePath);
            if ($parsed === null) {
                continue;
            }

            $nodes[] = $parsed;

            foreach ($parsed['imports'] as $imported) {
                $edges[] = [
                    'from_file'    => $parsed['file_path'],
                    'from_name'    => $parsed['name'],
                    'to_name'      => $imported,
                    'to_file'      => null,
                    'relationship' => 'imports',
                ];
            }

            foreach ($parsed['injects'] as $injected) {
                $edges[] = [
                    'from_file'    => $parsed['file_path'],
                    'from_name'    => $parsed['name'],
                    'to_name'      => $injected,
                    'to_file'      => null,
                    'relationship' => 'injects',
                ];
            }

            foreach ($parsed['dispatches'] as $dispatched) {
                $edges[] = [
                    'from_file'    => $parsed['file_path'],
                    'from_name'    => $parsed['name'],
                    'to_name'      => $dispatched,
                    'to_file'      => null,
                    'relationship' => 'dispatches',
                ];
            }

            foreach ($parsed['extends'] as $parent) {
                $edges[] = [
                    'from_file'    => $parsed['file_path'],
                    'from_name'    => $parsed['name'],
                    'to_name'      => $parent,
                    'to_file'      => null,
                    'relationship' => 'extends',
                ];
            }

            foreach ($parsed['implements'] as $iface) {
                $edges[] = [
                    'from_file'    => $parsed['file_path'],
                    'from_name'    => $parsed['name'],
                    'to_name'      => $iface,
                    'to_file'      => null,
                    'relationship' => 'implements',
                ];
            }
        }

        $this->resolveToFiles($edges, $nodes);
        $this->persistNodes($nodes);
        $this->persistEdges($edges);

        $nodeCount = count($nodes);
        $edgeCount = count($edges);

        $now = now()->toIso8601String();
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)');
        foreach ([
            ['built_at', $now],
            ['node_count', (string) $nodeCount],
            ['edge_count', (string) $edgeCount],
            ['repo', $this->repo],
        ] as [$key, $value]) {
            $stmt->execute([$key, $value]);
        }

        $this->info("Graph built: {$nodeCount} nodes, {$edgeCount} edges -> domain-graph.sqlite");

        return self::SUCCESS;
    }

    private function initDatabase(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS nodes ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'file_path TEXT NOT NULL,'
            . 'type TEXT NOT NULL,'
            . 'name TEXT NOT NULL,'
            . 'namespace_or_module TEXT,'
            . 'domain TEXT,'
            . "repo TEXT NOT NULL DEFAULT 'backoffice',"
            . 'UNIQUE(file_path, name)'
            . '); '
            . 'CREATE TABLE IF NOT EXISTS edges ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'from_file TEXT NOT NULL,'
            . 'from_name TEXT NOT NULL,'
            . 'to_name TEXT NOT NULL,'
            . 'to_file TEXT,'
            . 'relationship TEXT NOT NULL,'
            . "repo TEXT NOT NULL DEFAULT 'backoffice'"
            . '); '
            . 'CREATE TABLE IF NOT EXISTS meta ('
            . 'key TEXT PRIMARY KEY,'
            . 'value TEXT'
            . ');'
        );
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function collectPhpFiles(array $roots): array
    {
        $files = [];
        $skip = ['vendor', 'node_modules', 'storage', 'bootstrap/cache'];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $realPath = $file->getRealPath();

                if ($realPath === false || $file->getExtension() !== 'php') {
                    continue;
                }

                $shouldSkip = false;
                foreach ($skip as $segment) {
                    if (str_contains($realPath, DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR)) {
                        $shouldSkip = true;
                        break;
                    }
                }

                if ($shouldSkip) {
                    continue;
                }

                $files[] = $realPath;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array{
     *     file_path: string,
     *     type: string,
     *     name: string,
     *     namespace_or_module: string,
     *     domain: string|null,
     *     imports: list<string>,
     *     injects: list<string>,
     *     dispatches: list<string>,
     *     extends: list<string>,
     *     implements: list<string>,
     * }|null
     */
    private function parseFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $namespace = $this->extractNamespace($content);
        $classInfo = $this->extractClassInfo($content);

        if ($classInfo === null) {
            return null;
        }

        [$declarationKind, $className, $extendsClasses, $implementsInterfaces] = $classInfo;

        $imports = $this->extractUseStatements($content);
        $injects = $this->extractConstructorInjects($content, $imports);
        $dispatches = $this->extractDispatches($content);

        $domain = $this->extractDomain($namespace);
        $type = $this->inferType($filePath, $declarationKind, $className, $content);

        return [
            'file_path'           => $this->relativePath($filePath),
            'type'                => $type,
            'name'                => $className,
            'namespace_or_module' => $namespace,
            'domain'              => $domain,
            'imports'             => $imports,
            'injects'             => $injects,
            'dispatches'          => $dispatches,
            'extends'             => $extendsClasses,
            'implements'          => $implementsInterfaces,
        ];
    }

    private function extractNamespace(string $content): string
    {
        if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array{string, string, list<string>, list<string>}|null
     */
    private function extractClassInfo(string $content): ?array
    {
        $pattern = '/^\s*(?:abstract\s+|final\s+|readonly\s+)*'
            . '(class|interface|trait|enum)\s+(\w+)'
            . '(?:\s+extends\s+([\w\\\\,\s]+?))?'
            . '(?:\s+implements\s+([\w\\\\,\s]+?))?'
            . '\s*(?:\{|$)/m';

        if (! preg_match($pattern, $content, $m)) {
            return null;
        }

        $kind = $m[1];
        $name = $m[2];

        $extends = [];
        if (! empty($m[3])) {
            $extends = array_values(array_filter(array_map('trim', explode(',', $m[3]))));
        }

        $implements = [];
        if (! empty($m[4])) {
            $implements = array_values(array_filter(array_map('trim', explode(',', $m[4]))));
        }

        return [$kind, $name, $extends, $implements];
    }

    /**
     * @return list<string>
     */
    private function extractUseStatements(string $content): array
    {
        preg_match_all('/^\s*use\s+(App\\\\[\w\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  list<string>  $imports
     * @return list<string>
     */
    private function extractConstructorInjects(string $content, array $imports): array
    {
        if (! preg_match('/public\s+function\s+__construct\s*\(([^)]*)\)/s', $content, $m)) {
            return [];
        }

        $params = $m[1];

        preg_match_all(
            '/(?:private|protected|public|readonly|\s)+\s+([\w\\\\]+)\s+\$\w+/',
            $params,
            $typeMatches
        );

        $scalars = ['string', 'int', 'float', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'null', 'void'];
        $injected = [];

        foreach ($typeMatches[1] ?? [] as $type) {
            $type = trim($type);
            if ($type === '' || in_array($type, $scalars, true)) {
                continue;
            }

            $resolved = $this->resolveShortName($type, $imports);
            if ($resolved !== null) {
                $injected[] = $resolved;
            }
        }

        return array_values(array_unique($injected));
    }

    /**
     * @return list<string>
     */
    private function extractDispatches(string $content): array
    {
        $dispatched = [];

        preg_match_all('/(?:dispatch|event)\s*\(\s*new\s+([\w\\\\]+)\s*[,(]/', $content, $m1);
        foreach ($m1[1] ?? [] as $name) {
            $dispatched[] = trim($name);
        }

        return array_values(array_unique($dispatched));
    }

    /**
     * @param  list<string>  $imports
     */
    private function resolveShortName(string $shortName, array $imports): ?string
    {
        if (str_contains($shortName, '\\')) {
            return str_starts_with($shortName, 'App\\') ? $shortName : null;
        }

        foreach ($imports as $import) {
            $parts = explode('\\', $import);
            if (end($parts) === $shortName) {
                return $import;
            }
        }

        return null;
    }

    private function extractDomain(string $namespace): ?string
    {
        if (preg_match('/^App\\\\Domain\\\\(\w+)/', $namespace, $m)) {
            return $m[1];
        }

        return null;
    }

    private function inferType(string $filePath, string $kind, string $className, string $content): string
    {
        if ($kind === 'interface') {
            return 'interface';
        }

        if ($kind === 'trait') {
            return 'trait';
        }

        if ($kind === 'enum') {
            return 'enum';
        }

        $normalizedPath = str_replace('\\', '/', $filePath);

        $dirTypeMap = [
            '/Events/'       => 'event',
            '/Listeners/'    => 'listener',
            '/Aggregates/'   => 'aggregate',
            '/Projectors/'   => 'projector',
            '/Reactors/'     => 'reactor',
            '/Services/'     => 'service',
            '/Actions/'      => 'action',
            '/Models/'       => 'model',
            '/Policies/'     => 'policy',
            '/Repositories/' => 'repository',
            '/Workflows/'    => 'workflow',
            '/Controllers/'  => 'controller',
            '/Jobs/'         => 'job',
            '/Observers/'    => 'observer',
        ];

        foreach ($dirTypeMap as $segment => $type) {
            if (str_contains($normalizedPath, $segment)) {
                return $type;
            }
        }

        if (str_contains($content, 'ShouldBeStored')) {
            return 'event';
        }

        if (str_ends_with($className, 'Listener')) {
            return 'listener';
        }

        if (str_ends_with($className, 'Service')) {
            return 'service';
        }

        if (str_ends_with($className, 'Controller')) {
            return 'controller';
        }

        if (str_ends_with($className, 'Repository')) {
            return 'repository';
        }

        if (str_ends_with($className, 'Policy')) {
            return 'policy';
        }

        if (str_ends_with($className, 'Action')) {
            return 'action';
        }

        if (str_ends_with($className, 'Projector')) {
            return 'projector';
        }

        if (str_ends_with($className, 'Reactor')) {
            return 'reactor';
        }

        if (str_contains($content, 'AggregateRoot')) {
            return 'aggregate';
        }

        return 'class';
    }

    /**
     * @param  list<array{
     *     from_file: string,
     *     from_name: string,
     *     to_name: string,
     *     to_file: string|null,
     *     relationship: string,
     * }>  $edges
     * @param  list<array{file_path: string, name: string, namespace_or_module: string}>  $nodes
     */
    private function resolveToFiles(array &$edges, array $nodes): void
    {
        $nameIndex = [];
        $fqnIndex = [];

        foreach ($nodes as $node) {
            $nameIndex[$node['name']] = $node['file_path'];
            $fqn = rtrim($node['namespace_or_module'], '\\') . '\\' . $node['name'];
            $fqnIndex[$fqn] = $node['file_path'];
        }

        foreach ($edges as &$edge) {
            $toName = $edge['to_name'];

            if (isset($fqnIndex[$toName])) {
                $edge['to_file'] = $fqnIndex[$toName];
            } elseif (isset($nameIndex[$toName])) {
                $edge['to_file'] = $nameIndex[$toName];
            }
        }
        unset($edge);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function persistNodes(array $nodes): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO nodes (file_path, type, name, namespace_or_module, domain, repo) '
            . 'VALUES (:file_path, :type, :name, :namespace_or_module, :domain, :repo)'
        );

        $this->db->beginTransaction();
        foreach ($nodes as $node) {
            $stmt->execute([
                ':file_path'           => $node['file_path'],
                ':type'                => $node['type'],
                ':name'                => $node['name'],
                ':namespace_or_module' => $node['namespace_or_module'],
                ':domain'              => $node['domain'],
                ':repo'                => $this->repo,
            ]);
        }
        $this->db->commit();
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function persistEdges(array $edges): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO edges (from_file, from_name, to_name, to_file, relationship, repo) '
            . 'VALUES (:from_file, :from_name, :to_name, :to_file, :relationship, :repo)'
        );

        $this->db->beginTransaction();
        foreach ($edges as $edge) {
            $stmt->execute([
                ':from_file'    => $edge['from_file'],
                ':from_name'    => $edge['from_name'],
                ':to_name'      => $edge['to_name'],
                ':to_file'      => $edge['to_file'],
                ':relationship' => $edge['relationship'],
                ':repo'         => $this->repo,
            ]);
        }
        $this->db->commit();
    }

    private function relativePath(string $absolute): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolute, $base)) {
            return substr($absolute, strlen($base));
        }

        return $absolute;
    }
}
