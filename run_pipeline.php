#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-23
 * Modified    : 2026-06-16
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

// New pipeline, running everything fully automated and reducing the time spent on manual verification even more.
define('ROOT_PATH', __DIR__);
require_once(ROOT_PATH . '/aggregator.php');
require_once(ROOT_PATH . '/formatter.php');
require_once(ROOT_PATH . '/validator.php');
require_once(ROOT_PATH . '/log.php');
require_once(ROOT_PATH . '/normalizer.php');
require_once(ROOT_PATH . '/settings.php');
use LOVD\VKGL\Aggregator;
use LOVD\VKGL\Formatter;
use LOVD\VKGL\Normalizer;
use LOVD\VKGL\Validator;
use LOVD\Log;
use LOVD\Settings;
$Settings = new Settings();

// All PHP scripts use these error codes; store them in the settings if they are missing.
// See http://tldp.org/LDP/abs/html/exitcodes.html for recommendations, in particular:
// "[I propose] restricting user-defined exit codes to the range 64 - 113 (...), to conform with the C/C++ standard."
foreach([
    'EXIT_OK' => 0,
    'EXIT_WARNINGS_OCCURRED' => 64,
    'EXIT_ERROR_ARGS_INSUFFICIENT' => 65,
    'EXIT_ERROR_ARGS_NOT_UNDERSTOOD' => 66,
    'EXIT_ERROR_INPUT_NOT_A_FILE' => 67,
    'EXIT_ERROR_INPUT_UNREADABLE' => 68,
    'EXIT_ERROR_INPUT_CANT_OPEN' => 69,
    'EXIT_ERROR_HEADER_FIELDS_NOT_FOUND' => 70,
    'EXIT_ERROR_HEADER_FIELDS_INCORRECT' => 71,
    'EXIT_ERROR_DATA_FIELD_COUNT_INCORRECT' => 72,
    'EXIT_ERROR_DATA_CONTENT_ERROR' => 73,
    'EXIT_ERROR_CACHE_CANT_CREATE' => 74,
    'EXIT_ERROR_CACHE_UNREADABLE' => 75,
    'EXIT_ERROR_CACHE_CANT_WRITE' => 76,
    'EXIT_ERROR_OUTPUT_CANT_CREATE' => 77,
    'EXIT_ERROR_CONNECTION_PROBLEM' => 78,
    'EXIT_ERROR_SETTINGS_PROBLEM' => 79,
] as $sErrorCode => $nErrorCode) {
    if ($Settings->get("error_codes|$sErrorCode") === null) {
        $Settings->set("error_codes|$sErrorCode", $nErrorCode);
    }
}

// Convert older settings to newer settings.
foreach ($Settings->get() as $sKey => $Value) {
    if (preg_match('/^center_([a-z_]+)_id$/', $sKey, $aRegs)) {
        // Old-style center settings. Convert into something new.
        $sCenter = $aRegs[1];
        if (!$Settings->get("centers|$sCenter|id")) {
            // Hasn't migrated yet.
            $Settings->set("centers|$sCenter|id", $Value);
        }
        // Delete it, we don't need this anymore.
        $Settings->delete($sKey);
    }
}

// Fix the timezone, if needed (PHP defaults to UTC).
if ($Settings->get('timezone')) {
    date_default_timezone_set($Settings->get('timezone'));
}



// Checking the server settings, and collecting passphrases when needed.
$aPassphrases = [];
foreach (($Settings->get('servers') ?? []) as $sServer => $aServer) {
    if (!empty($aServer['key']) && !isset($aPassphrases[$aServer['key']])) {
        $aPassphrases[$aServer['key']] = '';
        // Check the key file and check if it's encrypted.
        if (!file_exists($aServer['key']) || !is_readable($aServer['key'])) {
            print("SSH key configured for server $sServer not found or not readable.\n");
            die($Settings->get("error_codes|EXIT_ERROR_SETTINGS_PROBLEM"));
        }
        if (str_contains(file_get_contents($aServer['key']), 'ENCRYPTED')) {
            // Key is encrypted. Ask for the passphrase.
            while (true) {
                print("SSH key {$aServer['key']} is encrypted, please provide the passphrase:\n");
                // Disable echo, so the user can safely type the key without anyone seeing.
                system('stty -echo');
                $sInput = trim(fgets(STDIN));
                // Re-enable echo, so the user can use their terminal again.
                system('stty echo');

                // If we have a hash stored, compare.
                $sHash = password_hash($sInput, PASSWORD_DEFAULT);
                if (empty($aServer['passphrase_hash'])) {
                    $Settings->set("servers|{$sServer}|passphrase_hash", $sHash);
                    print("SSH key passphrase hash cached for future use.\n");
                    break;
                } elseif (!password_verify($sInput, $aServer['passphrase_hash'])) {
                    print("SSH key passphrase hash does not match — clear the cached hash or try again.\n");
                } else {
                    break;
                }
            }
            $aPassphrases[$aServer['key']] = $sInput;
        }
    }
}



