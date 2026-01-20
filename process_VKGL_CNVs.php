#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2025-11-06
 * Modified    : 2025-11-06
 * Version     : 0.1
 *
 * Purpose     : Processes the VKGL CNV data, and creates or updates the
 *               VKGL data in the LOVD instance.
 *
 * Copyright   : 2004-2025 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *
 * This file is part of LOVD.
 *
 * LOVD is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LOVD is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with LOVD.  If not, see <http://www.gnu.org/licenses/>.
 *
 *************/

// Command line only.
if (isset($_SERVER['HTTP_HOST'])) {
    die('Please run this script through the command line.' . "\n");
}

// We're already using ROOT_PATH to point to LOVD, so define CWD to point to the directory where this script resides.
define('CWD', dirname(__FILE__) . '/');

// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$_CONFIG = array(
    'name' => 'VKGL CNV data importer',
    'version' => '0.1',
    'settings_file' => CWD . 'settings.json',
    'flags' => array(
        'n' => false, // Dry run.
        'y' => false, // Yes; accept current settings and don't ask anything.
    ),
    'columns_mandatory' => array(
        // These are the columns that need to be present in order for the file to get processed.
        'dna',
    ),
    'columns_ignore' => array(),
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
    'effect_mapping_LOVD' => array(
        'B' => 1,
        'LB' => 3,
        'VUS' => 5,
        'LP' => 7,
        'P' => 9,
    ),
    'effect_mapping_classification' => array(
        'B' => 'benign',
        'LB' => 'likely benign',
        'VUS' => 'VUS',
        'LP' => 'likely pathogenic',
        'P' => 'pathogenic',
    ),
    'user' => array(
        // Variables we will be asking the user.
        'refseq_build' => 'hg19',
        'lovd_path' => '/www/databases.lovd.nl/shared/',
        'vkgl_generic_id' => 0, // The LOVD ID of the generic VKGL account, needed for single lab submissions.
        'public_singlelab_owners' => 'y', // Should single-lab submissions get a public owner?
        'delete_redundant_variants' => 'n', // Should we remove variants in LOVD no longer in the dataset?
    ),
);

// Exit codes.
// See http://tldp.org/LDP/abs/html/exitcodes.html for recommendations, in particular:
// "[I propose] restricting user-defined exit codes to the range 64 - 113 (...), to conform with the C/C++ standard."
define('EXIT_OK', 0);
define('EXIT_WARNINGS_OCCURRED', 64);
define('EXIT_ERROR_ARGS_INSUFFICIENT', 65);
define('EXIT_ERROR_ARGS_NOT_UNDERSTOOD', 66);
define('EXIT_ERROR_INPUT_NOT_A_FILE', 67);
define('EXIT_ERROR_INPUT_UNREADABLE', 68);
define('EXIT_ERROR_INPUT_CANT_OPEN', 69);
define('EXIT_ERROR_HEADER_FIELDS_NOT_FOUND', 70);
define('EXIT_ERROR_HEADER_FIELDS_INCORRECT', 71);
define('EXIT_ERROR_SETTINGS_CANT_CREATE', 72);
define('EXIT_ERROR_SETTINGS_UNREADABLE', 73);
define('EXIT_ERROR_SETTINGS_CANT_UPDATE', 74);
define('EXIT_ERROR_SETTINGS_INCORRECT', 75);
define('EXIT_ERROR_CONNECTION_PROBLEM', 76);
define('EXIT_ERROR_CACHE_CANT_CREATE', 77);
define('EXIT_ERROR_CACHE_UNREADABLE', 78);
define('EXIT_ERROR_CACHE_CANT_UPDATE', 79);
define('EXIT_ERROR_DATA_FIELD_COUNT_INCORRECT', 80);
define('EXIT_ERROR_DATA_CONTENT_ERROR', 81);

define('VERBOSITY_NONE', 0); // No output whatsoever.
define('VERBOSITY_LOW', 3); // Low output, only the really important messages.
define('VERBOSITY_MEDIUM', 5); // Medium output. No output if there is nothing to do. Useful for when using cron.
define('VERBOSITY_HIGH', 7); // High output. The default.
define('VERBOSITY_FULL', 9); // Full output, including debug statements.





function lovd_printIfVerbose ($nVerbosity, $sMessage)
{
    // This function only prints the given message when the current verbosity is set to a level high enough.

    // If no verbosity is currently defined, just print everything.
    if (!defined('VERBOSITY')) {
        define('VERBOSITY', 9);
    }

    if (VERBOSITY >= $nVerbosity) {
        print($sMessage);
    }
    return true;
}





function lovd_saveSettings ($bHaltOnError = true)
{
    // Saves the settings we currently have to the JSON file.
    global $_CONFIG;

    if (!file_put_contents($_CONFIG['settings_file'], json_encode($_CONFIG['user'], JSON_PRETTY_PRINT))) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Could not save settings.' . "\n\n");
        if ($bHaltOnError) {
            die(EXIT_ERROR_SETTINGS_CANT_UPDATE);
        } else {
            return false;
        }
    }
    return true;
}





function lovd_verifySettings ($sKeyName, $sMessage, $sVerifyType, $options)
{
    // Based on a function provided by Ileos.nl in the interest of Open Source.
    // Check if settings match certain input.
    global $_CONFIG;

    switch($sVerifyType) {
        case 'array':
            $aOptions = $options;
            if (!is_array($aOptions)) {
                return false;
            }
            break;

        case 'int':
            // Integer, options define a range in the format '1,3' (1 to 3) or '1,' (1 or higher).
            $aRange = explode(',', $options);
            if (!is_array($aRange) ||
                ($aRange[0] === '' && $aRange[1] === '') ||
                ($aRange[0] !== '' && !ctype_digit($aRange[0])) ||
                ($aRange[1] !== '' && !ctype_digit($aRange[1]))) {
                return false;
            }
            break;
    }

    while (true) {
        print('  ' . $sMessage .
            ($sVerifyType != 'int' || ($aRange === array('', ''))? '' : ' (' . (int) $aRange[0] . '-' . $aRange[1] . ')') .
            (empty($_CONFIG['user'][$sKeyName])? '' : ' [' . $_CONFIG['user'][$sKeyName] . ']') . ' : ');
        $sInput = trim(fgets(STDIN));
        if (!strlen($sInput) && !empty($_CONFIG['user'][$sKeyName])) {
            $sInput = $_CONFIG['user'][$sKeyName];
        }

        switch ($sVerifyType) {
            case 'array':
                $sInput = strtolower($sInput);
                if (in_array($sInput, $aOptions)) {
                    $_CONFIG['user'][$sKeyName] = $sInput;
                    return true;
                }
                break;

            case 'int':
                $sInput = (int) $sInput;
                // Check if input is lower than minimum required value (if configured).
                if ($aRange[0] !== '' && $sInput < $aRange[0]) {
                    break;
                }
                // Check if input is higher than maximum required value (if configured).
                if ($aRange[1] !== '' && $sInput > $aRange[1]) {
                    break;
                }
                $_CONFIG['user'][$sKeyName] = $sInput;
                return true;

            case 'string':
                $_CONFIG['user'][$sKeyName] = $sInput;
                return true;

            case 'file':
            case 'lovd_path':
            case 'path':
                // Always accept the default (if non-empty) or the given options.
                if (($sInput && ($sInput == $_CONFIG['user'][$sKeyName] ||
                        $sInput === $options)) ||
                    (is_array($options) && in_array($sInput, $options))) {
                    $_CONFIG['user'][$sKeyName] = $sInput; // In case an option was chosen that was not the default.
                    return true;
                }
                if (in_array($sVerifyType, array('lovd_path', 'path')) && !is_dir($sInput)) {
                    print('    Given path is not a directory.' . "\n");
                    break;
                } elseif (!is_readable($sInput)) {
                    print('    Cannot read given path.' . "\n");
                    break;
                }

                if ($sVerifyType == 'lovd_path') {
                    if (!file_exists($sInput . '/config.ini.php')) {
                        if (file_exists($sInput . '/src/config.ini.php')) {
                            $sInput .= '/src';
                        } else {
                            print('    Cannot locate config.ini.php in given path.' . "\n" .
                                  '    Please check that the given path is a correct path to an LOVD installation.' . "\n");
                            break;
                        }
                    }
                    if (!is_readable($sInput . '/config.ini.php')) {
                        print('    Cannot read configuration file in given LOVD directory.' . "\n");
                        break;
                    }
                    // We'll set everything up later, because we don't want to
                    // keep the $_DB open for as long as the user is answering questions.
                }
                $_CONFIG['user'][$sKeyName] = $sInput;
                return true;

            default:
                return false;
        }
    }

    return false; // We'd actually never get here.
}





