#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-23
 * Modified    : 2026-08-05
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

// New pipeline, running everything fully automated and reducing the time spent on manual verification even more.
// We can't use ROOT_PATH because we'll connect to LOVD, which requires pointing that constant to a different directory.
require_once(__DIR__ . '/aggregator.php');
require_once(__DIR__ . '/formatter.php');
require_once(__DIR__ . '/log.php');
require_once(__DIR__ . '/normalizer.php');
require_once(__DIR__ . '/processor.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/ssh.php');
require_once(__DIR__ . '/validator.php');
use LOVD\VKGL\Aggregator;
use LOVD\VKGL\Formatter;
use LOVD\VKGL\Normalizer;
use LOVD\VKGL\Processor;
use LOVD\VKGL\Validator;
use LOVD\Log;
use LOVD\Settings;
use LOVD\SSH;



// All PHP scripts use these error codes; these are the default values.
// See http://tldp.org/LDP/abs/html/exitcodes.html for recommendations, in particular:
// "[I propose] restricting user-defined exit codes to the range 64 - 113 (...), to conform with the C/C++ standard."
$aErrorCodes = array_flip([
    0 => 'EXIT_OK',
    64 => 'EXIT_WARNINGS_OCCURRED',
    'EXIT_ERROR_ARGS_INSUFFICIENT',
    'EXIT_ERROR_ARGS_NOT_UNDERSTOOD',
    'EXIT_ERROR_SETTINGS_CANT_CREATE',
    'EXIT_ERROR_SETTINGS_UNREADABLE',
    'EXIT_ERROR_SETTINGS_CONTENT_ERROR',
    'EXIT_ERROR_SETTINGS_CANT_UPDATE',
    'EXIT_ERROR_CACHE_CANT_CREATE',
    'EXIT_ERROR_CACHE_UNREADABLE',
    'EXIT_ERROR_CACHE_CONTENT_ERROR',
    'EXIT_ERROR_CACHE_CANT_UPDATE',
    'EXIT_ERROR_INPUT_NOT_A_FILE',
    'EXIT_ERROR_INPUT_UNREADABLE',
    'EXIT_ERROR_INPUT_CONTENT_ERROR',
    'EXIT_ERROR_OUTPUT_CANT_CREATE',
    'EXIT_ERROR_OUTPUT_CONTENT_ERROR',
    'EXIT_ERROR_OUTPUT_CANT_WRITE',
    'EXIT_ERROR_CONNECTION_PROBLEM',
]);

// Allow controlling the pipeline through command-line arguments.
// Use --testing to enable tests; the pipeline will work in the tests/ directory, instead.
// Use --release=DATE (e.g., --release=2026-01) to control what release the pipeline will work on.
$bTesting = false;
$sRelease = date('Y-m');
$aArgs = $_SERVER['argv'];
$sScriptName = array_shift($aArgs);
while ($aArgs) {
    // Check for flags.
    $sArg = array_shift($aArgs);
    if ($sArg == '--testing') {
        $bTesting = true;
    } elseif (preg_match('/^--release=[0-9]{4}-[0-9]{2}$/', $sArg)) {
        $sRelease = explode('=', $sArg)[1];
    } else {
        print("Argument '$sArg' is not understood.\n\n");
        die($aErrorCodes['EXIT_ERROR_ARGS_NOT_UNDERSTOOD']);
    }
}

// Load the settings; the location of the settings file depends on whether we're testing or not.
try {
    $Settings = new Settings(!$bTesting? null : __DIR__ . '/tests/settings.json');
} catch (Exception $e) {
    print($e->getMessage() . ".\n\n");
    if (str_starts_with($e->getMessage(), 'Unable to create')) {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_CANT_CREATE']);
    } else {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_UNREADABLE']);
    }
}

// Store all the error codes in the settings so that all scripts can use them.
foreach($aErrorCodes as $sErrorCode => $nErrorCode) {
    if ($Settings->get("error_codes|$sErrorCode") !== $nErrorCode) {
        $Settings->set("error_codes|$sErrorCode", $nErrorCode);
    }
}