// First, determine which release we're supposed to be working on.
$aMonths = $Settings->get('release_months');
if ($aMonths === null) {
    print("Can't find information in the settings about the release months. Please configure them first.\n\n");
    die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_PROBLEM'));
}

rsort($aMonths);
$nThisYear = date('Y');
$nThisMonth = date('m');
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
define('RELEASE_PATH', ROOT_PATH . '/' . $sRelease);
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
$Status = new Settings(RELEASE_PATH . '/status.json');

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
    $Status->set('step', 0);
}

// We'll need the statistics multiple times.
$sStatisticsFile = ROOT_PATH . '/statistics.json';
if (!file_exists($sStatisticsFile) || !is_writable($sStatisticsFile)) {
    // Handle this kindly instead of throwing a hard exception.
    $Log->add("General statistics file not found or not writable: $sStatisticsFile.", '!!');
    die($Settings->get('error_codes|EXIT_ERROR_OUTPUT_CANT_CREATE'));
}
$Statistics = new Settings($sStatisticsFile);





// Step 1: Check if we have all the files.
$nStep = 1;
if ($Status->get('step') < $nStep) {
    // Check if we have all the files.
    $Log->add("Checking if we have all the required files...");
    $aFilesMissing = [];
    foreach ($Settings->get('centers') as $sCenter => $aCenter) {
        if (empty($aCenter['files'])) {
            $Log->add("Center $sCenter doesn't have files configured; please define what files to expect, or remove the center.", '!!');
            die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_PROBLEM'));
        }

        // Loop the file settings, and check everything.
        foreach ($aCenter['files'] as $sOrigin => $sFile) {
            // Check if we have the file.
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

            $aFilesMissing[] = $sFile;
        }
    }

    // If we don't have all files, complain.
    if ($aFilesMissing) {
        $Log->add("One or more data files are missing:\n" . implode("\n", $aFilesMissing), '!!');
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_NOT_A_FILE'));
    }

    $Log->add("All files are present, ready for the next step.", 'OK');
    $Status->set('step', $nStep);
}





// Step 2: Merge all files into one, regardless of the given format.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Use the formatter which recognizes all formats and merges everything into one tab-delimited file.
    $Log->add("Parsing the VKGL data files...");
    try {
        $o = Formatter::format($Status->get('data_files'));
    } catch (Exception $e) {
        $Log->add("Failed to parse the data files.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
    }

    $sOutputFile = 'vkgl_data.01-raw.' . date('Y-m-d.H.i.s') . '.tsv';
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

    // Fetch the statistics generated by the formatter and store them.
    $Status->set('statistics|formatting', $o->getStatistics());

    $Status->set('step', $nStep);
}





// Step 3: Normalize all input to fully valid HGVS descriptions, also pulling in other data.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Use the normalizer to convert all VCFs and other notations into HGVS and normalize, liftover, and map all data.
    $Log->add("Normalizing the data... (this may take hours or days if there is a lot of new data)");
    try {
        $o = Normalizer::normalize($Status->get('output_files|formatted'), $Log, ($Settings->get('VV') ?? []));
    } catch (Exception $e) {
        echo "\n"; // Clear the line as the normalizer has output of its own.
        $Log->add("Failed to normalize the data.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
    }

    // WIP.
    exit;
    $Status->set('step', $nStep);
}





// Step 4: Aggregate all variants.
$nStep++;
if ($Status->get('step') < $nStep) {
    // Aggregate all variants per center, and compare the classifications between centers.
    $Log->add("Aggregating the normalized variants...");
    try {
        $o = Aggregator::aggregate($Status->get('output_files|normalized'));
    } catch (Exception $e) {
        $Log->add("Failed to aggregate the data.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
    }

    $sOutputFile = 'vkgl_data.03-aggregated.' . date('Y-m-d.H.i.s') . '.tsv';
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
    $Log->add("Validating the diff with the previous release...");

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
        die($Settings->get('error_codes|EXIT_ERROR_INPUT_CANT_OPEN'));
    }
    $PreviousStatus = new Settings(PREVIOUS_RELEASE_PATH . '/status.json');

    // Now call the validator and pass on the files that need validation (i.e., their contents are compared).
    try {
        $o = Validator::validate(PREVIOUS_RELEASE_PATH . '/' . $PreviousStatus->get('output_files|aggregated'), $Status->get('output_files|aggregated'), ($Settings->get('validation_cutoffs|aggregated') ?? []));
    } catch (Exception $e) {
        $Log->add("Failed to validate the aggregated output.\n" . $e->getMessage() . '.', '!!');
        die($Settings->get('error_codes|EXIT_ERROR_DATA_CONTENT_ERROR'));
    }

    // Fetch the statistics generated by the validator and store them.
    $aStatistics = array_merge(
        $Statistics->get($sRelease),
        $aStatistics = $o->getStatistics()
    );
    $Statistics->set($sRelease, $aStatistics);

    // Finally, log that we're done and continue.
    $Log->add("Successfully validated the aggregated output.", 'OK');
    $Status->set('step', $nStep);
}