// Parse command line options.
$aArgs = $_SERVER['argv'];
$nArgs = $_SERVER['argc'];
// We need at least one argument, the file to convert.
$nArgsRequired = 1;

$sScriptName = array_shift($aArgs);
$nArgs --;
$nWarningsOccurred = 0;

if ($nArgs < $nArgsRequired) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n" .
        'Usage: ' . $sScriptName . ' file_to_import.tsv [-y]' . "\n\n");
    die(EXIT_ERROR_ARGS_INSUFFICIENT);
}

// First argument should be the file to convert.
$sFile = array_shift($aArgs);
$nArgs --;

while ($nArgs) {
    // Check for flags.
    $sArg = array_shift($aArgs);
    $nArgs --;
    if (preg_match('/^-[A-Z]+$/i', $sArg)) {
        $sArg = substr($sArg, 1);
        foreach (str_split($sArg) as $sFlag) {
            if (isset($_CONFIG['flags'][$sFlag])) {
                $_CONFIG['flags'][$sFlag] = true;
            } else {
                // Flag not recognized.
                lovd_printIfVerbose(VERBOSITY_LOW,
                    'Error: Flag -' . $sFlag . ' not understood.' . "\n\n");
                die(EXIT_ERROR_ARGS_NOT_UNDERSTOOD);
            }
        }
    }
}
$bCron = (empty($_SERVER['REMOTE_ADDR']) && empty($_SERVER['TERM']));
define('VERBOSITY', ($bCron? 5 : 7));
// Record the start of the script, but correct for the timezone. This way, (time() - $tStart) doesn't seem to make sense
//  to us human readers, but when used in combination with date('H:i:s', ...) to format hours, minutes, and seconds
//  spent, it all makes sense. Note that date("H:i:s", 0) only returns 00:00:00 when your timezone is GMT.
$tStart = time() + date('Z', 0);

// Configure dry run.
$bDebug = !empty($_CONFIG['flags']['n']);

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n" .
    (!$bDebug? '' : '  Dry run enabled, not running any database updates.' . "\n"));





// Check file passed as an argument.
if (!file_exists($sFile) || !is_file($sFile)) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Input is not a file.' . "\n\n");
    die(EXIT_ERROR_INPUT_NOT_A_FILE);
}
if (!is_readable($sFile)) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Unreadable input file.' . "\n\n");
    die(EXIT_ERROR_INPUT_UNREADABLE);
}



// Check headers. Isolate the center names, so we can ask the user about them.
$aHeaders = array();
$nHeaders = 0;
$nLine = 0;
$fInput = fopen($sFile, 'r');
if ($fInput === false) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Can not open file.' . "\n\n");
    die(EXIT_ERROR_INPUT_CANT_OPEN);
}

while ($sLine = fgets($fInput)) {
    $nLine++;
    $sLine = strtolower(trim($sLine));
    if (!$sLine) {
        continue;
    }

    // First line should be headers.
    $aHeaders = explode("\t", $sLine);
    $nHeaders = count($aHeaders);

    // Check for mandatory headers.
    $aHeadersMissing = array();
    foreach ($_CONFIG['columns_mandatory'] as $sColumn) {
        if (!in_array($sColumn, $aHeaders, true)) {
            $aHeadersMissing[] = $sColumn;
        }
    }
    if ($aHeadersMissing) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File does not conform to format; missing column' . (count($aHeadersMissing) == 1? '' : 's') . ': ' . implode(', ', $aHeadersMissing) . ".\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_INCORRECT);
    }
    break;
}

if (!$aHeaders) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: File does not conform to format; can not find headers.' . "\n\n");
    die(EXIT_ERROR_HEADER_FIELDS_NOT_FOUND);
}





// Now we have the headers, and all required ones are there.
// Parse the rest, ignore everything we don't care about, assume the rest must be centra.
// Verify these and store.
$aCentersFound = array();
$nCentersFound = 0;
$aHeadersSorted = array_diff($aHeaders, $_CONFIG['columns_mandatory'], $_CONFIG['columns_ignore']);
sort($aHeadersSorted); // This makes it easier to find the centra and their *_link column.
foreach ($aHeadersSorted as $sHeader) {
    // Are we a center name?
    if (in_array($sHeader . $_CONFIG['columns_center_suffix'], $aHeadersSorted)) {
        // Yes, this is a center. Its *_link column is present.
        $aCentersFound[] = $sHeader;
        $nCentersFound ++;
        $_CONFIG['user']['center_' . $sHeader . '_id'] = 0;
    } elseif (in_array(str_replace($_CONFIG['columns_center_suffix'], '', $sHeader), $aCentersFound)) {
        // This is a center's *_link column.
        continue;
    } else {
        // Column not recognized. Better warn, in case we're missing something.
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File header contains unrecognized column: ' . $sHeader . ".\n" .
            'In case you would like to ignore this column, please add it to the columns_ignore list.' . "\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_INCORRECT);
    }
}





// Get settings file, if it exists.
$_SETT = array();
if (!file_exists($_CONFIG['settings_file'])) {
    if (!touch($_CONFIG['settings_file'])) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Could not create settings file.' . "\n\n");
        die(EXIT_ERROR_SETTINGS_CANT_CREATE);
    }
} elseif (!is_file($_CONFIG['settings_file']) || !is_readable($_CONFIG['settings_file'])
    || !($_SETT = json_decode(file_get_contents($_CONFIG['settings_file']), true))) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Unreadable settings file.' . "\n\n");
    die(EXIT_ERROR_SETTINGS_UNREADABLE);
}