// Convert some older settings to newer settings.
foreach ($Settings->get() as $sKey => $Value) {
    if (preg_match('/^center_([a-z_]+)_id$/', $sKey, $aRegs)) {
        // Old-style center settings. Convert into something new.
        $sCenter = $aRegs[1];
        try {
            if (!$Settings->get("centers|$sCenter|id")) {
                // Hasn't migrated yet.
                $Settings->set("centers|$sCenter|id", $Value);
            }
            // Delete it, we don't need this anymore.
            $Settings->delete($sKey);
        } catch (Exception $e) {
            print("Failed to update the settings.\n" . $e->getMessage() . ".\n\n");
            die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_CANT_UPDATE'));
        }
    }
}

// Fix the timezone, if needed (PHP defaults to UTC).
if ($Settings->get('timezone')) {
    date_default_timezone_set($Settings->get('timezone'));
}



// First, determine which release we're supposed to be working on.
$aMonths = $Settings->get('release_months');
if ($aMonths === null) {
    print("Can't find information in the settings about the release months. Please configure them first.\n\n");
    die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_CONTENT_ERROR'));
}

rsort($aMonths);
list($nThisYear, $nThisMonth) = explode('-', $sRelease);
$nReleaseYear = null;
$nReleaseMonth = null;
foreach ($aMonths as $nMonth) {
    if ($nMonth <= $nThisMonth) {
        $nReleaseYear = $nThisYear;
        $nReleaseMonth = $nMonth;
        break;
    }
}
if ($nReleaseMonth === null) {
    $nReleaseYear = ($nThisYear - 1);
    $nReleaseMonth = max($aMonths);
}
$sRelease = $nReleaseYear . '-' . str_pad($nReleaseMonth, 2, '0', STR_PAD_LEFT);

// If the release folder doesn't exist yet, create it.
if ($bTesting) {
    define('RELEASE_PATH', __DIR__ . '/tests/releases/' . $sRelease);
} else {
    define('RELEASE_PATH', __DIR__ . '/' . $sRelease);
}
define('LOG_PATH', RELEASE_PATH . '/status.log');
if (!file_exists(RELEASE_PATH) && !mkdir(RELEASE_PATH)) {
    print("Can't create " . RELEASE_PATH . ".\n\n");
    die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
}
try {
    $Log = new Log(LOG_PATH);
    $Log->printToScreen(true);
} catch (Exception $e) {
    print("Can't create " . LOG_PATH . ".\n\n");
    die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
}



// For the release's status, we'll re-use the Settings class.
try {
    $Status = new Settings(RELEASE_PATH . '/status.json');
} catch (Exception $e) {
    $Log->add($e->getMessage() . '.', '!!');
    if (str_starts_with($e->getMessage(), 'Unable to create')) {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_CANT_CREATE']);
    } else {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_UNREADABLE']);
    }
}

// Check the status; are we perhaps done already?
if ($Status->get('step') == 9) {
    // Nothing to do; we're done already.
    die($Settings->get('error_codes|EXIT_OK'));
}

// Not done yet. Let's have a look.
$Log->addBreakIfNotEmpty();
$Log->add('Checking current status...');
chdir(RELEASE_PATH);
if ($Status->get('step') === null) {
    // If this fails, we have an unhandled exception.
    // For simplicity's sake, we decided to not catch Status update exceptions.
    $Status->set('step', 0);
}

// For the statistics, again re-use the Settings class.
try {
    $Statistics = new Settings(__DIR__ . (!$bTesting? '' : '/tests') . '/statistics.json');
} catch (Exception $e) {
    $Log->add($e->getMessage() . '.', '!!');
    if (str_starts_with($e->getMessage(), 'Unable to create')) {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_CANT_CREATE']);
    } else {
        die($aErrorCodes['EXIT_ERROR_SETTINGS_UNREADABLE']);
    }
}





