<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

function run(string $command): string
{
    return trim((string) shell_exec($command));
}

function slugify(string $subject): string
{
    /** @var string $replaced */
    $replaced = preg_replace('/[^A-Za-z0-9-]+/', '-', $subject);

    return strtolower(trim($replaced, '-'));
}

function title_case(string $subject): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $subject)));
}

function title_snake(string $subject, string $replace = '_'): string
{
    return str_replace(['-', '_'], $replace, $subject);
}

/**
 * @param  string[]  $replacements
 */
function replace_in_file(string $file, array $replacements): void
{
    /** @var string $contents */
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        str_replace(
            array_keys($replacements),
            array_values($replacements),
            $contents
        )
    );
}

function remove_prefix(string $prefix, string $content): string
{
    if (str_starts_with($content, $prefix)) {
        return substr($content, strlen($prefix));
    }

    return $content;
}

/**
 * @param  string[]  $names
 */
function remove_composer_deps(array $names): void
{
    /** @var string $contents */
    $contents = file_get_contents(__DIR__.'/composer.json');

    /** @var array{'require-dev': array<string, string>} $data */
    $data = json_decode($contents, true);

    foreach ($data['require-dev'] as $name => $version) {
        if (in_array($name, $names, true)) {
            unset($data['require-dev'][$name]);
        }
    }

    file_put_contents(__DIR__.'/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function remove_composer_script(string $scriptName): void
{
    /** @var string $contents */
    $contents = file_get_contents(__DIR__.'/composer.json');

    /** @var array{scripts: array<string, string>} $data */
    $data = json_decode($contents, true);

    foreach ($data['scripts'] as $name => $script) {
        if ($scriptName === $name) {
            unset($data['scripts'][$name]);
            break;
        }
    }

    file_put_contents(__DIR__.'/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function remove_composer_script_from_array(string $scriptKey, string $scriptValue): void
{
    /** @var string $contents */
    $contents = file_get_contents(__DIR__.'/composer.json');

    /** @var array{scripts: array<string, string>} $data */
    $data = json_decode($contents, true);

    /** @var string|string[] $scriptCommands */
    $scriptCommands = $data['scripts'][$scriptKey];

    if (is_array($scriptCommands)) {
        $index = array_search($scriptValue, $scriptCommands, true);
        if ($index !== false) {
            unset($data['scripts'][$scriptKey][$index]);

            // Reindex array to avoid gaps in numeric keys
            $data['scripts'][$scriptKey] = array_values($data['scripts'][$scriptKey]);
        }
    }

    file_put_contents(__DIR__.'/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function remove_readme_paragraphs(string $file): void
{
    /** @var string $contents */
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        preg_replace('/<!--delete-->.*<!--\/delete-->/s', '', $contents) ?? $contents
    );
}

function safeUnlink(string $filename): void
{
    if (file_exists($filename) && is_file($filename)) {
        unlink($filename);
    }
}

function determineSeparator(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

/**
 * @return string[]
 */
function replaceForWindows(): array
{
    /** @var string[] $replaced */
    $replaced = preg_split('/\\r\\n|\\r|\\n/', run('dir /S /B * | findstr /v /i .git\ | findstr /v /i vendor | findstr /v /i '.basename(__FILE__).' | findstr /r /i /M /F:/ ":author :vendor :package VendorName skeleton vendor_name vendor_slug author@domain.com"'));

    return $replaced;
}

/**
 * @return string[]
 */
function replaceForAllOtherOSes(): array
{
    return explode(PHP_EOL, run('grep -E -r -l -i ":author|:vendor|:package|VendorName|skeleton|migration_table_name|vendor_name|vendor_slug|author@domain.com" --exclude-dir=vendor ./* ./.github/* | grep -v '.basename(__FILE__)));
}

/**
 * @return null|array{name: ?string, login: ?string}
 */
function getGitHubApiEndpoint(string $endpoint): ?array
{
    try {
        /** @var CurlHandle $curl */
        $curl = curl_init("https://api.github.com/{$endpoint}");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: spatie-configure-script/1.0',
            ],
        ]);

        /** @var string $response */
        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($statusCode === 200) {
            /** @var array{name: ?string, login: ?string} $githubResponse */
            $githubResponse = json_decode($response);

            return $githubResponse;
        }
    } catch (Exception $e) {
        info("Warning: could not determine GitHub endpoint caused by {$e->getMessage()}");
    }

    return null;
}

function searchCommitsForGitHubUsername(): string
{
    /** @var string $username */
    $username = shell_exec('git config user.name');
    $authorName = strtolower(trim($username));

    /** @var null|string $committersRaw */
    $committersRaw = shell_exec("git log --author='@users.noreply.github.com' --pretty='%an:%ae' --reverse");
    $committersLines = explode("\n", $committersRaw ?? '');
    $committers = array_filter(array_map(function ($line) use ($authorName) {
        $line = trim($line);

        /**
         * @var string $name
         * @var string $email
         */
        [$name, $email] = explode(':', $line) + [null, null];

        return [
            'name' => $name,
            'email' => $email,
            'isMatch' => strtolower($name) === $authorName && ! str_contains($name, '[bot]'),
        ];
    }, $committersLines), fn ($item) => $item['isMatch']);

    if (count($committers) === 0) {
        return '';
    }

    $firstCommitter = reset($committers);

    /** @var string $email */
    $email = $firstCommitter['email'];

    return explode('@', $firstCommitter['email'])[0] ?? '';
}

function guessGitHubUsernameUsingCli(): string
{
    try {
        /** @var string $command */
        $command = shell_exec('gh auth status -h github.com 2>&1');

        /** @var bool $usernameMatches */
        $usernameMatches = preg_match('/ogged in to github\.com as ([a-zA-Z-_]+).+/', $command, $matches);

        if ($usernameMatches) {
            return $matches[1];
        }
    } catch (Exception) {
        info('WARNING: could not determine github username, using default');
    }

    return '';
}

function guessGitHubUsername(): string
{
    $username = searchCommitsForGitHubUsername();
    if ($username !== '') {
        return $username;
    }

    $username = guessGitHubUsernameUsingCli();
    if ($username !== '') {
        return $username;
    }

    /** @var string $remoteUrl fall back to using the username from the git remote */
    $remoteUrl = shell_exec('git config remote.origin.url');
    $remoteUrlParts = explode('/', str_replace(':', '/', trim($remoteUrl)));

    return $remoteUrlParts[1] ?? '';
}

/**
 * @return string[]
 */
function guessGitHubVendorInfo(string $authorName, string $username): array
{
    /** @var string $remoteUrl */
    $remoteUrl = shell_exec('git config remote.origin.url');
    $remoteUrlParts = explode('/', str_replace(':', '/', trim($remoteUrl)));

    /** @var null|array{name: ?string, login: ?string} $response */
    $response = getGitHubApiEndpoint("orgs/$remoteUrlParts[1]");

    if ($response === null) {
        return [$authorName, $username];
    }

    return [$response['name'] ?? $authorName, $response['login'] ?? $username];
}

$gitName = run('git config user.name');
$authorName = text('Author name?', $gitName, $gitName, true);

$gitEmail = run('git config user.email');
$authorEmail = text('Author email?', $gitEmail, $gitName, true);

$guessedUsername = guessGitHubUsername();
$authorUsername = text('Author username?', $guessedUsername, $guessedUsername, true);

$guessGitHubVendorInfo = guessGitHubVendorInfo($authorName, $authorUsername);

$vendorName = text('Vendor name?', $guessGitHubVendorInfo[0], $guessGitHubVendorInfo[0], true);
$guessUsername = $guessGitHubVendorInfo[1] ?? slugify($vendorName);
$vendorUsername = text('Vendor username?', $guessUsername, $guessUsername, true);
$vendorSlug = slugify($vendorUsername);

$vendorNamespace = str_replace('-', '', ucwords($vendorName));
$vendorNamespace = text('Vendor namespace?', $vendorNamespace, $vendorNamespace, true);

/** @var string $currentDirectory */
$currentDirectory = getcwd();

$folderName = basename($currentDirectory);
$packageName = text('Package name?', $folderName, $folderName, true);
$packageSlug = slugify($packageName);

$className = title_case($packageName);
$className = text('Class name', $className, $className, true);
$variableName = lcfirst($className);
$description = text('Package description', 'All your base are belong to us!', required: true);

$options = multiselect(
    'Any additional options?',
    ['PHPStan', 'Laravel Pint', 'Dependabot', 'Rector', 'Changelog'],
);

/** @var string[] $optionValues */
$optionValues = array_values($options);
$useLaravelPint = in_array('Pint', $optionValues, true);
$usePhpStan = in_array('PHPStan', $optionValues, true);
$useDependabot = in_array('Dependabot', $optionValues, true);
$useChangelog = in_array('Changelog', $optionValues, true);
$useRector = in_array('Rector', $optionValues, true);

info('------');
info("Author     : {$authorName} ({$authorUsername}, {$authorEmail})");
info("Vendor     : {$vendorName} ({$vendorSlug})");
info("Package    : {$packageSlug} <{$description}>");
info("Namespace  : {$vendorNamespace}\\{$className}");
info("Class name : {$className}");
info('---');
info('Packages & Utilities');
info('Use Laravel/Pint     : '.($useLaravelPint ? 'yes' : 'no'));
info('Use PhpStan : '.($usePhpStan ? 'yes' : 'no'));
info('Use Rector : '.($useRector ? 'yes' : 'no'));
info('Use Dependabot       : '.($useDependabot ? 'yes' : 'no'));
info('Use Auto-Changelog   : '.($useChangelog ? 'yes' : 'no'));
info('------');

info('This script will replace the above values in all relevant files in the project directory.');

if (! confirm('Modify files?', true)) {
    exit(1);
}

$files = (str_starts_with(strtoupper(PHP_OS), 'WIN') ? replaceForWindows() : replaceForAllOtherOSes());

foreach ($files as $file) {
    replace_in_file($file, [
        ':author_name' => $authorName,
        ':author_username' => $authorUsername,
        'author@domain.com' => $authorEmail,
        ':vendor_name' => $vendorName,
        ':vendor_slug' => $vendorSlug,
        'VendorName' => $vendorNamespace,
        ':package_name' => $packageName,
        ':package_slug' => $packageSlug,
        'Skeleton' => $className,
        'skeleton' => $packageSlug,
        'variable' => $variableName,
        ':package_description' => $description,
    ]);

    match (true) {
        str_contains($file, determineSeparator('src/Skeleton.php')) => rename($file, determineSeparator('./src/'.$className.'.php')),
        str_contains($file, 'README.md') => remove_readme_paragraphs($file),
        default => [],
    };
}

if (! $useLaravelPint) {
    safeUnlink(__DIR__.'/.github/workflows/fix-php-code-style-issues.yml');
    safeUnlink(__DIR__.'/pint.json');
}

if (! $usePhpStan) {
    safeUnlink(__DIR__.'/phpstan.neon.dist');
    safeUnlink(__DIR__.'/phpstan-baseline.neon');
    safeUnlink(__DIR__.'/.github/workflows/phpstan.yml');

    remove_composer_deps([
        'phpstan/phpstan',
        'phpstan/extension-installer',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-strict-rules',
    ]);

    remove_composer_script('lint');
}

if (! $useDependabot) {
    safeUnlink(__DIR__.'/.github/dependabot.yml');
    safeUnlink(__DIR__.'/.github/workflows/dependabot-auto-merge.yml');
}

if (! $useChangelog) {
    safeUnlink(__DIR__.'/.github/workflows/update-changelog.yml');
}

if (! $useRector) {
    safeUnlink(__DIR__.'/rector.php');

    remove_composer_deps([
        'phpstan/phpstan',
        'phpstan/extension-installer',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-strict-rules',
    ]);

    remove_composer_script('rector');
    remove_composer_script('rector:dry');
    remove_composer_script('refactor');
    remove_composer_script_from_array('ci', '@rector:dry');
}

$installAndTest = confirm('Execute `composer install` and run tests?');
if ($installAndTest) {
    run('composer install && composer test');
}

$removeFile = confirm('Let this script delete itself?', true);
if ($removeFile) {
    unlink(__FILE__);
}