// The settings file always replaces the standard defaults.
$_CONFIG['user'] = array_merge($_CONFIG['user'], $_SETT);



// User may have requested to continue without verifying the settings, but we may not have them all.
// If at least one setting evaluates to "false", we will ask anyway.
if ($_CONFIG['flags']['y']) {
    foreach ($_CONFIG['user'] as $Value) {
        if (!$Value) {
            $_CONFIG['flags']['y'] = false;
            break;
        }
    }
}





// Verify all the settings, if needed.
$aCenterIDs = array();
if (!$_CONFIG['flags']['y']) {
    lovd_verifySettings('refseq_build', 'The genome build that the data file uses (hg19/hg38)', 'array', array('hg19', 'hg38'));
    if (!lovd_verifySettings('lovd_path', 'Path of LOVD installation to load data into', 'lovd_path', '')) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Failed to get LOVD path.' . "\n\n");
        die(EXIT_ERROR_CONNECTION_PROBLEM);
    }
    lovd_verifySettings('vkgl_generic_id', 'The LOVD user ID for the generic VKGL account', 'int', '1,99999');

    // Verify all centra.
    $aCenterIDs = array(); // Make sure IDs are unique.
    foreach ($aCentersFound as $sCenter) {
        while (true) {
            lovd_verifySettings('center_' . $sCenter . '_id', 'The LOVD user ID for VKGL center ' . $sCenter, 'int', '1,99999');
            if (in_array($_CONFIG['user']['center_' . $sCenter . '_id'], $aCenterIDs)) {
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    '    This ID is already assigned to a different center.' . "\n");
                $_CONFIG['user']['center_' . $sCenter . '_id'] = 0;
            } else {
                $aCenterIDs[$sCenter] = $_CONFIG['user']['center_' . $sCenter . '_id'];
                break;
            }
        }
    }

    lovd_verifySettings('public_singlelab_owners', 'Should single-lab records be publically linked to the submitting laboratory? (y/n)', 'array', array('y', 'n'));

    // Delete LOVD variants no longer in the VKGL dataset? Should be left to "n" for all tests,
    //  otherwise incomplete VKGL files will result in lots of data marked for removal.
    // Note that this doesn't actually really remove these variants, it will hide them and mark them as removed.
    lovd_verifySettings('delete_redundant_variants', 'Do you want data no longer found in this input file removed from LOVD? (y/n)', 'array', array('y', 'n'));
}

// Save settings already, in case the connection breaks just below. Settings may be incorrect.
lovd_saveSettings();





// Open connection, and check if user accounts exist.
lovd_printIfVerbose(VERBOSITY_HIGH,
    '  Connecting to LOVD...');

// Find LOVD installation, run it's inc-init.php to get DB connection, initiate $_SETT, etc.
define('ROOT_PATH', $_CONFIG['user']['lovd_path'] . '/');
define('FORMAT_ALLOW_TEXTPLAIN', true);
$_GET['format'] = 'text/plain';
// To prevent notices when running inc-init.php.
$_SERVER = array_merge($_SERVER, array(
    'HTTP_HOST' => 'localhost',
    'REQUEST_URI' => '/' . basename(__FILE__),
    'QUERY_STRING' => '',
    'REQUEST_METHOD' => 'GET',
));
// If I put a requirement here, I can't nicely handle errors, because PHP will die if something is wrong.
// However, I need to get rid of the "headers already sent" warnings from inc-init.php.
// So, sadly if there is a problem connecting to LOVD, the script will die here without any output whatsoever.
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // CLI logs errors to the screen, apparently.
// Let the LOVD believe we're accessing it through SSL. LOVDs that demand this, will otherwise block us.
// We have error messages suppressed anyway, as the LOVD in question will complain when it tries to define "SSL" as well.
define('SSL', true);
require ROOT_PATH . 'inc-init.php';
require ROOT_PATH . 'inc-lib-form.php';
require ROOT_PATH . 'inc-lib-variants.php'; // For lovd_fixHGVS().
ini_set('display_errors', '1'); // We do want to see errors from here on.

lovd_printIfVerbose(VERBOSITY_HIGH,
    ' Connected!' . "\n\n");



// Check given refseq build.
$sRefSeqBuild = $_DB->q('SELECT refseq_build FROM ' . TABLE_CONFIG)->fetchColumn();
$bRefSeqBuildOK = ($_CONFIG['user']['refseq_build'] == $sRefSeqBuild);

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    'RefSeq build set to ' . $_CONFIG['user']['refseq_build'] .
    ($bRefSeqBuildOK? '.' : ', but LOVD uses ' . $sRefSeqBuild . '!!!') . "\n\n");

if (!$bRefSeqBuildOK) {
    $_CONFIG['user']['refseq_build'] = '';
}



// Check given user accounts.
// Get IDs. It is assumed that all numeric values in the user array are user IDs.
$aUserIDs = array_filter($_CONFIG['user'], function ($Val) { return (is_int($Val)); });
// Cast id to UNSIGNED to make sure our ints match.
$aUsers = $_DB->q('SELECT CAST(id AS UNSIGNED) AS id, name FROM ' . TABLE_USERS . ' WHERE id IN (?' . str_repeat(', ?', count($aUserIDs) - 1) . ') ORDER BY id',
    array_values($aUserIDs))->fetchAllCombine();

$bAccountsOK = true;
$lCenters = max(array_map('strlen', $aCentersFound));

// The generic VKGL account.
// If not found, reset the ID so it doesn't get saved.
$bFound = (isset($aUsers[$_CONFIG['user']['vkgl_generic_id']]));

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    'Generic' . str_pad(' VKGL ID', $lCenters, '.') . '... LOVD account #' .
    str_pad($_CONFIG['user']['vkgl_generic_id'], 5, '0', STR_PAD_LEFT) .
    (!$bFound? ' --- not found!!!' : ' "' . $aUsers[$_CONFIG['user']['vkgl_generic_id']] . '"') . "\n");

if (!$bFound) {
    $bAccountsOK = false;
    $_CONFIG['user']['vkgl_generic_id'] = 0;
} else {
    // str_pad() the ID, so we can match it with what's in the DB.
    $_CONFIG['user']['vkgl_generic_id'] = str_pad($_CONFIG['user']['vkgl_generic_id'], 5, '0', STR_PAD_LEFT);
}

// The other centra that we have collected from the input file.
foreach ($aCentersFound as $sCenter) {
    // If the user was changing settings, then print the center's name, and user name from LOVD.
    // If not found, reset the ID so it doesn't get saved.
    $bFound = (isset($aUsers[$_CONFIG['user']['center_' . $sCenter . '_id']]));

    lovd_printIfVerbose(VERBOSITY_MEDIUM,
        'Center ' . str_pad($sCenter, $lCenters, '.') . '... LOVD account #' .
        str_pad($_CONFIG['user']['center_' . $sCenter . '_id'], 5, '0', STR_PAD_LEFT) .
        (!$bFound? ' --- not found!!!' : ' "' . $aUsers[$_CONFIG['user']['center_' . $sCenter . '_id']] . '"') . "\n");

    if (!$bFound) {
        $bAccountsOK = false;
        $_CONFIG['user']['center_' . $sCenter . '_id'] = 0;
    } else {
        // We need it for querying the database later; also str_pad() the ID, so we can match it with what's in the DB.
        $aCenterIDs[$sCenter] = str_pad($_CONFIG['user']['center_' . $sCenter . '_id'], 5, '0', STR_PAD_LEFT);
    }
}
lovd_printIfVerbose(VERBOSITY_MEDIUM, "\n");

