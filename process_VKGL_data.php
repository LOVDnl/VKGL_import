#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2019-06-27
 * Modified    : 2019-07-02
 * Version     : 0.0
 * For LOVD+   : 3.0-22
 *
 * Purpose     : Processes the VKGL consensus data, and creates or updates the
 *               VKGL data in the LOVD instance.
 *
 * Changelog   : 0.1    2019-07-??
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
    'mutalyzer_URL' => 'https://test.mutalyzer.nl/', // Test may be faster than www.mutalyzer.nl.
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





function lovd_getVariantDescription (&$aVariant, $sRef, $sAlt)
{
    // Constructs a variant description from $sRef and $sAlt and adds it to $aVariant in a new 'VariantOnGenome/DNA' key.
    // The 'position_g_start' and 'position_g_end' keys in $aVariant are adjusted accordingly and a 'type' key is added too.
    // The numbering scheme is either g. or m. and depends on the 'chromosome' key in $aVariant.
    // Requires:
    //   $aVariant['chromosome']
    //   $aVariant['position']
    // Adds:
    //   $aVariant['position_g_start']
    //   $aVariant['position_g_end']
    //   $aVariant['type']
    //   $aVariant['VariantOnGenome/DNA']

    // Make all bases uppercase.
    $sRef = strtoupper($sRef);
    $sAlt = strtoupper($sAlt);

    // Clear out empty REF and ALTs. This is not allowed in the VCF specs,
    //  but some tools create them nonetheless.
    foreach (array('sRef', 'sAlt') as $var) {
        if (in_array($$var, array('.', '-'))) {
            $$var = '';
        }
    }

    // Use the right prefix for the numbering scheme.
    $sHGVSPrefix = 'g.';
    if ($aVariant['chromosome'] == 'M') {
        $sHGVSPrefix = 'm.';
    }

    // Even substitutions are sometimes mentioned as longer Refs and Alts, so we'll always need to isolate the actual difference.
    $aVariant['position_g_start'] = $aVariant['position'];
    $aVariant['position_g_end'] = $aVariant['position'] + strlen($sRef) - 1;

    // Save original values before we edit them.
    $sRefOriginal = $sRef;
    $sAltOriginal = $sAlt;

    // 'Eat' letters from either end - first left, then right - to isolate the difference.
    while (strlen($sRef) > 0 && strlen($sAlt) > 0 && $sRef{0} == $sAlt{0}) {
        $sRef = substr($sRef, 1);
        $sAlt = substr($sAlt, 1);
        $aVariant['position_g_start'] ++;
    }
    while (strlen($sRef) > 0 && strlen($sAlt) > 0 && $sRef[strlen($sRef) - 1] == $sAlt[strlen($sAlt) - 1]) {
        $sRef = substr($sRef, 0, -1);
        $sAlt = substr($sAlt, 0, -1);
        $aVariant['position_g_end'] --;
    }

    // Substitution, or something else?
    if (strlen($sRef) == 1 && strlen($sAlt) == 1) {
        // Substitutions.
        $aVariant['type'] = 'subst';
        $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . $sRef . '>' . $sAlt;
    } else {
        // Insertions/duplications, deletions, inversions, indels.

        // Now find out the variant type.
        if (strlen($sRef) > 0 && strlen($sAlt) == 0) {
            // Deletion.
            $aVariant['type'] = 'del';
            if ($aVariant['position_g_start'] == $aVariant['position_g_end']) {
                $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . 'del';
            } else {
                $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . '_' . $aVariant['position_g_end'] . 'del';
            }
        } elseif (strlen($sAlt) > 0 && strlen($sRef) == 0) {
            // Something has been added... could be an insertion or a duplication.
            if ($sRefOriginal && substr($sAltOriginal, strrpos($sAltOriginal, $sAlt) - strlen($sAlt), strlen($sAlt)) == $sAlt) {
                // Duplicaton (not allowed when REF was empty from the start).
                $aVariant['type'] = 'dup';
                $aVariant['position_g_start'] -= strlen($sAlt);
                if ($aVariant['position_g_start'] == $aVariant['position_g_end']) {
                    $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . 'dup';
                } else {
                    $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . '_' . $aVariant['position_g_end'] . 'dup';
                }
            } else {
                // Insertion.
                $aVariant['type'] = 'ins';
                if (!$sRefOriginal) {
                    // We never got a Ref. This is not allowed, but some centers have it anyway.
                    $aVariant['position_g_end'] += 2;
                } else {
                    // Exchange g_start and g_end; after the 'letter eating' we did, start is actually end + 1!
                    $aVariant['position_g_start'] --;
                    $aVariant['position_g_end'] ++;
                }
                $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . '_' . $aVariant['position_g_end'] . 'ins' . $sAlt;
            }
        } elseif ($sRef == strrev(str_replace(array('a', 'c', 'g', 't'), array('T', 'G', 'C', 'A'), strtolower($sAlt)))) {
            // Inversion.
            $aVariant['type'] = 'inv';
            $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . '_' . $aVariant['position_g_end'] . 'inv';
        } else {
            // Deletion/insertion.
            $aVariant['type'] = 'delins';
            if ($aVariant['position_g_start'] == $aVariant['position_g_end']) {
                $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . 'delins' . $sAlt;
            } else {
                $aVariant['VariantOnGenome/DNA'] = $sHGVSPrefix . $aVariant['position_g_start'] . '_' . $aVariant['position_g_end'] . 'delins' . $sAlt;
            }
        }
    }
}





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
$tStart = time() + date('Z', 0); // Correct for timezone, otherwise the start value is not 0.





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





