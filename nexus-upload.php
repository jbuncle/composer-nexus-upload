#!/usr/bin/env php
<?php

const RESET = "\033[0m";
const RED = "\033[1;31m";
const GREEN = "\033[1;32m";
const YELLOW = "\033[1;33m";
const BLUE = "\033[1;34m";

/**
 * @param string $path
 * @param array $ignoreList
 * @return bool
 */
function isIgnorebale($path, $ignoreList): bool
{
    foreach ($ignoreList as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }
    return false;
}

function zipDirectory(string $directory, string $zipPath, callable $fileFilter): void
{
    $rootRealPath = realpath($directory);
    $zipArchive = new ZipArchive();
    $zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootRealPath),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if ($file->isDir()) continue;

        $realpath = $file->getRealPath();
        $relativePath = substr($realpath, strlen($rootRealPath) + 1);

        if ($fileFilter($relativePath)) {
            $zipArchive->addFile($realpath, $relativePath);
            echo BLUE . "Adding: $relativePath" . RESET . PHP_EOL;
        }
    }

    $zipArchive->close();
}

function curlPutFile(string $url, string $filename, string $username, string $password): bool
{
    echo YELLOW . "Preparing HTTP PUT request...\n" . RESET;
    echo "\tURL:        $url\n";
    echo "\tFile:       $filename\n";
    echo "\tSize:       " . filesize($filename) . " bytes\n";
    echo "\tUsername:   $username\n\n";

    $filestream = fopen($filename, "rb");

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_USERPWD        => "$username:$password",
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $filestream,
        CURLOPT_INFILESIZE     => filesize($filename),
        CURLOPT_HEADER         => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        echo RED . "cURL Error ($errno): $error\n" . RESET;
        return false;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    echo BLUE . "Response Status: HTTP $statusCode\n" . RESET;
    echo BLUE . "Response Headers:\n" . RESET;
    foreach (explode("\n", trim($headers)) as $headerLine) {
        echo "\t$headerLine\n";
    }

    // Print short body summary
    $trimmedBody = trim($body);
    if ($trimmedBody !== '') {
        echo BLUE . "Response Body (first 500 chars):\n" . RESET;
        echo substr($trimmedBody, 0, 500) . (strlen($trimmedBody) > 500 ? "..." : "") . "\n";
    } else {
        echo BLUE . "Response Body: (empty)\n" . RESET;
    }

    if ($statusCode !== 200) {
        echo RED . "Upload failed: HTTP $statusCode\n" . RESET;
        return false;
    }

    echo GREEN . "Upload succeeded with HTTP $statusCode\n" . RESET;
    return true;
}

function getComposerJson(): array
{
    static $composerJson;
    if (!isset($composerJson)) {
        $path = getcwd() . '/composer.json';
        $composerJson = json_decode(file_get_contents($path), true);
    }
    return $composerJson;
}

function getComposerOptions(): array
{
    return getComposerJson()['extra']['nexus-upload'] ?? [];
}

function getCliOptions(): array
{
    static $options;
    if (!isset($options)) {
        $options = getopt('', [
            'repository:',
            'username:',
            'password::',
            'version:',
            'ignore:',
        ]);
    }
    return $options;
}

function getProperties(): array
{
    $path = getcwd() . '/.nexus';
    if (!file_exists($path)) return [];

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $options = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#')) continue;

        [$key, $value] = explode('=', $line, 2);
        $options[trim($key)] = trim($value);
    }

    return $options;
}

/**
 * @param string $option
 * @return mixed
 */
function getOption(string $option) {
    static $options;
    if (!isset($options)) {
        $options = array_merge(
            getProperties(),
            getComposerOptions(),
            getCliOptions()
        );
    }
    return $options[$option] ?? null;
}

// === Main Execution ===

$packageName = getComposerJson()['name'];
$nexusRepo   = getOption('repository');
$username    = getOption('username');
$password    = getOption('password');
$version     = getOption('version');
$ignore      = getOption('ignore');

$stdIgnore = "/^(\.git|vendor|composer\.lock|\.gitignore|\.nexus)/";
$ignore = is_array($ignore) ? [...$ignore, $stdIgnore] : [$ignore, $stdIgnore];

$ignoreList = array_filter(array_map(function ($pattern) {
    if ($pattern === null) return false;
    if (str_starts_with($pattern, '/')) return $pattern;

    $pattern = str_replace(['*', '/'], ['.*', '\/'], preg_quote($pattern));
    return '/^' . $pattern . '/';
}, $ignore));

// Summary
echo YELLOW . "Running with:\n" . RESET;
echo "\tRepository:      $nexusRepo\n";
echo "\tUsername:        $username\n";
echo "\tPassword:        " . (!empty($password) ? '(provided)' : 'missing') . "\n";
echo "\tVersion:         $version\n";
echo "\tIgnore patterns: " . implode(', ', $ignoreList) . "\n\n";

if (empty($version)) {
    echo RED . "Version is required.\n" . RESET;
    exit(1);
}

$projectDir  = getcwd();
$zipFileName = $projectDir . '/' . str_replace('/', '-', $packageName) . "-$version.zip";

echo YELLOW . "Zipping project directory...\n" . RESET;
zipDirectory($projectDir, $zipFileName, fn($path) => !isIgnorebale($path, $ignoreList));

$filesize = filesize($zipFileName);
echo GREEN . "Created: $zipFileName ($filesize bytes)\n" . RESET;

if ($filesize === 0) {
    echo RED . "Zip file is empty. Aborting.\n" . RESET;
    exit(1);
}

$url = rtrim($nexusRepo, '/') . "/packages/upload/$packageName/$version";
echo YELLOW . "Uploading to: $url\n" . RESET;

try {
    if (curlPutFile($url, $zipFileName, $username, $password)) {
        echo GREEN . "Upload complete.\n" . RESET;
    } else {
        echo RED . "Upload failed.\n" . RESET;
        exit(1);
    }
} catch (Exception $e) {
    echo RED . "Error: " . $e->getMessage() . "\n" . RESET;
    exit(1);
}