if (!$bRefSeqBuildOK || !$bAccountsOK) {
    // One of the settings is no good. Settings have been updated, save changes (but don't die if that doesn't work).
    lovd_saveSettings(false);

    // Now, die because of the incorrect settings.
    lovd_printIfVerbose(VERBOSITY_LOW,
        ($bRefSeqBuildOK? '' : 'Error: Failed to set RefSeq build.' . "\n") .
        ($bAccountsOK? '' : 'Error: Failed to get all LOVD user accounts.' . "\n") . "\n");
    die(EXIT_ERROR_SETTINGS_INCORRECT);
}

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    'Delete data from LOVD if no longer found in the input file: ' .
    ($_CONFIG['user']['delete_redundant_variants'] == 'y'? 'Yes' : 'No') . "\n\n");





// Start the parsing...
lovd_printIfVerbose(VERBOSITY_MEDIUM, "\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Parsing VKGL file...' . "\n");

// Read out all variants, with labels per center, and store cDNA annotation.
$aData = array();
$aColumnsToUse = array_merge($_CONFIG['columns_mandatory'], $aCentersFound);
while ($sLine = fgets($fInput)) {
    $nLine++;
    $sLine = trim($sLine);
    if (!$sLine) {
        continue;
    }

    $aDataLine = explode("\t", $sLine);
    // Trim quotes off of the data.
    $aDataLine = array_map(function($sData) {
        return trim($sData, '"');
    }, $aDataLine);
    $nDataColumns = count($aDataLine);
    if ($nHeaders > $nDataColumns) {
        // We accidentally trimmed off empty fields.
        $aDataLine = array_pad($aDataLine, $nHeaders, '');
    } elseif ($nHeaders < $nDataColumns) {
        // Eh? More data received than headers.
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Data line ' . $nLine . ' has ' . count($aDataLine) . ' columns instead of the expected ' . $nHeaders . ".\n\n");
        die(EXIT_ERROR_DATA_FIELD_COUNT_INCORRECT);
    }

    $aDataLine = array_combine($aHeaders, $aDataLine);

    // Store data.
    $aData[] = array_intersect_key($aDataLine, array_flip($aColumnsToUse));
}

$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] VKGL file successfully parsed, found ' . $nVariants . ' variants.' . "\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Verifying genomic variants...' . "\n");

// We might be running for some time.
set_time_limit(0);





// Correct all genomic variants. Skip substitutions.
$nVariantsLost = 0;
$nVariantsDone = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.
foreach ($aData as $nKey => $aVariant) {
    // Translate all classification values to easier values.
    // I need this cleaned up here already, so I can report which centra cause problems.
    $aVariant['classifications'] = array();
    foreach ($aCentersFound as $sCenter) {
        if ($aVariant[$sCenter]) {
            $aVariant['classifications'][$sCenter] = str_replace(array('likely ', 'benign', 'pathogenic', 'vus'),
                array('L', 'B', 'P', 'VUS'), strtolower($aVariant[$sCenter]));
            //FIXME: LUMC and Radboud data have allele information but it's unknown if other centers include this too.
        }
        unset($aVariant[$sCenter]);
    }

    // Store corrected variant description.
    $aVariant['VariantOnGenome/DNA'] = $aVariant['dna'];
    //Here every line is still seperated, they aren't merged yet
    // Store new information, dropping some excess information.
    //dna is not needed anymore.
    unset($aVariant['dna']);
    $aData[$nKey] = $aVariant;

    // Print update, for every percentage changed.
    $nVariantsDone ++;
    if ((microtime(true) - $tProgressReported) > 5 && $nVariantsDone != $nVariants
        && floor($nVariantsDone * 1000 / $nVariants) != $nPercentageComplete) {
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] ' .
            str_pad($nVariantsDone, strlen($nVariants), ' ', STR_PAD_LEFT) . ' genomic variants verified...' . "\n");
        $tProgressReported = microtime(true); // Don't report again for a certain amount of time.
    }
}

// Last message.
$nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
        5, ' ', STR_PAD_LEFT) . '%] ' .
    $nVariantsDone . ' genomic variants verified.' . "\n" .
    '                   Variants lost: ' . $nVariantsLost . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Merging variants after corrections...' . "\n");





// Loop variants again, merging entries.
$nVariantsMerged = 0;
foreach ($aData as $nKey => $aVariant) {
    // Simple merge.
    if (!isset($aData[$aVariant['VariantOnGenome/DNA']])) {
        $aData[$aVariant['VariantOnGenome/DNA']] = $aVariant;
    } else {
        // Variant has already been seen before.
        $aData[$aVariant['VariantOnGenome/DNA']] = array_merge_recursive($aData[$aVariant['VariantOnGenome/DNA']], $aVariant);
        $nVariantsMerged ++;
    }
    // Get rid of the old data.
    unset($aData[$nKey]);
}

$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] ' . $nVariantsMerged . ' variants merged. Variants left: ' . $nVariants . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Determining consensus classifications...' . "\n");





