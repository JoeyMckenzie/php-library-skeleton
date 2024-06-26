<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

update_composer_key('name', ':vendor_slug/:package_slug');

$gitName = run('git config user.name');
$authorName = text('Author name?', $gitName, $gitName, true);

$gitEmail = run('git config user.email');
$authorEmail = text('Author email?', $gitEmail, $gitEmail, true);

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
    [
        'PHPStan',
        'Laravel Pint',
        'Dependabot',
        'Rector',
        'Git hooks',
        'Changelog',
    ],
);

/** @var string[] $optionValues */
$optionValues = array_values($options);
$useLaravelPint = in_array('Pint', $optionValues, true);
$usePhpStan = in_array('PHPStan', $optionValues, true);
$useDependabot = in_array('Dependabot', $optionValues, true);
$useChangelog = in_array('Changelog', $optionValues, true);
$useRector = in_array('Rector', $optionValues, true);
$useGitHooks = in_array('Git hooks', $optionValues, true);

$minimumPhpVersion = select('Minimum PHP version?', [
    '8.0', '8.1', '8.2', '8.3',
], default: '8.3');

info('------');
info("Author      : $authorName ($authorUsername, $authorEmail)");
info("Vendor      : $vendorName ($vendorSlug)");
info("Package     : $packageSlug <$description>");
info("Namespace   : $vendorNamespace\\$className");
info("Class name  : $className");
info("PHP version : $minimumPhpVersion");
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

// Update PHP version
update_composer_dep('php', "^$minimumPhpVersion");
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

if (! $useGitHooks) {
    safeUnlinkDirectory(__DIR__.'/.githooks');
    remove_composer_script('prepare');
} else {
    $prepareGitHooks = confirm('Prepare git hooks?', true);
    if ($prepareGitHooks) {
        run('composer run prepare');
    }
}

$installAndTest = confirm('Execute `composer install` and run tests?');
if ($installAndTest) {
    remove_composer_deps([
        'laravel/prompts',
        'ext-curl',
    ]);

    safeUnlinkDirectory(__DIR__.'/../vendor');
    run('composer install && composer test');
}

$removeFile = confirm('Let this script delete itself?', true);
if ($removeFile) {
    safeUnlinkDirectory(__DIR__);
}