// Load the caches, create if they don't exist. They can only not exist, when the defaults are used.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [      ] Loading Mutalyzer cache files...' . "\n");
$_CACHE = array();
foreach (array('mutalyzer_cache_NC', 'mutalyzer_cache_NM') as $sKeyName) {
    $_CACHE[$sKeyName] = array();
    if (!file_exists($_CONFIG['user'][$sKeyName])) {
        // It doesn't exist, create it.
        if (!touch($_CONFIG['user'][$sKeyName])) {
            lovd_printIfVerbose(VERBOSITY_LOW,
                'Error: Could not create Mutalyzer cache file.' . "\n\n");
            die(EXIT_ERROR_CACHE_CANT_CREATE);
        } else {
            lovd_printIfVerbose(VERBOSITY_HIGH,
                '  Cache created: ' . $_CONFIG['user'][$sKeyName] . "\n");
        }
    } elseif (!is_file($_CONFIG['user'][$sKeyName]) || !is_readable($_CONFIG['user'][$sKeyName])) {
        // It does exist, but we can't read it.
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Unreadable Mutalyzer cache file.' . "\n\n");
        die(EXIT_ERROR_CACHE_UNREADABLE);
    } else {
        // Load the cache.
        $aCache = file($_CONFIG['user'][$sKeyName], FILE_IGNORE_NEW_LINES);
        $nCacheLine = 0;
        $nCacheLines = count($aCache);
        foreach ($aCache as $sVariant) {
            $nCacheLine ++;
            $aVariant = explode("\t", $sVariant);
            if (count($aVariant) == 2) {
                $_CACHE[$sKeyName][$aVariant[0]] = $aVariant[1];
            } else {
                // Malformed line.
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(round($nCacheLine * 100 / $nCacheLines, 1), 1),
                        5, ' ', STR_PAD_LEFT) . '%] Warning: ' . ucfirst(str_replace('_', ' ', $sKeyName)) . ' line ' . $nCacheLine . ' malformed.' . "\n");
                $bWarningsOcurred = true;
            }
        }
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] ' . ucfirst(str_replace('_', ' ', $sKeyName)) . ' loaded, ' . count($_CACHE[$sKeyName]) . ' variants.' . "\n");
    }
}
lovd_printIfVerbose(VERBOSITY_MEDIUM, "\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Parsing VKGL file...' . "\n");





// Read out all variants, with labels per center, and store cDNA/protein annotation.
$aData = array();
// 'disease' is currently empty. When we'll start using it, then add it to the mandatory columns and copy it here.
// 'comments' is currently the same as 'id'.
$aColumnsToUse = array_merge($_CONFIG['columns_mandatory'], $aCentersFound);
while ($sLine = fgets($fInput)) {
    $nLine++;
    $sLine = trim($sLine);
    if (!$sLine) {
        continue;
    }

    $aDataLine = explode("\t", $sLine);
    // Trim quotes off of the data.
    $aDataRow = array_map(function($sData) {
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

    // Store data. We assume here that the ID field is unique.
    $aData[$aDataLine['id']] = array_intersect_key($aDataLine, array_flip($aColumnsToUse));
}

$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] VKGL file successfully parsed, found ' . $nVariants . ' variants.' . "\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Verifying variants...' . "\n");

// We might be running for some time.
set_time_limit(0);





// Correct all genomic variants, using the cache. Skip substitutions.
// And don't bother using the database, we'll assume the cache knows it all.
$nVariantsDone = 0;
$nVariantsAddedToCache = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within one second (interrupted runs).
foreach ($aData as $sID => $aVariant) {
    if (!isset($_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences'][$aVariant['chromosome']])) {
        // Can't get chromosome's NC refseq?
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Variant ID ' . $sID . ' has unknown chromosome value ' . $aVariant['chromosome'] . ".\n\n");
        die(EXIT_ERROR_DATA_CONTENT_ERROR);
    }

    // Use LOVD+'s lovd_getVariantDescription() to build the HGVS from the VCF fields.
    // Also adjusts the positions when needed, and creates the 'type' field for us.
    $aVariant['position'] = $aVariant['start']; // The function needs this.
    lovd_getVariantDescription($aVariant, $aVariant['ref'], $aVariant['alt']);

    // Don't check substitutions, we'll assume them to be OK. This saves a huge amount of time.
    // Check everything else.
    if ($aVariant['type'] != 'subst') {
        $sVariant =
            $_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences'][$aVariant['chromosome']] .
            ':' . $aVariant['VariantOnGenome/DNA'];
        // Check cache. If not found there, add it.
        if (isset($_CACHE['mutalyzer_cache_NC'][$sVariant])) {
            $sVariantCorrected = $_CACHE['mutalyzer_cache_NC'][$sVariant];
        } else {
            $aResult = json_decode(file_get_contents($_CONFIG['mutalyzer_URL'] . '/json/runMutalyzerLight?variant=' . $sVariant), true);
            if (!$aResult) {
                // Error? Just report. They must be new variants, anyway.
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                            floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                        5, ' ', STR_PAD_LEFT) . '%] Warning: Error parsing reply from Mutalyzer for variant ' . $sID . ".\n" .
                    '                   It was sent as ' . $sVariant . ".\n");
                $bWarningsOcurred = true;
                $nVariantsDone ++;
                continue; // Next variant.
            }

            $sVariantCorrected = $aResult['genomicDescription'];
            // Add to cache. We won't see it again here, to just add to the file.
            file_put_contents($_CONFIG['user']['mutalyzer_cache_NC'], $sVariant . "\t" . $sVariantCorrected . "\n", FILE_APPEND);
            $nVariantsAddedToCache ++;

            // Since we called Mutalyzer now already, better make use of these:
            $aVariant['mutalyzer_legend'] = $aResult['legend'];
            $aVariant['mutalyzer_transcripts'] = $aResult['transcriptDescriptions'];
            $aVariant['mutalyzer_proteins'] = $aResult['proteinDescriptions'];
        }

        // Store corrected variant description.
        $aVariant['VariantOnGenome/DNA'] = $sVariantCorrected;
    }

    // Store new information, dropping some excess information.
    unset($aVariant['position']); // Never needed.
    unset($aVariant['start'], $aVariant['ref'], $aVariant['alt']); // We're done using VCF now.
    unset($aVariant['position_g_start'], $aVariant['position_g_end'], $aVariant['type']); // Are unreliable now.
    $aData[$sID] = $aVariant;

    // Print update, for every percentage changed.
    $nVariantsDone ++;
    if ((microtime(true) - $tProgressReported) > 1 && $nVariantsDone != $nVariants
        && floor($nVariantsDone * 1000 / $nVariants) != $nPercentageComplete) {
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] ' .
            str_pad($nVariantsDone, strlen($nVariants), ' ', STR_PAD_LEFT) . ' variants verified...' . "\n");
        $tProgressReported = microtime(true); // Don't report again for another second.
    }
}