// Loop variants again, fixing multiple classifications from the same center (report opposites, */VUS to VUS,
// LB/B to LB, LP/P to LP), and determining overall consensus (opposite, non-consensus, consensus, single-lab).
$nVariantsDone = 0;
$aStatusCounts = array(
    'single-lab' => 0,
    'consensus' => 0,
    'non-consensus' => 0,
    'opposite' => 0,
);
foreach ($aData as $sVariant => $aVariant) {
    // Per center, first make sure we only have one classification left per variant.
    //De double ones get removed later
    $bInternalConflict = false;
    foreach ($aVariant['classifications'] as $sCenter => $Classification) {
        if (is_array($Classification)) {
            // This center has multiple classifications for this variant.
            // Flipping the array makes the values unique and makes it easier to work with the values;
            //  isset()s are faster than array_search() and in_array().
            $aClassifications = array_flip($Classification);
            if (count($aClassifications) > 1) {
                // We have seen multiple classifications of this gene.
                // Rules: report opposites; */VUS to VUS; LB/B to LB; LP/P to LP.
                if ((isset($aClassifications['B']) || isset($aClassifications['LB']))
                    && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
                    // Internal conflict within center. These are reported in the opposites file.
                    lovd_printIfVerbose(VERBOSITY_MEDIUM,
                        ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                        floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                        5, ' ', STR_PAD_LEFT) .
                        '%] Warning: Internal conflict in center ' . $sCenter . ': ' . implode(', ', array_keys($aClassifications)) . ".\n");
                    // Reduce to one string, we want to store the conflict to report this in LOVD in a non-public entry.
                    $aClassifications = array(implode(',', array_keys($aClassifications)) => 1);
                    $bInternalConflict = true; // This'll make the consensus code a lot cleaner.

                } elseif (isset($aClassifications['VUS'])) {
                    // VUS and something else, not a conflict. OK, VUS then.
                    $aClassifications = array('VUS' => 1); // Remove the other classification(s).

                } else {
                    // Still multiple values. LB/B to LB, LP/P to LP.
                    if (isset($aClassifications['B']) && isset($aClassifications['LB'])) {
                        unset($aClassifications['B']);
                    }
                    if (isset($aClassifications['P']) && isset($aClassifications['LP'])) {
                        unset($aClassifications['P']);
                    }
                }

                if (count($aClassifications) > 1) {
                    // How can this be?
                    //This is to check if $aClassification only has 1 value, we shouldn't get here.
                    //This is a failsave.
                    lovd_printIfVerbose(VERBOSITY_MEDIUM,
                        ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                        floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                        5, ' ', STR_PAD_LEFT) .
                        '%] Warning: Failed to resolve classification string for center ' . $sCenter . ': ' . implode(', ', $Classification) . ".\n");
                }
            }

            // Store string value.
            $aVariant['classifications'][$sCenter] = key($aClassifications); // Should of course have one value.
        }
    }



    // Determine consensus (opposite, non-consensus, consensus, single-lab).
    $aVariant['status'] = '';
    //First we're going to check if we're dealing with 1 center or more
    //Here is checked if there are different conclusions in 1 center, that means that within 1 center
    //different conclusions were drawn and forms a conflict
    if ($bInternalConflict) {
        // One center had a conflict, so we all have a conflict.
        $aVariant['status'] = 'opposite';
        $aStatusCounts['opposite'] ++;
    //We get here if there's no conflict within a center.
    } elseif (count($aVariant['classifications']) == 1) {
        $aVariant['status'] = 'single-lab';
        $aStatusCounts['single-lab'] ++;
    //We get here if there are multiple centra
    } else {
        // We should have clean, one-classification values.
        // Handle it similarly as we did within the labs. Take unique values only and look at the combos.

        // Flipping the array makes the values unique and makes it easier to work with the values
        // (isset()s are faster than array_search() and in_array()).
        $aClassifications = array_flip($aVariant['classifications']);
        //If the classifications align, we get here
        if (count($aClassifications) == 1) {
            // One unique value, everybody agrees.
            $aVariant['status'] = 'consensus';
            $aStatusCounts['consensus'] ++;

            //We get here if the classifications are different between centra
        } elseif ((isset($aClassifications['B']) || isset($aClassifications['LB']))
            && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
            // Opposite.
            $aVariant['status'] = 'opposite';
            $aStatusCounts['opposite'] ++;
            //VUS
        } elseif (isset($aClassifications['VUS'])) {
            // VUS and something else, not a conflict, but no consensus either.
            $aVariant['status'] = 'non-consensus';
            $aStatusCounts['non-consensus'] ++;
            //likely or not
        } else {
            // Rest is consensus (possible LP/P or LB/B differences are ignored).
            $aVariant['status'] = 'consensus';
            $aStatusCounts['consensus'] ++;
        }
    }



    // Do some cleaning up.
    if (is_array($aVariant['VariantOnGenome/DNA'])) {
        // Multiple variants have been merged, but much information is duplicated.

        // Chromosome can't really be different.

        // We can get case-differences here, and I don't like that. array_unique() however, is case-sensitive.
        // This trick solves that problem.
        // https://stackoverflow.com/questions/2276349/case-insensitive-array-unique

        // VariantOnGenome/DNA, we grouped on this, so the other values are removed.
        //This is because the array get's transformed into a simple string.
        $aVariant['VariantOnGenome/DNA'] = current($aVariant['VariantOnGenome/DNA']);
    }

    // Report opposites.
    if ($aVariant['status'] == 'opposite') {
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                    floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                5, ' ', STR_PAD_LEFT) .
            '%] Conflict: ' . implode(', ', array_map(function ($key, $val) { return $key . ': ' . $val; }, array_keys($aVariant['classifications']), $aVariant['classifications'])) . ".\n" .
            '                   DNA: ' . $aVariant['VariantOnGenome/DNA'] . "\n");
        // Also report in a structured manner which we can extract from the output to report.
        $sReport = '{Conflict|' . $aVariant['VariantOnGenome/DNA'];
        foreach (array_keys($aCenterIDs) as $sCenter) {
            // This is called a "Null coalescing operator" (PHP7) and doesn't emit a notice.
            $sReport .= '|' . ($aVariant['classifications'][$sCenter] ?? '');
        }
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
                '                   ' . $sReport . "}\n");
    }

    $aData[$sVariant] = $aVariant;
    $nVariantsDone ++;
}
file_put_contents('output.' . time() . '.txt', print_r($aData, true));exit;

$lPadding = max(array_map('strlen', array_keys($aStatusCounts)));
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] Done.' . "\n" .
    implode("\n", array_map(
            function ($sKey, $nValue) {
                global $lPadding;
                return '                   ' . str_pad(ucfirst($sKey), $lPadding, ' ') . ' : ' . $nValue;
            }, array_keys($aStatusCounts), array_values($aStatusCounts))) . "\n" .
    '                   {ConflictHeader|# Variant (HGVS, normalized)|Gene(s)|' . implode('|', array_keys($aCenterIDs)) . "}\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Writing data file...' . "\n");





// Create a data file. We can keep things simple and just dump the data here.
// We don't need another script then process that file, we can keep processing it.
// However, having a data file allows us to check for changes in the data set.
ksort($aData, SORT_NATURAL);
$sOutFile = preg_replace(['/(\.tsv|\.txt)$/', '/$/'], ['', '.normalized-data.tsv'], $sFile);
$fOutput = fopen($sOutFile, 'w');
fputs($fOutput, "normalized_variant\tstatus\t" . implode("\t", $aCentersFound) . "\tids\tgenes\treported_as\n");
foreach ($aData as $sVariant => $aVariant) {
    $aLine = [
        $sVariant,
        $aVariant['status'],
    ];
    foreach ($aCentersFound as $sCenter) {
        $aLine[] = ($aVariant['classifications'][$sCenter] ?? '');
    }
}
fclose($fOutput);

// Report progress to the screen and continue.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] Done.' . "\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Downloading VKGL data from LOVD and preparing update...' . "\n");





// Process updates in the database.
$nVariantsDone = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.

$aVariantsCreated = array(); // Counters per chromosome.
$aVariantsUpdated = array(); // Counters per chromosome.
$aVariantsDeleted = array(); // Counters per chromosome.
$aVariantsSkipped = array(); // Counters per chromosome.
$sNow = date('Y-m-d H:i:s');

// Process updates per chromosome, but show progress over the total number of variants.
$sRefSeq = ''; // The RefSeq (NC) we're currently working on.
$sPrevRefSeq = ''; // The one (NC) we were working on before.
$sChromosome = ''; // The chromosome we're currently working on, derived from $sRefSeq.

// We won't process variants that we can't hold.
$sMaxDNALength = lovd_getColumnLength(TABLE_VARIANTS, 'VariantOnGenome/DNA');

