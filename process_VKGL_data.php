#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2019-06-27
 * Modified    : 2019-06-28
 * Version     : 0.0
 * For LOVD+   : 3.0-22
 *
 * Purpose     : Processes the VKGL consensus data, and creates or updates the
 *               VKGL data in the LOVD instance.
 *
 * Changelog   : 0.1    2019-06-??
 *               Initial release.
 *
 * Copyright   : 2004-2019 Leiden University Medical Center; http://www.LUMC.nl/
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

// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$_CONFIG = array(
    'name' => 'VKGL data importer',
    'version' => '0.0',
    'settings_file' => 'settings.json',
    'flags' => array(
        'y' => false,
    ),
    'columns_mandatory' => array(
        // These are the columns that need to be present in order for the file to get processed.
        'id',
        'chromosome',
        'start',
        'ref',
        'alt',
        'gene',
        'c_dna',
        'transcript',
        'protein',
    ),
    'columns_ignore' => array(
        // These are the columns that we'll ignore. If we find any others, we'll complain.
        'stop',
        'consensus_classification',
        'matches',
        'disease',
        'comments',
        'history',
    ),
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
    'user' => array(
        // Variables we will be asking the user.
        'refseq_build' => 'hg19',
        'lovd_path' => '/www/databases.lovd.nl/shared/',
        'mutalyzer_cache_NC' => 'NC_cache.txt', // Stores NC g. descriptions and their corrected output.
        'mutalyzer_cache_NM' => 'NM_cache.txt', // Stores NM c. descriptions and their corrected output.
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
        // Write to STDERR, as this script dumps the resulting output file to STDOUT.
        fwrite(STDERR, $sMessage);
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
$bWarningsOcurred = false;

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
// Parse the rest, ignore everything we don't care about, assume the rest must be centers.
// Verify these and store.
$aCentersFound = array();
$nCentersFound = 0;
$aHeadersSorted = array_diff($aHeaders, $_CONFIG['columns_mandatory'], $_CONFIG['columns_ignore']);
sort($aHeadersSorted); // This makes it easier to find the centers and their *_link column.
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
if (!$_CONFIG['flags']['y']) {
    lovd_printIfVerbose(VERBOSITY_HIGH,
        $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n");

    lovd_verifySettings('refseq_build', 'The genome build that the data file uses (hg19/hg38)', 'array', array('hg19', 'hg38'));
    if (!lovd_verifySettings('lovd_path', 'Path of LOVD installation to load data into', 'lovd_path', '')) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Failed to get LOVD path.' . "\n\n");
        die(EXIT_ERROR_CONNECTION_PROBLEM);
    }
    lovd_verifySettings('mutalyzer_cache_NC', 'File containing the Mutalyzer cache for genomic (NC) variants', 'file', '');
    lovd_verifySettings('mutalyzer_cache_NM', 'File containing the Mutalyzer cache for transcript (NM) variants', 'file', '');

    // Verify all centers.
    $aIDsUsed = array(); // Make sure IDs are unique.
    foreach ($aCentersFound as $sCenter) {
        while (true) {
            lovd_verifySettings('center_' . $sCenter . '_id', 'The LOVD user ID for VKGL center ' . $sCenter, 'int', '1,99999');
            if (in_array($_CONFIG['user']['center_' . $sCenter . '_id'], $aIDsUsed)) {
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    '    This ID is already assigned to a different center.' . "\n");
                $_CONFIG['user']['center_' . $sCenter . '_id'] = 0;
            } else {
                $aIDsUsed[] = $_CONFIG['user']['center_' . $sCenter . '_id'];
                break;
            }
        }
    }
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
// If I put a require here, I can't nicely handle errors, because PHP will die if something is wrong.
// However, I need to get rid of the "headers already sent" warnings from inc-init.php.
// So, sadly if there is a problem connecting to LOVD, the script will die here without any output whatsoever.
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // CLI logs errors to the screen, apparently.
// Let the LOVD believe we're accessing it through SSL. LOVDs that demand this, will otherwise block us.
// We have error messages surpressed anyway, as the LOVD in question will complain when it tries to define "SSL" as well.
define('SSL', true);
require ROOT_PATH . 'inc-init.php';
ini_set('display_errors', '1'); // We do want to see errors from here on.

lovd_printIfVerbose(VERBOSITY_HIGH,
    ' Connected!' . "\n\n");



// Check given refseq build.
$sRefSeqBuild = $_DB->query('SELECT refseq_build FROM ' . TABLE_CONFIG)->fetchColumn();
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
$aUsers = $_DB->query('SELECT CAST(id AS UNSIGNED) AS id, name FROM ' . TABLE_USERS . ' WHERE id IN (?' . str_repeat(', ?', count($aUserIDs) - 1) . ') ORDER BY id',
    array_values($aUserIDs))->fetchAllCombine();

$bAccountsOK = true;
$lCenters = max(array_map('strlen', $aCentersFound));
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
    }
}
if (!$_CONFIG['flags']['y']) {
    lovd_printIfVerbose(VERBOSITY_MEDIUM, "\n");
}

if (!$bRefSeqBuildOK || !$bAccountsOK) {
    // One of the settings is no good. Settings have been updated, save changes (but don't die if that doesn't work).
    lovd_saveSettings(false);

    // Now, die because of the incorrect settings.
    lovd_printIfVerbose(VERBOSITY_LOW,
        ($bRefSeqBuildOK? '' : 'Error: Failed to set RefSeq build.' . "\n") .
        ($bAccountsOK? '' : 'Error: Failed to get all LOVD user accounts.' . "\n") . "\n");
    die(EXIT_ERROR_SETTINGS_INCORRECT);
}

?>
