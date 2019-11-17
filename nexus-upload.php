#!/usr/bin/env php
<?php
/**
 * PHP Script to upload/push a composer project to a Nexus Repository Manager (that supports composer).
 */

/**
 * 
 * @param type $path
 * @param type $ignoreList
 * @return boolean
 */
function isIgnorebale($path, $ignoreList) {


    foreach ($ignoreList as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * 
 * @param string   $directory
 * @param string   $zipPath
 * @param callable $fileFilter
 */
function zipDirectory($directory, $zipPath, $fileFilter) {
    // Get real path for our folder
    $rootRealPath = realpath($directory);

    // Initialize archive object
    $zipArchive = new ZipArchive();

    $zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    /** @var SplFileInfo[] $files */
    $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootRealPath),
            RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            // Skip directories (they would be added automatically)
            continue;
        }

        $realpath = $file->getRealPath();
        $relativePath = substr($realpath, strlen($rootRealPath) + 1);

        // Decide whether file should be included
        if (\call_user_func($fileFilter, $relativePath)) {
            $zipArchive->addFile($realpath, $relativePath);
        }
    }
    $zipArchive->close();
}

function curlPutFile($url, $filename, $username, $password) {
    $filestream = fopen($filename, "rb");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);

    curl_setopt($ch, CURLOPT_PUT, 1);
    curl_setopt($ch, CURLOPT_INFILE, $filestream);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filename));

    $result = curl_exec($ch);
    curl_close($ch);
    return $result !== false;
}

function getPackageName($projectUrl) {
    return getComposerJson($projectUrl)['name'];
}

$projectDir = getcwd();
$packageName = getPackageName($projectDir);

/**
 * Get composer JSON Object
 * @staticvar array<stdClass> $composerJsons
 * @param string $projectUrl
 *
 * @return array
 */
function getComposerJson($projectUrl) {
    static $composerJsons;
    if (!isset($composerJson)) {
        $composerJsons = [];
    }
    if (!array_key_exists($projectUrl, $composerJsons)) {
        $composerJsonPath = $projectUrl . '/composer.json';

        $fileContents = file_get_contents($composerJsonPath);
        $composerJson = \json_decode($fileContents, true);

        $composerJsons[$projectUrl] = $composerJson;
    }

    return $composerJsons[$projectUrl];
}

function getComposerOption($projectUrl, $option) {

    $json = getComposerJson($projectUrl);

    if (!isset($json['extra'])) {
        return null;
    }
    if (!isset($json['extra']['nexus-upload'])) {
        return null;
    }

    if (!isset($json['extra']['nexus-upload'][$option])) {
        return null;
    }
    return $json['extra']['nexus-upload'][$option];
}

/**
 * Get option from CLI.
 *
 * @staticvar array $options
 * @param array<string> $option
 *
 * @return string
 */
function getCliOption($option) {
    static $options;
    if (!isset($options)) {
        // Followed by single colon = required
        // Followed by double colon = optional
        $options = getopt(
                '',
                [
                    'repository:',
                    'username:',
                    'password:',
                    'version:',
                    'ignore:',
                ]
        );
    }

    if (array_key_exists($option, $options)) {
        return $options[$option];
    } else {
        return null;
    }
}

function getOption($projectUrl, $option) {
    $cliOption = getCliOption($option);
    if ($cliOption !== null) {
        return $cliOption;
    } else {
        return getComposerOption($projectUrl, $option);
    }
}

$nexusRepo = getOption($projectDir, 'repository');
$username = getOption($projectDir, 'username');
$password = getOption($projectDir, 'password');
$version = getOption($projectDir, 'version');
$ignore = getOption($projectDir, 'ignore');

$stdIgnore = "/^(\.git|vendor|composer\.lock|\.gitignore)/";
if (is_array($ignore)) {
    $ignore[] = $stdIgnore;
} else {
    $ignore = [
        $stdIgnore, // Standard PHP ignores
        $ignore
    ];
}


// Process
$ignoreList = array_map(function($value) {
    if ($value === null) {
        return false;
    }
    if (strpos($value, '/') !== 0) {
        // Quote the string
        $value = preg_quote($value);
        // Escape slashes
        $value = str_replace('/', '\/', $value);
        // Allow * wildcards
        $value = str_replace('\*', '.*', $value);

        return '/^' . $value . '/';
    } else {
        return $value;
    }
}, $ignore);

$ignoreList = array_filter($ignoreList);

echo "Running with:\n";
echo "\tRepository:          $nexusRepo\n";
echo "\tUsername:            $username\n";
echo "\tPassword:            " . (!empty($password)) ? '(provided)' : 'missing' . "\n";
echo "\tVersion:             $version\n";
echo "\tIgnore patterns:     " . implode(', ', $ignoreList) . "\n";
echo "\n";

if (empty($version)) {
    echo "Version is empty\n";
    exit();
}

$zipFileName = getcwd() . DIRECTORY_SEPARATOR . str_replace('/', '-', $packageName) . '-' . $version . '.zip';

echo "Zipping '$projectDir' as '$zipFileName'\n";
zipDirectory($projectDir, $zipFileName, function($path) use ($ignoreList) {
    if (isIgnorebale($path, $ignoreList)) {
        return false;
    }
    echo "Adding '" . $path . "' \n";
    return true;
});

echo "Created '$zipFileName' (" . filesize($zipFileName) . " bytes)\n";

$url = $nexusRepo . "packages/upload/" . $packageName . '/' . $version;

echo "\n";
echo "Uploading '$zipFileName' to '$url'\n";

if (filesize($zipFileName) === 0) {
    throw new Exception("Zip file is empty");
}
$success = curlPutFile($url, $zipFileName, $username, $password);
if (!$success) {
    echo "Failed to upload zip to repository\n";
    die();
} else {
    echo "Finished\n";
}