foreach ($aData as $sVariant => $aVariant) {
    // Check chromosome, is this different from the previous line?
    list($sRefSeq, $sDNA) = explode(':', $sVariant, 2);
    if (!$sRefSeq) {
        // Eh, no chromosome?
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Cannot get chromosome from variant ' . $sVariant . ".\n\n");
        die(EXIT_ERROR_DATA_CONTENT_ERROR);
    }

    if ($sRefSeq != $sPrevRefSeq) {
        // New chromosome, report and load this new chromosome's data.
        if ($sPrevRefSeq) {
            // Report status of previous chromosome.
            $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Chromosome ' . $sChromosome . ' completed.' . "\n" .
                '                   Variants created: ' . $aVariantsCreated[$sChromosome] . ".\n" .
                '                   Variants updated: ' . $aVariantsUpdated[$sChromosome] . ".\n" .
                '                   Variants deleted: ' . $aVariantsDeleted[$sChromosome] . ".\n" .
                '                   Variants skipped: ' . $aVariantsSkipped[$sChromosome] . ".\n");
            $tProgressReported = microtime(true); // Don't report again for a certain amount of time.
        }

        $sChromosome = array_search($sRefSeq, $_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences']);
        if (!$sChromosome) {
            // Eh? It did work the other way around before.... This is a failsave if there's a bug.
            lovd_printIfVerbose(VERBOSITY_LOW,
                'Error: Cannot find chromosome belonging to ' . $_CONFIG['user']['refseq_build'] . ':' . $sRefSeq . ".\n\n");
            die(EXIT_ERROR_DATA_CONTENT_ERROR);
        }

        // Reset counters. We're looking per chromosome.
        $aVariantsCreated[$sChromosome] = 0;
        $aVariantsUpdated[$sChromosome] = 0;
        $aVariantsDeleted[$sChromosome] = 0;
        $aVariantsSkipped[$sChromosome] = 0;

        // Check if we actually have some columns that we use, activated.
        // These are optional, so we don't want to die if we don't have them.
        $aActiveCols = $_DB->q('
            SELECT colid FROM ' . TABLE_ACTIVE_COLS . '
            WHERE colid IN (?, ?, ?, ?)',
            array(
                'VariantOnGenome/Genetic_origin',
                'VariantOnGenome/Remarks',
                'VariantOnGenome/Remarks_Non_Public',
                'VariantOnGenome/ClinicalClassification',
            ))->fetchAllColumn();
        $bGeneticOrigin = in_array('VariantOnGenome/Genetic_origin', $aActiveCols);
        $bRemarks = in_array('VariantOnGenome/Remarks', $aActiveCols);
        $bRemarksNonPublic = in_array('VariantOnGenome/Remarks_Non_Public', $aActiveCols);
        $bClassification = in_array('VariantOnGenome/ClinicalClassification', $aActiveCols);

        // Load the data currently in the database.
        // Note, that if there are two entries of the same variant by the same center, we see only *one*.
        $_DB->q('SET group_concat_max_len = 10000');
        $aDataLOVD = $_DB->q('
            SELECT CONCAT(vog.created_by, ":", ?, ":", vog.`VariantOnGenome/DNA`) AS ID,
              vog.id, vog.allele, vog.effectid, vog.chromosome, vog.position_g_start, vog.position_g_end, vog.type,
              vog.created_by, vog.owned_by, vog.statusid, vog.`VariantOnGenome/DNA`,
              vog.`VariantOnGenome/DBID`' .
                (!$bGeneticOrigin? '' : ', vog.`VariantOnGenome/Genetic_origin`') .
                (!$bRemarks? '' : ', vog.`VariantOnGenome/Remarks`') .
                (!$bRemarksNonPublic? '' : ', vog.`VariantOnGenome/Remarks_Non_Public`') .
                (!$bClassification? '' : ', IFNULL(NULLIF(vog.`VariantOnGenome/ClinicalClassification`, ""), "-") AS `VariantOnGenome/ClinicalClassification`') . '
            FROM ' . TABLE_VARIANTS . ' AS vog LEFT OUTER JOIN ' . TABLE_VARIANTS_ON_TRANSCRIPTS . ' AS vot USING (id)
            WHERE vog.chromosome = ? AND vog.created_by IN (?' . str_repeat(', ?', $nCentersFound - 1) . ')
            GROUP BY vog.id',
            array_merge(
                array($sRefSeq, $sChromosome),
                array_values($aCenterIDs)))->fetchAllGroupAssoc();

        // Check all LOVD data; normalize everything and mark removed data.
        // Older data may not have been fully normalized, and we will find new records even though we already had them.
        foreach ($aDataLOVD as $sLOVDKey => $aLOVDVariant) {
            list($nCenter, $sLOVDVariant) = explode(':', $sLOVDKey, 2);
            $sCenter = array_search($nCenter, $aCenterIDs);
            // Perhaps we find that we want to remove this variant.
            $bRemoveVariant = false;
            $sRemoveMessage = '';
            $sVariantCorrected = $sLOVDVariant;

            if (!$bRemoveVariant && $_CONFIG['user']['delete_redundant_variants'] == 'y'
                && (!isset($aData[$sVariantCorrected]) || !isset($aData[$sVariantCorrected]['classifications'][$sCenter]))) {
                // We aren't already removing this variant, but we don't actually see this variant anymore.
                // The variant is lost, there's nothing to do about it. If the user has indicated so, remove it,
                //  but mark it only as removed. Later we can always decide to actually remove these entries.
                $bRemoveVariant = true;
                $sRemoveMessage = 'Variant no longer found in the VKGL dataset for this center.';
            }

            // Remove variant if needed. Don't touch the Remarks_Non_Public, we don't want to complicate things.
            // Also, don't run this if we don't have to. Check status and current remarks.
            if ($bRemoveVariant && !$bDebug) {
                $sRemoveMessage = 'VKGL data sharing initiative Nederland' .
                    (!$sRemoveMessage? '' : '; ' . $sRemoveMessage);
                $q = $_DB->q('UPDATE ' . TABLE_VARIANTS . '
                              SET `VariantOnGenome/Remarks` = ?, statusid = ?, edited_by = 0, edited_date = ?
                              WHERE id = ? AND !(`VariantOnGenome/Remarks` LIKE ? AND statusid <= ?)',
                    array(
                        $sRemoveMessage,
                        STATUS_HIDDEN,
                        $sNow,
                        $aLOVDVariant['id'],
                        $sRemoveMessage . '%',
                        STATUS_HIDDEN,
                    ));
                if ($q->rowCount()) {
                    $aVariantsDeleted[$sChromosome]++;
                }
                unset($aDataLOVD[$sLOVDKey]);
            }
        }

        // Report data loaded, and get to work.
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] Chromosome ' . $sChromosome . ' data loaded, running updates...' . "\n");
        $tProgressReported = microtime(true); // Don't report again for a certain amount of time.

        $sPrevRefSeq = $sRefSeq;
    }



    // LOVD+ has a much shorter DNA field; only 150 characters.
    // Trying to put in a variant that's bigger will crash this process.
    // However, we may also simply find variants longer than 255 characters.
    // We will simply skip whatever is too long.
    if (strlen($sDNA) > $sMaxDNALength) {
        $aVariantsSkipped[$sChromosome] ++;
        continue;
    }

    // Add some needed fields; (type, position_start, position_end).
    //!!Instead of using this function, using the HGVS library to get the needed fields.
        $aVariant = array_merge(
        $aVariant,
        lovd_getVariantInfo($sDNA)
    );

    // Loop through centra who found this variant.
    foreach ($aVariant['classifications'] as $sCenter => $sClassification) {
        // Build variant entry.
        $sLOVDKey = $aCenterIDs[$sCenter] . ':' . $sVariant;
        $aVOGEntry = array(
            'id' => null,
            'allele' => '0', // Unknown.
            // Don't let internal conflicts cause notices here.
            'effectid' => (!isset($_CONFIG['effect_mapping_LOVD'][$sClassification])? 0 :
                $_CONFIG['effect_mapping_LOVD'][$sClassification]) .
                // Default to "Not curated" for concluded effect, unless a user filled something in already.
                (!isset($aDataLOVD[$sLOVDKey])? '0' : substr($aDataLOVD[$sLOVDKey]['effectid'], -1)),
            'chromosome' => $sChromosome,
            'position_g_start' => $aVariant['position_start'],
            'position_g_end' => $aVariant['position_end'],
            'type' => $aVariant['type'],
            'created_by' => $aCenterIDs[$sCenter],
            // Created_date will be added later, right now we don't have it to prevent unneeded differences.
            'owned_by' => ($aVariant['status'] == 'single-lab' && $_CONFIG['user']['public_singlelab_owners'] != 'y'? // Should single-lab entry get the generic VKGL account as owner?
                $_CONFIG['user']['vkgl_generic_id'] : $aCenterIDs[$sCenter]),
            'statusid' => (string) ($aVariant['status'] == 'opposite'? STATUS_HIDDEN : STATUS_OK), // FIXME: Set to Marked if a warning occurred within this variant? Or like, when not having a mapping?
            // Don't let internal conflicts cause notices here.
            'VariantOnGenome/ClinicalClassification' => (!isset($_CONFIG['effect_mapping_classification'][$sClassification])? '-' :
                $_CONFIG['effect_mapping_classification'][$sClassification]),
            'VariantOnGenome/DNA' => $sDNA, // Can actually also update, if the LOVD data is not correct.
            'VariantOnGenome/DBID' => '', // FIXME: Will be filled in later for records to be created!
            'VariantOnGenome/Genetic_origin' => 'CLASSIFICATION record',
            'VariantOnGenome/Remarks' => 'VKGL data sharing initiative Nederland' . ($aVariant['status'] != 'opposite'? '' : '; Variant classification is in conflict with a different center.'),
            'VariantOnGenome/Remarks_Non_Public' => array(
                'warning' => 'Do not remove or edit this field!',
                'updates' => array(),
            ),
        );

        // Some of these columns are optional.
        if (!$bClassification) {
            unset($aVOGEntry['VariantOnGenome/ClinicalClassification']);
        }
        if (!$bGeneticOrigin) {
            unset($aVOGEntry['VariantOnGenome/Genetic_origin']);
        }
        if (!$bRemarks) {
            unset($aVOGEntry['VariantOnGenome/Remarks']);
        }
        if (!$bRemarksNonPublic) {
            unset($aVOGEntry['VariantOnGenome/Remarks_Non_Public']);
        }

        // If this entry already exists, simply update the record when needed.
        if (isset($aDataLOVD[$sLOVDKey])) {
            // Variant has been seen already by this center.

            // Make it easier to compare with our array.
            // Build array from JSON object, if we have it.
            if (!empty($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'])) {
                $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] = json_decode($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'], true);
                if ($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] === false
                    || !is_array($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'])) {
                    // Somebody malformed this field...
                    lovd_printIfVerbose(VERBOSITY_LOW,
                        'Error: Variant ID ' . $sVariant . ' has an unparsable JSON object for center ' . $sCenter . '(' . $aCenterIDs[$sCenter] . ').' . "\n\n");
                    die(EXIT_ERROR_DATA_CONTENT_ERROR);
                }
            } elseif ($bRemarksNonPublic) {
                $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] = array();
            }

            // Make my life easier, just copy some values.
            $aVOGEntry['id'] = $aDataLOVD[$sLOVDKey]['id'];
            $aVOGEntry['VariantOnGenome/DBID'] = $aDataLOVD[$sLOVDKey]['VariantOnGenome/DBID'];
            if ($bRemarksNonPublic) {
                $aVOGEntry['VariantOnGenome/Remarks_Non_Public'] = array_merge(
                    $aVOGEntry['VariantOnGenome/Remarks_Non_Public'],
                    $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public']
                );
            }

            // NOTE: This is debugging code. It checks the differences, and reports them, instead of running the update.
            if ($bDebug) {
                // Reduce the differences, by adapting the LOVD record a bit already.
                if ($bRemarks && $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks'] == 'VKGL data sharing initiative Nederland; correct HGVS to be checked') {
                    $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks'] = $aVOGEntry['VariantOnGenome/Remarks'];
                }
                // Don't mention ins to dups, that's the logical result of our checking.
                if ($aDataLOVD[$sLOVDKey]['type'] == 'ins' && $aVOGEntry['type'] == 'dup') {
                    $aDataLOVD[$sLOVDKey]['type'] = $aVOGEntry['type'];
                }
            }

            // Determine if there are any differences.
            $aDiff = array();
            foreach ($aDataLOVD[$sLOVDKey] as $sKey => $Value) {
                if (!isset($aVOGEntry[$sKey]) || $Value != $aVOGEntry[$sKey]) {
                    $aDiff[$sKey] = array(
                        $Value,
                        (!isset($aVOGEntry[$sKey])? 'NULL' : $aVOGEntry[$sKey]),
                    );
                    if ($bRemarksNonPublic) {
                        // Also report differences.
                        // FIXME: When an entry gets deleted, this is not mentioned in the JSON. Updates remains empty.
                        if ($sKey != 'VariantOnGenome/Remarks_Non_Public') {
                            // Don't self-report, of course.
                            $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey] = array($Value, $aVOGEntry[$sKey]);
                        }
                    }
                }
            }
            // Because we were building this while building up the diff array:
            if ($bRemarksNonPublic && $aDiff && !$bDebug) {
                $aDiff['VariantOnGenome/Remarks_Non_Public'][1] = $aVOGEntry['VariantOnGenome/Remarks_Non_Public'];
            }

            // If there is a diff, and we're in debug mode, report the diff but do nothing. This way, we can check if
            //  our script works well. To prevent very long diffs however, remove certain elements from the diff that we
            //  understand can easily change.
            if ($aDiff && $bDebug) {
                // When the classification changes and becomes just a bit more or less sure, it's fine.
                // Do check if the concluded effect doesn't change.
                if (isset($aDiff['effectid']) && substr($aDiff['effectid'][0], -1) == substr($aDiff['effectid'][1], -1)) {
                    $aEffects = array(
                        substr($aDiff['effectid'][0], 0, 1),
                        substr($aDiff['effectid'][1], 0, 1),
                    );
                    sort($aEffects);
                    if (in_array(implode('', $aEffects), array('13', '35', '57', '79'))) {
                        unset($aDiff['effectid'], $aDiff['VariantOnGenome/ClinicalClassification']);
                    }
                }
                // ClinicalClassification was filled in only later.
                if (isset($aDiff['VariantOnGenome/ClinicalClassification'])
                    && in_array($aDiff['VariantOnGenome/ClinicalClassification'][0], array('', '-'))) {
                    unset($aDiff['VariantOnGenome/ClinicalClassification']);
                }
                // If diff is only the status change, it's fine.
                if (count($aDiff) == 1 && isset($aDiff['statusid'])) {
                    unset($aDiff['statusid']);
                }
                // Hide the JSON object, we know it works.
                unset($aDiff['VariantOnGenome/Remarks_Non_Public']);

                // Check if the diff is simply the re-publication of this variant.
                // That's a status change to 9 and possibly a Remarks change.
                if ($aDiff && array_diff(array_keys($aDiff), ['VariantOnGenome/Remarks']) == array('statusid') && $aDiff['statusid'][1] == 9) {
                    $aDiff = array();
                }

                if ($aDiff) {
                    var_dump($sVariant, $aDiff);
                }
                $aVariantsUpdated[$sChromosome] ++;
                continue;
            }



            // Run update, if needed.
            if ($aDiff && !$bDebug) {
                // Update atomically, we don't want half updates.
                $_DB->beginTransaction();

                // Update the VOG, remove 'id' as an updateable field (it shouldn't be there, but still).
                $aFieldsToUpdate = array();
                foreach ($aDiff as $sKey => $aColDiff) {
                    if ($sKey != 'id') {
                        if ($sKey == 'VariantOnGenome/Remarks_Non_Public') {
                            $aFieldsToUpdate[$sKey] = json_encode($aColDiff[1]);
                        } else {
                            $aFieldsToUpdate[$sKey] = $aColDiff[1];
                        }
                    }
                }
                $aFieldsToUpdate['edited_by'] = 0;
                $aFieldsToUpdate['edited_date'] = $sNow;

                $_DB->q('UPDATE ' . TABLE_VARIANTS . ' SET ' .
                    implode(', ', array_map(function ($sField) {
                        return '`' . $sField . '` = ?';
                    }, array_keys($aFieldsToUpdate))) . '
                              WHERE id = ?', array_merge(array_values($aFieldsToUpdate), array($aVOGEntry['id'])));

                // If we get here, everything went well.
                $_DB->commit();

                $aVariantsUpdated[$sChromosome] ++;
                continue;
            }

            // If we get here, there was nothing to update, data is still the same.
            $aVariantsSkipped[$sChromosome] ++;
            continue;





        } elseif (!$bDebug) {
            // Variant has not been seen yet by this center. Create it in the database.

            // Prepare additional data.
            $aVOGEntry['created_date'] = $sNow;
            if ($bRemarksNonPublic) {
                $aVOGEntry['VariantOnGenome/Remarks_Non_Public'] = json_encode($aVOGEntry['VariantOnGenome/Remarks_Non_Public']);
            }
            // We can be more correct here by adding VOT data, but this function expects that in quite a complex manner.
            $aVOGEntry['VariantOnGenome/DBID'] = lovd_fetchDBID($aVOGEntry);

            // Run atomically, we don't want half inserts.
            $_DB->beginTransaction();

            // Insert the VOG first.
            $aFields = array_keys($aVOGEntry);
            $_DB->q('INSERT INTO ' . TABLE_VARIANTS . '
                         (' . implode(', ', array_map(function ($sField) {
                    return '`' . $sField . '`';
                }, $aFields)) . ')
                         VALUES (?' . str_repeat(', ?', count($aFields) - 1) . ')', array_values($aVOGEntry));
            $aVOGEntry['id'] = $_DB->lastInsertId();

            // If we get here, everything went well.
            $_DB->commit();

            $aVariantsCreated[$sChromosome] ++;
            continue;
        }
    }





    // Print update, for every percentage changed.
    $nVariantsDone ++;
    if ((microtime(true) - $tProgressReported) > 5 && $nVariantsDone != $nVariants
        && floor($nVariantsDone * 1000 / $nVariants) != $nPercentageComplete) {
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] ' .
            str_pad($nVariantsDone, strlen($nVariants), ' ', STR_PAD_LEFT) . ' variants processed...' . "\n");
        $tProgressReported = microtime(true); // Don't report again for a certain amount of time.
    }
}