// Step 1: Check if we have all the files; download them if possible.
$nStep = 1;
if ($Status->get('step') < $nStep) {
    // Check if we have all the files.
    $Log->add('Checking if we have all the required files...');
    $aFilesMissing = [];
    $aSSHConnections = [];
    foreach ($Settings->get('centers') as $sCenter => $aCenter) {
        if (empty($aCenter['files'])) {
            $Log->add("Center $sCenter doesn't have files configured; please define what files to expect, or remove the center.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_CONTENT_ERROR'));
        }

        // Loop the file settings, and check everything.
        foreach ($aCenter['files'] as $sOrigin => $sFile) {
            // Check if we have the file; if not, attempt to retrieve it.
            if (file_exists($sFile)) {
                $Status->set("data_files|$sFile", $sCenter);
                continue;
            }

            // File does not exist... is it packed?
            if (file_exists("$sFile.gz")) {
                @exec('gunzip ' . escapeshellarg("$sFile.gz"), $aOutput, $nReturn);
                if ($nReturn !== 0) {
                    $Log->add("Failed to decompress $sFile.gz for center $sCenter.", '!!');
                    die($Settings->get('error_codes|EXIT_ERROR_INPUT_UNREADABLE'));
                }
                $Log->add("Decompressed $sFile.gz for center $sCenter.", 'OK');
                $Status->set("data_files|$sFile", $sCenter);
                continue;
            }

            if (is_int($sOrigin)) {
                // We don't have the file and don't know how to retrieve it; no origin was provided.
                $Log->add("File $sFile missing for center $sCenter and no origin has been provided; I can't resolve this problem.", '!!');
                $aFilesMissing[] = $sFile;
                continue; // Continue to the next file.
            }

            // Otherwise, connect to the host and download the file.
            // Because all kinds of things can go wrong here, add a log entry so that we know what it was trying to do.
            $Log->add("Trying to download $sFile from $sOrigin...");
            list($sHost, $sRemotePath) = array_pad(explode(':', $sOrigin), 2, '');
            if (!isset($aSSHConnections[$sHost])) {
                try {
                    $aSSHConnections[$sHost] = new SSH(
                        ($Settings->get("servers|$sHost|host") ?? ''),
                        ($Settings->get("servers|$sHost|fingerprint") ?? '')
                    );
                } catch (Exception $e) {
                    $Log->add("Failed to connect to $sOrigin to obtain $sFile.\n" . $e->getMessage() . '.', '!!');
                    die($Settings->get('error_codes|EXIT_ERROR_CONNECTION_PROBLEM'));
                }
            }

            // Resolve the path by replacing some variables.
            $sRemotePath = str_replace(['{YEAR}', '{MONTH}'], [$nReleaseYear, str_pad($nReleaseMonth, 2, '0', STR_PAD_LEFT)], $sRemotePath);
            $sLocalPath = $sFile;
            if (str_ends_with($sRemotePath, '.gz')) {
                $sLocalPath .= '.gz';
            }

            try {
                $aSSHConnections[$sHost]->download($sRemotePath, $sLocalPath);
                $Log->add("Successfully downloaded $sLocalPath.", 'OK');
            } catch (Exception $e) {
                $Log->add("Failed to download $sLocalPath.\n" . $e->getMessage() . '.', '!!');
                die($Settings->get('error_codes|EXIT_ERROR_CONNECTION_PROBLEM'));
            }

            // If these are compressed files, decompress them.
            if (str_ends_with($sLocalPath, '.gz')) {
                @exec('gunzip ' . escapeshellarg("$sFile.gz"), $aOutput, $nReturn);
                if ($nReturn !== 0) {
                    $Log->add("Failed to decompress $sFile.gz for center $sCenter.", '!!');
                    die($Settings->get('error_codes|EXIT_ERROR_INPUT_UNREADABLE'));
                }
                $Log->add("Decompressed $sFile.gz for center $sCenter.", 'OK');
            }
            $Status->set("data_files|$sFile", $sCenter);
        }
    }

    // Make sure we disconnect everywhere.
    foreach ($aSSHConnections as $sHost => $SSH) {
        $SSH->disconnect();
        unset($aSSHConnections[$sHost]);
    }

    // If we don't have all files, complain.
    if ($aFilesMissing) {
        $Log->add("One or more data files are missing:\n" . implode("\n", $aFilesMissing), '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_NOT_A_FILE'));
    }

    // Finally, log that we're done and continue.
    $Log->add('All files are present, ready for the next step.', 'OK');
    $Status->set('step', $nStep);
}