// Last message.
$nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
        5, ' ', STR_PAD_LEFT) . '%] ' .
    $nVariantsDone . ' variants verified. Variants added to cache: ' . $nVariantsAddedToCache . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Merging variants after corrections...' . "\n");





// Loop variants again, merging entries.
$nVariantsMerged = 0;
foreach ($aData as $sID => $aVariant) {
    // Translate all classification values to easier values.
    $aVariant['classifications'] = array();
    foreach ($aCentersFound as $sCenter) {
        if ($aVariant[$sCenter]) {
            $aVariant['classifications'][$sCenter] = str_replace(array('likely ', 'benign', 'pathogenic', 'vus'),
                array('L', 'B', 'P', 'VUS'), strtolower($aVariant[$sCenter]));
        }
        unset($aVariant[$sCenter]);
    }

    if (!isset($aData[$aVariant['VariantOnGenome/DNA']])) {
        $aData[$aVariant['VariantOnGenome/DNA']] = $aVariant;
    } else {
        // Variant has already been seen before.
        $aData[$aVariant['VariantOnGenome/DNA']] = array_merge_recursive($aData[$aVariant['VariantOnGenome/DNA']], $aVariant);
        $nVariantsMerged ++;
    }

    // Get rid of the old data.
    unset($aData[$sID]);
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
foreach ($aData as $sID => $aVariant) {
    // Per center, first make sure we only have one classification left.
    $bInternalConflict = false;
    foreach ($aVariant['classifications'] as $sCenter => $Classification) {
        if (is_array($Classification)) {
            // Flipping the array makes the values unique and makes it easier to work with the values
            // (isset()s are faster than array_search() and in_array()).
            $aClassifications = array_flip($Classification);

            if (count($aClassifications) > 1) {
                // Rules: report opposites, */VUS to VUS, LB/B to LB, LP/P to LP.
                if ((isset($aClassifications['B']) || isset($aClassifications['LB']))
                    && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
                    // Internal conflict within center.
                    lovd_printIfVerbose(VERBOSITY_MEDIUM,
                        ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                            floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                            5, ' ', STR_PAD_LEFT) .
                        '%] Warning: Internal conflict in center ' . $sCenter . ': ' . implode(', ', $Classification) . ".\n" .
                        '                   IDs: ' . implode("\n                        ", $aVariant['id']) . "\n");
                    // Reduce to one string, we want to store the conflict to report this in LOVD in a non-public entry.
                    $aClassifications = array(implode(',', $Classification) => 1);
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
                    lovd_printIfVerbose(VERBOSITY_MEDIUM,
                        ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                            floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                            5, ' ', STR_PAD_LEFT) .
                        '%] Warning: Failed to resolve classification string for center ' . $sCenter . ': ' . implode(', ', $Classification) . ".\n" .
                        '                   IDs: ' . implode("\n                        ", $aVariant['id']) . "\n");
                }
            }

            // Store string value.
            $aVariant['classifications'][$sCenter] = key($aClassifications); // Should of course have one value.
        }
    }



    // Determine consensus (opposite, non-consensus, consensus, single-lab).
    $aVariant['status'] = '';
    if (count($aVariant['classifications']) == 1) {
        $aVariant['status'] = 'single-lab';
        $aStatusCounts['single-lab'] ++;

    } elseif ($bInternalConflict) {
        // One center had a conflict, so we all have a conflict.
        $aVariant['status'] = 'opposite';
        $aStatusCounts['opposite'] ++;

    } else {
        // We should have clean, one-classification values.
        // Handle it similarly as we did within the labs. Take unique values only and look at the combos.

        // Flipping the array makes the values unique and makes it easier to work with the values
        // (isset()s are faster than array_search() and in_array()).
        $aClassifications = array_flip($aVariant['classifications']);

        if (count($aClassifications) == 1) {
            // One unique value, everybody agrees.
            $aVariant['status'] = 'consensus';
            $aStatusCounts['consensus'] ++;

        } elseif ((isset($aClassifications['B']) || isset($aClassifications['LB']))
            && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
            // Opposite.
            $aVariant['status'] = 'opposite';
            $aStatusCounts['opposite'] ++;

        } elseif (isset($aClassifications['VUS'])) {
            // VUS and something else, not a conflict, but no consensus either.
            $aVariant['status'] = 'non-consensus';
            $aStatusCounts['non-consensus'] ++;

        } else {
            // Rest is consensus (possible LP/P or LB/B differences are ignored.
            $aVariant['status'] = 'consensus';
            $aStatusCounts['consensus'] ++;
        }
    }



    // Do some cleaning up.
    if (is_array($aVariant['chromosome'])) {
        // Multiple variants have been merged, but much information is duplicated.
        // We keep arrays only if needed.

        // Chromosome can't really be different.
        $aVariant['chromosome'] = current($aVariant['chromosome']);

        // Since we're grouping on variant, the gene doesn't have to be unique anymore.
        $aVariant['gene'] = array_unique($aVariant['gene']);
        if (count($aVariant['gene']) == 1) {
            $aVariant['gene'] = current($aVariant['gene']);
        }

        // Then, transcript.
        $aVariant['transcript'] = array_unique($aVariant['transcript']);
        if (count($aVariant['transcript']) == 1) {
            $aVariant['transcript'] = current($aVariant['transcript']);
        }

        // cDNA; we can have quite a few different values here.
        $aVariant['c_dna'] = array_unique($aVariant['c_dna']);
        if (count($aVariant['c_dna']) == 1) {
            $aVariant['c_dna'] = current($aVariant['c_dna']);
        } else {
            sort($aVariant['c_dna']);
        }

        // Protein; not sure how many centers provide this info.
        $aVariant['protein'] = array_unique($aVariant['protein']);
        if (count($aVariant['protein']) == 1) {
            $aVariant['protein'] = current($aVariant['protein']);
        } else {
            sort($aVariant['protein']);
        }

        // VariantOnGenome/DNA, we grouped on this, so just remove.
        $aVariant['VariantOnGenome/DNA'] = current($aVariant['VariantOnGenome/DNA']);
    }

    $nVariantsDone ++;
}

$lPadding = max(array_map('strlen', array_keys($aStatusCounts)));
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] Done.' . "\n" .
    implode("\n", array_map(function ($sKey, $nValue) { global $lPadding; return '                   ' . str_pad(ucfirst($sKey), $lPadding, ' ') . ' : ' . $nValue; }, array_keys($aStatusCounts), array_values($aStatusCounts))) . "\n\n");
?>