// Final counts.
$nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
        5, ' ', STR_PAD_LEFT) . '%] Chromosome ' . $sChromosome . ' completed.' . "\n" .
    '                   Variants created: ' . $aVariantsCreated[$sChromosome] . ".\n" .
    '                   Variants updated: ' . $aVariantsUpdated[$sChromosome] . ".\n" .
    '                   Variants deleted: ' . $aVariantsDeleted[$sChromosome] . ".\n" .
    '                   Variants skipped: ' . $aVariantsSkipped[$sChromosome] . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [Totals] Variants created: ' . array_sum($aVariantsCreated) . ".\n" .
    '                   Variants updated: ' . array_sum($aVariantsUpdated) . ".\n" .
    '                   Variants deleted: ' . array_sum($aVariantsDeleted) . ".\n" .
    '                   Variants skipped: ' . array_sum($aVariantsSkipped) . ".\n" .
    (!$nWarningsOccurred? '' :
        '                   Warning(s) count: ' . $nWarningsOccurred . ".\n")
      . "\n");

if (!$bDebug && !LOVD_plus) {
    // Update all gene's updated dates.
    // We're going to make this easy for us; all entries created or edited at $sNow,
    //  we're going to assume are ours. Run on entire database.
    $aGenesUpdated = $_DB->q('
        SELECT DISTINCT t.geneid
        FROM ' . TABLE_TRANSCRIPTS . ' AS t
         INNER JOIN ' . TABLE_VARIANTS_ON_TRANSCRIPTS . ' AS vot ON (t.id = vot.transcriptid)
         INNER JOIN ' . TABLE_VARIANTS . ' AS vog ON (vot.id = vog.id)
        WHERE vog.created_date = ? OR vog.edited_date = ?', array($sNow, $sNow))->fetchAllColumn();

    if ($aGenesUpdated) {
        // We can't use lovd_setUpdatedDate(), since that contains $_AUTH checks that we won't be able to pass.
        $q = $_DB->q('
            UPDATE ' . TABLE_GENES . '
            SET updated_by = ?, updated_date = ?
            WHERE updated_date < ? AND id IN (?' . str_repeat(', ?', count($aGenesUpdated) - 1) . ')',
            array_merge(array(0, $sNow, $sNow), $aGenesUpdated), false);
        $nUpdated = $q->rowCount();
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [Totals] Gene(s)  updated: ' . $nUpdated . '/' . count($aGenesUpdated) . ".\n\n");
    }
}

if ($nWarningsOccurred) {
    die(EXIT_WARNINGS_OCCURRED);
}
?>