// Step 2: Merge all files into one, regardless of the given format.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Use the formatter which recognizes all formats and merges everything into one tab-delimited file.
    $Log->add('Parsing the VKGL data files...');
    try {
        $o = Formatter::format($Status->get('data_files'));
    } catch (Exception $e) {
        $Log->add("Failed to parse the data files.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CONTENT_ERROR'));
    }

    $sOutputFile = 'vkgl_data.01-raw.' . ($bTesting? '' : date('Y-m-d.H.i.s.')) . 'tsv';
    if (!$o->save($sOutputFile)) {
        $Log->add("Failed to save the result to $sOutputFile.", '!!');
        die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
    }
    $Log->add("Successfully created $sOutputFile.", 'OK');
    $Status->set('output_files|formatted', $sOutputFile);

    if ($o->hasErrors()) {
        $sErrorOutputFile = str_replace('.tsv', '.errors.tsv', $sOutputFile);
        if (!$o->saveErrors($sErrorOutputFile)) {
            $Log->add("Failed to save the errors to $sErrorOutputFile.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
        }
        $Log->add("Errors occurred, successfully created $sErrorOutputFile.", 'OK');
        $Status->set('error_files|formatted', $sErrorOutputFile);
    }

    // Fetch the statistics generated by the formatter and store them (these contain errors only).
    $Status->set('statistics|formatting', $o->getStatistics());

    $Status->set('step', $nStep);
}





// Step 3: Normalize all input to fully valid HGVS descriptions, also pulling in other data.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Use the normalizer to convert all VCFs and other notations into HGVS and normalize, liftover, and map all data.
    $Log->add('Normalizing the data... (this may take hours or days if there is a lot of new data)');
    try {
        $o = Normalizer::normalize($Status->get('output_files|formatted'), $Log, ($Settings->get() ?? []));
    } catch (Exception $e) {
        echo "\n"; // Clear the line as the normalizer has output of its own.
        $Log->add("Failed to normalize the data.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CONTENT_ERROR'));
    }

    $sOutputFile = 'vkgl_data.02-normalized.' . ($bTesting? '' : date('Y-m-d.H.i.s.')) . 'tsv';
    if (!$o->save($sOutputFile)) {
        $Log->add("Failed to save the result to $sOutputFile.", '!!');
        die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
    }
    $Log->add("Successfully created $sOutputFile.", 'OK');
    $Status->set('output_files|normalized', $sOutputFile);

    if ($o->hasErrors()) {
        $sErrorOutputFile = str_replace('.tsv', '.errors.tsv', $sOutputFile);
        if (!$o->saveErrors($sErrorOutputFile)) {
            $Log->add("Failed to save the errors to $sErrorOutputFile.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
        }
        $Log->add("Errors occurred, successfully created $sErrorOutputFile.", 'OK');
        $Status->set('error_files|normalized', $sErrorOutputFile);
    }

    // Fetch the statistics generated by the normalizer and store them.
    $Status->set('statistics|normalizing', $o->getStatistics());

    $Status->set('step', $nStep);
}





// Step 4: Aggregate all variants.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Aggregate all variants per center, and compare the classifications between centers.
    $Log->add('Aggregating the normalized variants...');
    try {
        $o = Aggregator::aggregate($Status->get('output_files|normalized'));
    } catch (Exception $e) {
        $Log->add("Failed to aggregate the data.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CONTENT_ERROR'));
    }

    $sOutputFile = 'vkgl_data.03-aggregated.' . ($bTesting? '' : date('Y-m-d.H.i.s.')) . 'tsv';
    if (!$o->save($sOutputFile)) {
        $Log->add("Failed to save the result to $sOutputFile.", '!!');
        die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
    }
    $Log->add("Successfully created $sOutputFile.", 'OK');
    $Status->set('output_files|aggregated', $sOutputFile);

    if ($o->hasErrors()) {
        $sErrorOutputFile = str_replace('.tsv', '.errors.tsv', $sOutputFile);
        if (!$o->saveErrors($sErrorOutputFile)) {
            $Log->add("Failed to save the errors to $sErrorOutputFile.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
        }
        $Log->add("Errors occurred, successfully created $sErrorOutputFile.", 'OK');
        $Status->set('error_files|aggregated', $sErrorOutputFile);
    }

    $Status->set('step', $nStep);
}





// Step 5: Validate the aggregated output.
$nStep ++;
if ($Status->get('step') < $nStep) {
    // Compare the aggregated data file with the file from the previous release and validate the resulting diff.
    $Log->add('Validating the diff with the previous release...');

    // First, we'll need to determine where to find that previous file.
    $nPreviousReleaseYear = $nReleaseYear;
    if ($nReleaseMonth == $aMonths[array_key_last($aMonths)]) {
        // If the current release month is the first release of the year, the last
        //  release must be set to the last release of the last year.
        $nPreviousReleaseMonth = $aMonths[0];
        $nPreviousReleaseYear --;
    } else {
        $nPreviousReleaseMonth = $aMonths[array_search($nReleaseMonth, $aMonths) + 1];
    }

    // Load the previous release's status.json. Note that the Settings class will try to create the file if it doesn't
    //  exist; it won't complain unless the directory also doesn't exist.
    define('PREVIOUS_RELEASE_PATH', dirname(RELEASE_PATH) . '/' . $nPreviousReleaseYear . '-' . str_pad($nPreviousReleaseMonth, 2, '0', STR_PAD_LEFT));
    $sPreviousStatus = PREVIOUS_RELEASE_PATH . '/status.json';
    if (!file_exists($sPreviousStatus) || !is_readable($sPreviousStatus)) {
        // Handle this kindly instead of throwing a hard exception.
        $Log->add("Failed to find the previous release's status file: $sPreviousStatus.\nHas the directory been moved?", '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_UNREADABLE'));
    }
    $PreviousStatus = new Settings(PREVIOUS_RELEASE_PATH . '/status.json');

    // Now call the validator and pass on the files that need validation (i.e., their contents are compared).
    try {
        $o = Validator::validate(PREVIOUS_RELEASE_PATH . '/' . $PreviousStatus->get('output_files|aggregated'), $Status->get('output_files|aggregated'), ($Settings->get('validation_cutoffs|aggregated') ?? []));
    } catch (Exception $e) {
        $Log->add("Failed to validate the aggregated output.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CONTENT_ERROR'));
    }

    // Fetch the statistics generated by the validator and store them.
    $aStatistics = $o->getStatistics();

    // Merge the error counts.
    // We can't just use an array_merge() because centers may have both formatting and normalizing errors.
    // However, an array_merge_recursive() may create arrays, which we then need to reduce to integers.
    $aStatistics['errors'] = array_map(
        function ($Value)
        {
            if (!is_array($Value)) {
                return $Value;
            } else {
                return array_sum($Value);
            }
        },
        array_merge_recursive(
            (array) $Status->get('statistics|formatting|errors'),
            (array) $Status->get('statistics|normalizing|errors'),
        )
    );
    $Statistics->set($sRelease, $aStatistics);

    // Finally, log that we're done and continue.
    $Log->add('Successfully validated the aggregated output and stored the statistics.', 'OK');
    $Status->set('step', $nStep);
}





// Step 6: Process the data and import it into the local LOVD instance.
$nStep ++;
if ($Status->get('step') < $nStep) {
    if (!$Settings->get('lovd_path')) {
        $Log->add("Can't find lovd_path in the settings. Please configure it first.", '!!');
        die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_CONTENT_ERROR'));
    }

    $Log->add('Processing the aggregated data...');
    try {
        $o = Processor::process($Status->get('output_files|aggregated'), $Settings, $Log);
    } catch (Exception $e) {
        $Log->add("Failed to process the aggregated data file.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CONTENT_ERROR'));
    }

    $sErrorOutputFile = ($bTesting? 'vkgl_data.04-processed.errors.tsv' : 'vkgl_data.04-processed.' . date('Y-m-d.H.i.s')) . '.errors.tsv';
    if ($o->hasErrors()) {
        if (!$o->saveErrors($sErrorOutputFile)) {
            $Log->add("Failed to save the errors to $sErrorOutputFile.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
        }
        $Log->add("Errors occurred, successfully created $sErrorOutputFile.", 'OK');
        $Status->set('error_files|processed', $sErrorOutputFile);
    }

    $aStatistics = $Statistics->get("$sRelease|diff");
    $aProcessorStatistics = $o->getStatistics();
    // Checking to see if the statistics of the validator step are on the remote server.
    if ($aStatistics) {
        $aCommonKeys = array_intersect_key($aStatistics, $aProcessorStatistics);
        // $aCommonKeys has the values of $aStatistics.
        $nMisMatch = 0;
        $sShowExpectations = "results: Expected Reality\n";
        foreach ($aCommonKeys as $Key => $Value) {
            $sShowExpectations .= $Key . ": " . $aCommonKeys[$Key] . "\t " . $aProcessorStatistics[$Key] . "\n";
            if ($aCommonKeys[$Key] != $aProcessorStatistics[$Key]) {
                $nMisMatch ++;
            }
        }
        $Log->add($sShowExpectations);
        if ($nMisMatch > 0) {
            $Log->add("The statistics don't match.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CONTENT_ERROR'));
        }
    }
    $Status->set('step', $nStep);
}





// Step 7: Connecting and processing data on remote server.
$nStep ++;
$aSSHConnections = [];

if ($Status->get('step') < $nStep) {
    $Log->add("Remote server");
    foreach (($Settings->get("destinations") ?: []) as $nKey => $aLocation) {
        $sHost = $aLocation["server"];
            $SSH = new SSH(
                $Settings->get("servers|{$sHost}|host"),
                $Settings->get("servers|{$sHost}|fingerprint")
            );
        $sRemotePath = $aLocation['path'];
        $Log->add("Creating release directory on remote server.");
        $SSH->execute("mkdir -p $sRelease", $sRemotePath);

        $sAggregatedFile = $Status->get("output_files|aggregated");
        $aUpload = array(
            RELEASE_PATH . "/status.json" => $sRemotePath . "/$sRelease/status.json",
            RELEASE_PATH . "/$sAggregatedFile" => $sRemotePath . "/$sRelease/$sAggregatedFile",
            __DIR__ . "/libs/HGVS-syntax-checker/cache/mapping.txt" => $sRemotePath . "/libs/HGVS-syntax-checker/cache/mapping.txt",
            __DIR__ . "/libs/HGVS-syntax-checker/cache/NC-variants.txt" => $sRemotePath . "/libs/HGVS-syntax-checker/cache/NC-variants.txt"
        );
        foreach ($aUpload as $sLocalFile => $sRemoteFile) {
            try {
                $sFileName = explode("/", $sLocalFile);
                $Log->add("Uploading " . end($sFileName) . " to remote server");
                $SSH->upload($sLocalFile, $sRemoteFile);
            } catch (Exception $e) {
                $Log->add($e->getMessage());
                die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
            }
        }

        $nLocalStep = $Status->get("step");
        $nRemoteStep = $Status->get("step")-1;
        $aExecute = array(
            'sed -i "s/step\": '.$nLocalStep.'/step\": '.$nRemoteStep.'/" status.json' => "Preparing remote status.json.",
            'git pull' => "Pulling the most recent pushed code.",
            '../run_pipeline.php' => "Running pipeline on remote server."
        );
        foreach ($aExecute as $sCommand => $sLogEntry) {
            try {
                $Log->add($sLogEntry);
                $SSH->execute($sCommand, $sRemotePath . "/$sRelease/");
            } catch (Exception $e) {
                $Log->add($e->getMessage());
                die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
            }
        }
    }
}