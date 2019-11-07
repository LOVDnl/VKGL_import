#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2019-06-27
 * Modified    : 2019-11-07
 * Version     : 0.2.0
 * For LOVD    : 3.0-22
 *
 * Purpose     : Processes the VKGL consensus data, and creates or updates the
 *               VKGL data in the LOVD instance.
 *
 * Changelog   : 0.2    2019-11-07
 *               Better debugging, store the new VKGL IDs, improved diff
 *               formatting, better annotation of double submissions so we can
 *               remove them in the future.
 *               0.1    2019-07-18
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

// FIXME: When the position converter returns mappings that Mutalyzer cannot generate a protein change for because the
//  transcript or later versions of it is not found in the NC, we skip the transcript, and we don't store it, either.
//  This can be improved on, by taking in mappings that map into locations that we can generate the protein change for.
//  Notes: Position converter descriptions are *not* normalized. For variants on the reverse strand, this is a problem.
//         If you fix this, remove "numberConversion" as a method from the cache, so all variants will be repeated.

// Command line only.
if (isset($_SERVER['HTTP_HOST'])) {
    die('Please run this script through the command line.' . "\n");
}

// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$bDebug = false; // Are we debugging? If so, none of the queries actually take place.
$_CONFIG = array(
    'name' => 'VKGL data importer',
    'version' => '0.2.0',
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
    'mutalyzer_URL' => 'https://test.mutalyzer.nl/', // Test may be faster than www.mutalyzer.nl.
    'user' => array(
        // Variables we will be asking the user.
        'refseq_build' => 'hg19',
        'lovd_path' => '/www/databases.lovd.nl/shared/',
        'mutalyzer_cache_NC' => 'NC_cache.txt', // Stores NC g. descriptions and their corrected output.
        'mutalyzer_cache_mapping' => 'mapping_cache.txt', // Stores NC to NM mappings and the protein predictions.
        'vkgl_generic_id' => 0, // The LOVD ID of the generic VKGL account, needed for single lab submissions.
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

    // No variant?
    if ($sRef == $sAlt) {
        return false;
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

    return true;
}





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
$tStart = time() + date('Z', 0); // Correct for timezone, otherwise the start value is not 0.

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n");





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
$aCenterIDs = array();
if (!$_CONFIG['flags']['y']) {
    lovd_verifySettings('refseq_build', 'The genome build that the data file uses (hg19/hg38)', 'array', array('hg19', 'hg38'));
    if (!lovd_verifySettings('lovd_path', 'Path of LOVD installation to load data into', 'lovd_path', '')) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Failed to get LOVD path.' . "\n\n");
        die(EXIT_ERROR_CONNECTION_PROBLEM);
    }
    lovd_verifySettings('mutalyzer_cache_NC', 'File containing the Mutalyzer cache for genomic (NC) variants', 'file', '');
    lovd_verifySettings('mutalyzer_cache_mapping', 'File containing the Mutalyzer cache for mappings from genome to transcript', 'file', '');
    lovd_verifySettings('vkgl_generic_id', 'The LOVD user ID for the generic VKGL account', 'int', '1,99999');

    // Verify all centers.
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
// If I put a require here, I can't nicely handle errors, because PHP will die if something is wrong.
// However, I need to get rid of the "headers already sent" warnings from inc-init.php.
// So, sadly if there is a problem connecting to LOVD, the script will die here without any output whatsoever.
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // CLI logs errors to the screen, apparently.
// Let the LOVD believe we're accessing it through SSL. LOVDs that demand this, will otherwise block us.
// We have error messages surpressed anyway, as the LOVD in question will complain when it tries to define "SSL" as well.
define('SSL', true);
require ROOT_PATH . 'inc-init.php';
require ROOT_PATH . 'inc-lib-form.php';
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

// The other centers that we have collected from the input file.
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





// Load the caches, create if they don't exist. They can only not exist, when the defaults are used.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [      ] Loading Mutalyzer cache files...' . "\n");
$_CACHE = array();
foreach (array('mutalyzer_cache_NC', 'mutalyzer_cache_mapping') as $sKeyName) {
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
                if ($sKeyName == 'mutalyzer_cache_mapping') {
                    // The mapping cache has a JSON structure.
                    $_CACHE[$sKeyName][$aVariant[0]] = json_decode($aVariant[1], true);
                } else {
                    $_CACHE[$sKeyName][$aVariant[0]] = $aVariant[1];
                }
            } else {
                // Malformed line.
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(round($nCacheLine * 100 / $nCacheLines, 1), 1),
                        5, ' ', STR_PAD_LEFT) . '%] Warning: ' . ucfirst(str_replace('_', ' ', $sKeyName)) . ' line ' . $nCacheLine . ' malformed.' . "\n");
                $nWarningsOccurred ++;
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

    // Store data. We assume here that the ID field is unique.
    $aData[$aDataLine['id']] = array_intersect_key($aDataLine, array_flip($aColumnsToUse));
}

$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] VKGL file successfully parsed, found ' . $nVariants . ' variants.' . "\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Verifying genomic variants and creating mappings...' . "\n");

// We might be running for some time.
set_time_limit(0);





// Correct all genomic variants, using the cache. Skip substitutions.
// And don't bother using the database, we'll assume the cache knows it all.
$nVariantsLost = 0;
$nVariantsDone = 0;
$nVariantsAddedToCache = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.
foreach ($aData as $sID => $aVariant) {
    // VKGL stores chrM data as "MT".
    if ($aVariant['chromosome'] == 'MT') {
        $aVariant['chromosome'] = 'M';
    }

    if (!isset($_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences'][$aVariant['chromosome']])) {
        // Can't get chromosome's NC refseq?
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Variant ID ' . $sID . ' has unknown chromosome value ' . $aVariant['chromosome'] . ".\n\n");
        die(EXIT_ERROR_DATA_CONTENT_ERROR);
    }

    // Translate all classification values to easier values.
    // I need this cleaned up here already, so I can report which centers cause problems.
    $aVariant['classifications'] = array();
    foreach ($aCentersFound as $sCenter) {
        if ($aVariant[$sCenter]) {
            $aVariant['classifications'][$sCenter] = str_replace(array('likely ', 'benign', 'pathogenic', 'vus'),
                array('L', 'B', 'P', 'VUS'), strtolower($aVariant[$sCenter]));
        }
        unset($aVariant[$sCenter]);
    }

    // Use LOVD+'s lovd_getVariantDescription() to build the HGVS from the VCF fields.
    // Also adds fields that we currently won't use yet since we don't know yet if this HGVS is correct.
    $aVariant['position'] = $aVariant['start']; // The function needs this.
    if (!lovd_getVariantDescription($aVariant, $aVariant['ref'], $aVariant['alt'])) {
        // Returns false if Ref and Alt are equal (or both empty).
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] Warning: Error for variant ' . $sID . ' (' . implode(', ', array_keys($aVariant['classifications'])) . ").\n" .
            '                   Could not construct DNA field.' . "\n");
        $nWarningsOccurred ++;
        $nVariantsLost ++;
        $nVariantsDone ++;
        unset($aData[$sID]); // We don't want to continue working with this variant.
        continue; // Next variant.
    }

    // Previously we were skipping substitutions for this step, but runMutalyzerLight provides us with
    //  all mappings as well, as well as all protein predictions, and we still need those.
    // So to make the code much simpler, just run *everything* through here.
    $sVariant =
        $_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences'][$aVariant['chromosome']] .
        ':' . $aVariant['VariantOnGenome/DNA'];

    // The variant may be in the NC cache, but not yet in the mapping cache.
    // This happens when the NC cache is used by another application, which doesn't build the mapping cache as well.
    // Check if we need this call, if the variant is missing in one of the two caches.
    $bUpdateCache = !isset($_CACHE['mutalyzer_cache_NC'][$sVariant]);
    if (!$bUpdateCache) {
        // But check the mapping cache too!
        $sVariantCorrected = $_CACHE['mutalyzer_cache_NC'][$sVariant];

        // Check if this is not a cached error message.
        if ($sVariantCorrected{0} == '{') {
            // This is a cached error message. Report, but don't cache.
            $aError = json_decode($sVariantCorrected, true);

            // I'm not too happy duplicating this code.
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                    floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Warning: Error for variant ' . $sID . ' (' . implode(', ', array_keys($aVariant['classifications'])) . ").\n" .
                '                   It was sent as ' . $sVariant . ".\n" .
                (!$aError? '' : '                   Error: ' . implode("\n" . str_repeat(' ', 26), $aError) . "\n"));
            $nWarningsOccurred ++;
            $nVariantsLost ++;
            $nVariantsDone ++;
            unset($aData[$sID]); // We don't want to continue working with this variant.
            continue; // Next variant.
        }

        $bUpdateCache = !isset($_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected]);
    }

    // Update cache if needed.
    if ($bUpdateCache) {
        $aResult = json_decode(file_get_contents($_CONFIG['mutalyzer_URL'] . '/json/runMutalyzerLight?variant=' . $sVariant), true);
        if (!$aResult || !isset($aResult['genomicDescription'])) {
            // Error? Just report. They must be new variants, anyway.
            // If this is a recognized Mutalyzer error, we want to cache this as well, so we won't keep running into it.
            $aError = array();
            if (!empty($aResult['errors']) && isset($aResult['messages'])) {
                foreach ($aResult['messages'] as $aMessage) {
                    if (isset($aMessage['errorcode']) && $aMessage['errorcode'] == 'EREF') {
                        // Cache this error.
                        $aError['EREF'] = $aMessage['message'];
                    }
                }
                // Save to cache.
                file_put_contents($_CONFIG['user']['mutalyzer_cache_NC'], $sVariant . "\t" . json_encode($aError) . "\n", FILE_APPEND);
                $nVariantsAddedToCache ++;
            }

            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                        floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Warning: Error for variant ' . $sID . ' (' . implode(', ', array_keys($aVariant['classifications'])) . ").\n" .
                '                   It was sent as ' . $sVariant . ".\n" .
                (!$aError? '' : '                   Error: ' . implode("\n" . str_repeat(' ', 26), $aError) . "\n"));
            $nWarningsOccurred ++;
            $nVariantsLost ++;
            $nVariantsDone ++;
            unset($aData[$sID]); // We don't want to continue working with this variant.
            continue; // Next variant.
        }

        $sVariantCorrected = $aResult['genomicDescription'];



        // While we're here, let's not repeat this call later.
        // The given mappings are already corrected, so let's use them.
        $aMutalyzerTranscripts = array();
        $aMutalyzerMappings = array();
        foreach ($aResult['legend'] as $aLegend) {
            // We'll store all mappings, since we don't know which ones we want.
            if ($aVariant['chromosome'] == 'M' && empty($aLegend['id'])) {
                $aLegend['id'] = $_SETT['human_builds'][$_CONFIG['user']['refseq_build']]['ncbi_sequences'][$aVariant['chromosome']] .
                    '(' . $aLegend['name'] . ')';
            }
            $aMutalyzerTranscripts[$aLegend['name']] = $aLegend['id'];
        }

        // Loop mappings on the transcript, resolving the transcript IDs.
        foreach ($aResult['transcriptDescriptions'] as $sMapping) {
            list(,$sTranscript,, $sDNA) = preg_split('/[():]/', $sMapping);
            if (isset($aMutalyzerTranscripts[$sTranscript])) {
                // This should always be true, but I won't complain if this fails, whatever.
                $aMutalyzerMappings[$aMutalyzerTranscripts[$sTranscript]] = array(
                    'c' => $sDNA,
                );
            }
        }

        // Now get the protein descriptions, too.
        foreach ($aResult['proteinDescriptions'] as $sMapping) {
            list(,$sIsoform,, $sProtein) = preg_split('/[():]/', $sMapping, 4);
            // Generate transcript name (v-number) from protein isoform name (i-number).
            $sTranscript = str_replace('_i', '_v', $sIsoform);
            if (isset($aMutalyzerMappings[$aMutalyzerTranscripts[$sTranscript]])) {
                // This should always be true, but I won't complain if this fails, whatever.
                $aMutalyzerMappings[$aMutalyzerTranscripts[$sTranscript]]['p'] = $sProtein;
            }
        }

        // Add to caches; mapping cache and NC cache.
        // There are multiple descriptions that can lead to the same corrected variant,
        //  so we might know this mapping already from an uncached, different alternative description.
        if (!isset($_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected])) {
            // We trust the cache more than the new data.
            // This is actually mostly because we can't easily overwrite a previous line in the cache,
            //  and the cache may be manually updated. Adding a line to the cache may be reversed when the cache
            //  is resorted. All in all, to keep things consistent, let's stick to what we have.
            // Add only to the cache when we don't know the mappings of the corrected variant yet.
            // Indicate which method we used.
            $aMutalyzerMappings['methods'] = array('runMutalyzerLight');
            file_put_contents($_CONFIG['user']['mutalyzer_cache_mapping'], $sVariantCorrected . "\t" . json_encode($aMutalyzerMappings) . "\n", FILE_APPEND);
            $_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected] = $aMutalyzerMappings;
        }
        // Add to NC cache, if we didn't have it already.
        if (!isset($_CACHE['mutalyzer_cache_NC'][$sVariant])) {
            file_put_contents($_CONFIG['user']['mutalyzer_cache_NC'], $sVariant . "\t" . $sVariantCorrected . "\n", FILE_APPEND);
            $_CACHE['mutalyzer_cache_NC'][$sVariant] = $sVariantCorrected;
        }
        // Count as addition always, one of the caches should have been updated.
        $nVariantsAddedToCache ++;
    }

    // Store corrected variant description.
    $aVariant['VariantOnGenome/DNA'] = $sVariantCorrected;

    // Clean transcript, it sometimes ends in colon or comma.
    $aVariant['transcript'] = rtrim($aVariant['transcript'], ' ,:');

    // Store new information, dropping some excess information.
    unset($aVariant['position']); // Never needed.
    unset($aVariant['start'], $aVariant['ref'], $aVariant['alt']); // We're done using VCF now.
    unset($aVariant['position_g_start'], $aVariant['position_g_end'], $aVariant['type']); // Are unreliable now.
    $aData[$sID] = $aVariant;

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
    '                   Variants added to cache: ' . $nVariantsAddedToCache . ".\n" .
    '                   Variants lost: ' . $nVariantsLost . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Merging variants after corrections...' . "\n");





// Loop variants again, merging entries.
$nVariantsMerged = 0;
foreach ($aData as $sID => $aVariant) {
    // Merging makes reconstructing some fields much harder, so link them now.
    $aVariant['published_as'] = '';
    if ($aVariant['c_dna']) {
        $aVariant['published_as'] = $aVariant['gene'] . '(' . $aVariant['transcript'] . '):' . $aVariant['c_dna'];
    }
    if ($aVariant['protein']) {
        // We sometimes get multiple protein descriptions in one field.
        $sProtein = implode(', ', array_unique(array_map(function ($sValue) {
            if (strpos($sValue, ':') !== false) {
                list(, $sValue) = explode(':', $sValue);
            }
            return trim($sValue);
        }, explode(',', $aVariant['protein']))));
        $aVariant['published_as'] .= (!$aVariant['published_as']? '' : ' ') . '(' . $sProtein . ')';
    }

    // Simple merge.
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
foreach ($aData as $sVariant => $aVariant) {
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
    if ($bInternalConflict) {
        // One center had a conflict, so we all have a conflict.
        $aVariant['status'] = 'opposite';
        $aStatusCounts['opposite'] ++;

    } elseif (count($aVariant['classifications']) == 1) {
        $aVariant['status'] = 'single-lab';
        $aStatusCounts['single-lab'] ++;

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

        // Chromosome can't really be different.
        $aVariant['chromosome'] = current($aVariant['chromosome']);

        // Since we're grouping on variant, the gene doesn't have to be unique anymore.
        // We can get case-differences here, and I don't like that. array_unique() however, is case-sensitive.
        // This trick solves that problem.
        // https://stackoverflow.com/questions/2276349/case-insensitive-array-unique
        $aVariant['gene'] = array_intersect_key(
            $aVariant['gene'],
            array_unique(array_map('strtoupper', $aVariant['gene'])));

        // Then, transcript.
        $aVariant['transcript'] = array_unique($aVariant['transcript']);

        // cDNA; we can have quite a few different values here.
        $aVariant['c_dna'] = array_unique($aVariant['c_dna']);

        // Protein; not sure how many centers provide this info.
        $aVariant['protein'] = array_unique($aVariant['protein']);
        // Remove empty values, don't resort.
        if (($nKey = array_search('', $aVariant['protein'])) !== false) {
            unset($aVariant['protein'][$nKey]);
        }

        // Published as.
        $aVariant['published_as'] = array_unique($aVariant['published_as']);

        // VariantOnGenome/DNA, we grouped on this, so just remove.
        $aVariant['VariantOnGenome/DNA'] = current($aVariant['VariantOnGenome/DNA']);

    } else {
        // Better always have arrays here, which makes the code simpler.
        $aVariant['id'] = array($aVariant['id']);
        $aVariant['gene'] = array($aVariant['gene']);
        $aVariant['transcript'] = array($aVariant['transcript']);
        $aVariant['c_dna'] = array($aVariant['c_dna']);
        if ($aVariant['protein']) {
            $aVariant['protein'] = array($aVariant['protein']);
        } else {
            $aVariant['protein'] = array();
        }
        $aVariant['published_as'] = array($aVariant['published_as']);
    }

    $aData[$sVariant] = $aVariant;
    $nVariantsDone ++;
}

$lPadding = max(array_map('strlen', array_keys($aStatusCounts)));
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] Done.' . "\n" .
    implode("\n", array_map(
            function ($sKey, $nValue) {
                global $lPadding;
                return '                   ' . str_pad(ucfirst($sKey), $lPadding, ' ') . ' : ' . $nValue;
            }, array_keys($aStatusCounts), array_values($aStatusCounts))) . "\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Verifying transcript variants...' . "\n");





// Now correct all cDNA variants, using the cache, and predict RNA and protein.
$nVariantsDone = 0;
$nVariantsAddedToCache = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.

// Store all of LOVD's transcripts, we need them; array(id_ncbi => id).
$aTranscripts = $_DB->query('
    SELECT id_ncbi, id
    FROM ' . TABLE_TRANSCRIPTS . '
    ORDER BY id_ncbi')->fetchAllCombine();

foreach ($aData as $sVariant => $aVariant) {
    $aVariant['mappings'] = array(); // What we'll store in LOVD.

    // Check cache. If not found there, something went wrong.
    if (!isset($_CACHE['mutalyzer_cache_mapping'][$sVariant])) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Could not find mappings in the cache after checking for them.' . "\n\n");
        die(EXIT_ERROR_CACHE_UNREADABLE);
    }

    $aPossibleMappings = $_CACHE['mutalyzer_cache_mapping'][$sVariant];

    // Go through the possible mappings to see which ones we'll store, based on the transcripts we have in LOVD.
    foreach ($aPossibleMappings as $sTranscript => $aMapping) {
        if ($sTranscript == 'methods') {
            continue;
        }
        if (isset($aTranscripts[$sTranscript])) {
            // Match with LOVD's transcript.
            $aVariant['mappings'][$sTranscript] = array(
                'DNA' => $aMapping['c'],
                'protein' => (!isset($aMapping['p'])? '-' : $aMapping['p']), // Always set it.
            );
        }
    }

    // Check if we have anything now.
    if (!count($aVariant['mappings']) && (empty($aPossibleMappings['methods']) || !in_array('numberConversion', $aPossibleMappings['methods']))) {
        // Mutalyzer came up with none of LOVD's transcripts.
        // The problem with our method of using runMutalyzer for everything, is that you only use the NC,
        //  and as such you only get the latest transcripts. Mappings on older transcripts are not provided,
        //  even if that is the transcript that LOVD is using.
        // We cannot assume the transcripts are the same, and just copy the mapping.
        // Since we're using the NCs for the protein prediction, *if* the cDNA mapping of this variant on both the older
        //  and the newer transcript are the same, then the protein predictions will also be the same.
        // So the fastest thing to do is to do a numberConversion() and check.
        $aResult = json_decode(file_get_contents($_CONFIG['mutalyzer_URL'] . '/json/numberConversion?build=' . $_CONFIG['user']['refseq_build'] . '&variant=' . $sVariant), true);
        if ($aResult === false) {
            // Error? Just report. They must be new variants, anyway.
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                    floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Warning: Error for variant ' . $sVariant . ".\n" .
                '                   No LOVD transcript and Mutalyzer call failed.' . "\n");
            $nWarningsOccurred ++;
            $nVariantsDone ++;
            continue; // Next variant.
        }

        // Parse results.
        $aPositionConverterTranscripts = array();
        foreach ($aResult as $sResult) {
            list($sTranscript, $sDNA) = explode(':', $sResult, 2);
            $aPositionConverterTranscripts[$sTranscript] = $sDNA;
        }

        // OK, now loop the NC mappings again to compare to the position converter's output.
        // The NC mapping's DNA field may be different from the position converter's output. So we're not comparing DNA
        //  between those mappings, and only require the NC transcript version to also be in the position converter
        //  results. We could still try to get more transcripts by removing this requirement, and by also looking at DNA
        //  description matches between the NC data and the position converter. (FIXME)
        // For now, we're looking for NC given transcripts that are found in the position converter's output, with
        //  additional versions as well, that match each other's DNA field. Then it is assumed that the protein change
        //  will also match, and we add this new version to our mappings list. We then hope to have more chance
        //   comparing to transcripts in LOVD. The LOVD transcript can only be *older*.
        foreach ($aPossibleMappings as $sTranscript => $aMapping) {
            // Check if this transcript is in the numberConversion output, which is a requirement.
            if ($sTranscript == 'methods' || !isset($aPositionConverterTranscripts[$sTranscript])) {
                // We can't match, then.
                continue;
            }

            // Isolate version.
            list($sTranscriptNoVersion, $nVersion) = explode('.', $sTranscript, 2);
            for ($i = $nVersion; $i > 0; $i --) {
                // First, check if both transcripts have the same cDNA prediction.
                if (isset($aPositionConverterTranscripts[$sTranscriptNoVersion . '.' . $i])
                    && $aPositionConverterTranscripts[$sTranscript] == $aPositionConverterTranscripts[$sTranscriptNoVersion . '.' . $i]) {
                    // NumberConversion has the same results for both transcripts.
                    // That means the protein prediction based on the NC would be the same as well.
                    // Store this useful transcript in the cache.
                    $_CACHE['mutalyzer_cache_mapping'][$sVariant][$sTranscriptNoVersion . '.' . $i] = $aMapping;
                    // Now check if LOVD has this transcript, perhaps.
                    if (isset($aTranscripts[$sTranscriptNoVersion . '.' . $i])) {
                        // Match with LOVD's transcript, and Mutalyzer's numberConversion's results.
                        // Accept the corrected mapping of the newest transcript for the lower version which is in LOVD.
                        $aVariant['mappings'][$sTranscriptNoVersion . '.' . $i] = array(
                            'DNA' => $aMapping['c'],
                            'protein' => (!isset($aMapping['p'])? '' : $aMapping['p']), // Always set it.
                        );
                        break; // Next transcript!
                    }
                }
            }
        }



        // Update cache, whether this was useful or not. We don't want to keep repeating this call.
        // Yes, this will add an additional line to the cache for this variant. When reading the cache,
        //  the second line will overwrite the first. Sorting the cache will not cause problems.
        // However, we'll need to clean this cache in the future, and remove double mappings.
        $aPossibleMappings = $_CACHE['mutalyzer_cache_mapping'][$sVariant];
        if (empty($aPossibleMappings['methods'])) {
            // Older mappings don't have this value yet, but surely we have the data from there.
            $aPossibleMappings['methods'] = array('runMutalyzerLight');
        }
        $aPossibleMappings['methods'][] = 'numberConversion'; // This stops calling this method again.
        file_put_contents($_CONFIG['user']['mutalyzer_cache_mapping'], $sVariant . "\t" . json_encode($aPossibleMappings) . "\n", FILE_APPEND);
        // This doesn't actually always lead to a new variant mapping. It might as well just be the method "numberConversion" being added.
        $nVariantsAddedToCache ++;
    }



    // But, we don't support all mappings.
    foreach ($aVariant['mappings'] as $sTranscript => $aMapping) {
        if (in_array(substr($aMapping['DNA'], 0, 3), array('n.-', 'n.*'))) {
            // n.-123 or n.*123 positions aren't supported by LOVD, and it's unclear if this is correct HGVS.
            unset($aVariant['mappings'][$sTranscript]);
        }
    }



    // See if we solved it now.
    if (!count($aVariant['mappings'])) {
        // Nope...
        // Just report. We still want the variant.

        // Perhaps we can work around it even more, but I want to see if that is necessary to build.
        // Perhaps there is an older version of the transcripts, but the mapping doesn't match? Then just run name checker.
        // If this is not enough, then use mappingInfo to enforce mapping on an available transcript?
        // https://test.mutalyzer.nl/json/mappingInfo?LOVD_ver=3.0-21&build=hg19&accNo=NM_002225.3&variant=g.40680000C%3ET
        // This will work even if the transcript is too far away for Mutalyzer to annotate it.
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] Warning: No LOVD transcript for variant ' . $sVariant . ".\n" .
            '                   Given mappings: ' . implode(', ', array_diff(array_keys($aPossibleMappings), array('methods'))) . ".\n" .
            (empty($aPositionConverterTranscripts)? '' :
                '                   Also found: ' . implode(', ', array_diff(array_keys($aPositionConverterTranscripts), array_keys($aPossibleMappings))) . ".\n") .
            '                   VKGL data: ' . implode(', ', $aVariant['gene']) . '; ' . implode(', ', $aVariant['transcript']) . ".\n");
        $nWarningsOccurred ++;
        $nVariantsDone ++;
        $tProgressReported = microtime(true); // Don't report progress for a certain amount of time.
        $aData[$sVariant] = $aVariant; // But store updates.
        continue; // Next variant.
    }





    // Now, generate some more data (position fields, RNA field) and check the predicted protein field.
    foreach ($aVariant['mappings'] as $sTranscript => $aMapping) {
        // First, get positions for variant.
        $aMapping = array_merge(
            $aMapping,
            lovd_getVariantInfo($aMapping['DNA'], $sTranscript)
        );

        // We're not using lovd_getRNAProteinPrediction() because that's using SOAP and the normal runMutalyzer,
        //  and we already did that stuff. Also, this code below is better in predicting good RNA values.
        // We will be borrowing quite some logic though. It would be better if this was solved.
        $aMapping['RNA'] = 'r.(?)'; // Default.
        if (in_array($aMapping['protein'], array('', 'p.?', 'p.(=)'))) {
            // We'd want to check this.
            // Splicing.
            if (($aMapping['position_start_intron'] && abs($aMapping['position_start_intron']) <= 5)
                || ($aMapping['position_end_intron'] && abs($aMapping['position_end_intron']) <= 5)
                || ($aMapping['position_start_intron'] && !$aMapping['position_end_intron'])
                || (!$aMapping['position_start_intron'] && $aMapping['position_end_intron'])) {
                $aMapping['RNA'] = 'r.spl?';
                $aMapping['protein'] = 'p.?';

            } elseif ($aMapping['position_start_intron'] && $aMapping['position_end_intron']
                && abs($aMapping['position_start_intron']) > 5 && abs($aMapping['position_end_intron']) > 5
                && ($aMapping['position_start'] == $aMapping['position_end'] || $aMapping['position_start'] == ($aMapping['position_end'] + 1))) {
                // Deep intronic.
                $aMapping['RNA'] = 'r.(=)';
                $aMapping['protein'] = 'p.(=)';

            } else {
                // No introns involved.
                if ($aMapping['position_start'] < 0 && $aMapping['position_end'] < 0) {
                    // Variant is upstream.
                    $aMapping['RNA'] = 'r.(?)';
                    $aMapping['protein'] = 'p.(=)';

                } elseif ($aMapping['position_start'] < 0 && strpos($aMapping['DNA'], '*') !== false) {
                    // Start is upstream, end is downstream.
                    if ($aMapping['type'] == 'del') {
                        $aMapping['RNA'] = 'r.0?';
                        $aMapping['protein'] = 'p.0?';
                    } else {
                        $aMapping['RNA'] = 'r.?';
                        $aMapping['protein'] = 'p.?';
                    }

                } elseif (strpos($aMapping['DNA'], 'c.*') === 0 && ($aMapping['type'] == 'subst' || substr_count($aMapping['DNA'], '*') > 1)) {
                    // Variant is downstream.
                    $aMapping['RNA'] = 'r.(=)';
                    $aMapping['protein'] = 'p.(=)';

                } elseif ($aMapping['type'] != 'subst' && $aMapping['protein'] != 'p.(=)') {
                    // Deletion/insertion partially in the transcript, not predicted to do nothing.
                    $aMapping['RNA'] = 'r.?';
                    $aMapping['protein'] = 'p.?';

                } else {
                    // Substitution on wobble base or so.
                    $aMapping['RNA'] = 'r.(?)';
                }
            }
        }

        // Empty the protein prediction again for NR transcripts.
        if (in_array(substr($sTranscript, 0, 2), array('NR', 'XR'))) {
            $aMapping['protein'] = '-';
        }

        // We don't need type anymore, either.
        unset($aMapping['type']);

        // We should have RNA and protein descriptions now. Store.
        $aVariant['mappings'][$sTranscript] = $aMapping;
    }



    // Store the updates into the data array.
    $aData[$sVariant] = $aVariant;

    // Print update, for every percentage changed.
    $nVariantsDone ++;
    if ((microtime(true) - $tProgressReported) > 5 && $nVariantsDone != $nVariants
        && floor($nVariantsDone * 1000 / $nVariants) != $nPercentageComplete) {
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] ' .
            str_pad($nVariantsDone, strlen($nVariants), ' ', STR_PAD_LEFT) . ' transcript variants verified...' . "\n");
        $tProgressReported = microtime(true); // Don't report again for a certain amount of time.
    }
}

// Last message.
$nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
        5, ' ', STR_PAD_LEFT) . '%] ' .
    $nVariantsDone . ' transcript variants verified.' . "\n" .
    '                   Variants added to cache: ' . $nVariantsAddedToCache . ".\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Downloading VKGL data from LOVD and preparing update...' . "\n");





// Process updates in the database.
ksort($aData);
$nVariantsDone = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.
$aAddToCache = array(); // LOVD variants that we don't know from the cache. Could help to add it.

$aVariantsCreated = array(); // Counters per chromosome.
$aVariantsUpdated = array(); // Counters per chromosome.
$aVariantsDeleted = array(); // Counters per chromosome.
$aVariantsSkipped = array(); // Counters per chromosome.
$sNow = date('Y-m-d H:i:s');

// Process updates per chromosome, but show progress over the total number of variants.
$sRefSeq = ''; // The RefSeq (NC) we're currently working on.
$sPrevRefSeq = ''; // The one (NC) we were working on before.
$sChromosome = ''; // The chromosome we're currently working on, derived from $sRefSeq.

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
            // Eh? It did work the other way around before...
            lovd_printIfVerbose(VERBOSITY_LOW,
                'Error: Cannot find chromosome belonging to ' . $_CONFIG['user']['refseq_build'] . ':' . $sRefSeq . ".\n\n");
            die(EXIT_ERROR_DATA_CONTENT_ERROR);
        }

        // Reset counters.
        $aVariantsCreated[$sChromosome] = 0;
        $aVariantsUpdated[$sChromosome] = 0;
        $aVariantsDeleted[$sChromosome] = 0;
        $aVariantsSkipped[$sChromosome] = 0;

        // Load the data currently in the database.
        // Note, that if there are two entries of the same variant by the same center, we see only *one*.
        $_DB->query('SET group_concat_max_len = 10000');
        $aDataLOVD = $_DB->query('
            SELECT CONCAT(vog.created_by, ":", ?, ":", vog.`VariantOnGenome/DNA`) AS ID,
              vog.id, vog.allele, vog.effectid, vog.chromosome, vog.position_g_start, vog.position_g_end, vog.type,
              vog.created_by, vog.owned_by, vog.statusid, vog.`VariantOnGenome/DNA`,
              vog.`VariantOnGenome/DBID`, vog.`VariantOnGenome/Genetic_origin`, vog.`VariantOnGenome/Published_as`,
              vog.`VariantOnGenome/Remarks`, vog.`VariantOnGenome/Remarks_Non_Public`,
              GROUP_CONCAT(vot.transcriptid, ";", vot.effectid, ";",
                IFNULL(vot.position_c_start, "0"), ";",
                IFNULL(vot.position_c_start_intron, "0"), ";",
                IFNULL(vot.position_c_end, "0"), ";",
                IFNULL(vot.position_c_end_intron, "0"), ";",
                IFNULL(NULLIF(vot.`VariantOnTranscript/Classification`, ""), "-"), ";",
                IFNULL(NULLIF(vot.`VariantOnTranscript/DNA`, ""), "-"), ";",
                IFNULL(NULLIF(vot.`VariantOnTranscript/RNA`, ""), "-"), ";",
                IFNULL(NULLIF(vot.`VariantOnTranscript/Protein`, ""), "-") SEPARATOR ";;") AS vots
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
            // Check if it exists in the NC cache as a different name. This assumes the variant has been cached before.
            if (isset($_CACHE['mutalyzer_cache_NC'][$sLOVDVariant])) {
                $sVariantCorrected = $_CACHE['mutalyzer_cache_NC'][$sLOVDVariant];

                // Check if this is a cached error message.
                if ($sVariantCorrected{0} == '{') {
                    // Variant is actually in error. These are OK to be removed, since we don't want them.
                    // If the variant is still in the source, that's OK, because he will be skipped there, too.
                    $bRemoveVariant = true;
                    $aErrorMessages = json_decode($sVariantCorrected, true);
                    array_walk($aErrorMessages, function (&$sValue, $sError) { $sValue = $sError . ': ' . $sValue; });
                    $sRemoveMessage = 'Variant is in error: ' . implode('; ', $aErrorMessages);

                } elseif ($sLOVDVariant != $sVariantCorrected) {
                    // LOVD variant is in the cache, and has a different name.

                    // Whoops. From a previous release, we have uncorrected data in LOVD. It won't match this way.
                    // Correct the key; this will make a match possible. The update will then fix the entry's DNA field.
                    $sLOVDNewKey = $nCenter . ':' . $sVariantCorrected;
                    if (!isset($aDataLOVD[$sLOVDNewKey])) {
                        // Copy data, correct variant doesn't exist in LOVD yet.
                        $aDataLOVD[$sLOVDNewKey] = $aLOVDVariant;
                        unset($aDataLOVD[$sLOVDKey]);
                        continue;
                    } else {
                        // We have an old notation for this center, but also the corrected.
                        // Let the corrected match with the variant in case we still have it, remove this old one.
                        $bRemoveVariant = true;
                        $sRemoveMessage = 'Variant notation is not normalized, and the correct notation (' . $sVariantCorrected . ') is already in the database for this center.';
                    }
                }

                if (!$bRemoveVariant && $_CONFIG['user']['delete_redundant_variants'] == 'y'
                    && (!isset($aData[$sVariantCorrected]) || !isset($aData[$sVariantCorrected]['classifications'][$sCenter]))) {
                    // We aren't already removing this variant, but we don't actually see this variant anymore.
                    // The variant is lost, there's nothing to do about it. If the user has indicated so, remove it,
                    //  but mark it only as removed. Later we can always decide to actually remove these entries.
                    $bRemoveVariant = true;
                    $sRemoveMessage = 'Variant no longer found in the VKGL dataset for this center.';
                }

            } elseif (!in_array($sLOVDVariant, $_CACHE['mutalyzer_cache_NC'])) {
                // We haven't seen this variant before. Not as an input to the cache, not as a result of the cache.
                // The variant seems to be lost, but we can't be sure because it's maybe described incorrectly.
                // Maybe it helps if we add it. We won't implement that here.

                // Before we recommend adding it to the cache, check its nomenclature. If not HGVS, ignore it.
                // This is not a great HGVS check; this function does allow some non-HGVS descriptions.
                $bHGVS = (bool) lovd_getVariantInfo($sLOVDVariant);
                if ($bHGVS) {
                    $aAddToCache[] = $sLOVDVariant;
                    continue;
                } else {
                    // We're not recommending to cache it. Get rid of it.
                    $bRemoveVariant = true;
                    $sRemoveMessage = 'Variant is in error, not using correct HGVS.';
                }
            }

            // Remove variant if needed. Don't touch the Remarks_Non_Public, we don't want to complicate things.
            // Also, don't run this if we don't have to. Check status and current remarks.
            if ($bRemoveVariant && !$bDebug) {
                $sRemoveMessage = 'VKGL data sharing initiative Nederland' .
                    (!$sRemoveMessage? '' : '; ' . $sRemoveMessage);
                $q = $_DB->query('UPDATE ' . TABLE_VARIANTS . '
                                  SET `VariantOnGenome/Remarks` = ?, statusid = ?, edited_by = 0, edited_date = NOW()
                                  WHERE id = ? AND !(`VariantOnGenome/Remarks` LIKE ? AND statusid <= ?)',
                    array(
                        $sRemoveMessage,
                        STATUS_HIDDEN,
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



    // Add some needed fields; (type, position_start, position_end).
    $aVariant = array_merge(
        $aVariant,
        lovd_getVariantInfo($sDNA)
    );

    // We've built the "Published as" field before merging the entries, which made it much easier.
    sort($aVariant['published_as']);
    $aVariant['published_as'] = implode(', ', $aVariant['published_as']);
    // Do limit the input a bit, 150 should be enough.
    $aVariant['published_as'] = lovd_shortenString($aVariant['published_as'], 150);

    // Loop through centers who found this variant.
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
            'owned_by' => ($aVariant['status'] == 'single-lab'? // Single lab gets the generic VKGL account as owner.
                $_CONFIG['user']['vkgl_generic_id'] : $aCenterIDs[$sCenter]),
            'statusid' => (string) ($aVariant['status'] == 'opposite'? STATUS_HIDDEN : STATUS_OK), // FIXME: Set to Marked if a warning occurred within this variant? Or like, when not having a mapping?
            'VariantOnGenome/DNA' => $sDNA, // Can actually also update, if the LOVD data is not correct.
            'VariantOnGenome/DBID' => '', // FIXME: Will be filled in later for records to be created!
            'VariantOnGenome/Genetic_origin' => 'CLASSIFICATION record',
            'VariantOnGenome/Published_as' => $aVariant['published_as'],
            'VariantOnGenome/Remarks' => 'VKGL data sharing initiative Nederland',
            'VariantOnGenome/Remarks_Non_Public' => array(
                'warning' => 'Do not remove or edit this field!',
                'ids' => $aVariant['id'],
                'updates' => array(),
            ),
            'vots' => array(),
        );

        // Fill VOTs.
        foreach ($aVariant['mappings'] as $sTranscript => $aMapping) {
            $aVOGEntry['vots'][$aTranscripts[$sTranscript]] = array(
                'transcriptid' => $aTranscripts[$sTranscript],
                'effectid' => $aVOGEntry['effectid'],
                'position_c_start' => $aMapping['position_start'],
                'position_c_start_intron' => $aMapping['position_start_intron'],
                'position_c_end' => $aMapping['position_end'],
                'position_c_end_intron' => $aMapping['position_end_intron'],
                // Don't let internal conflicts cause notices here.
                'VariantOnTranscript/Classification' => (!isset($_CONFIG['effect_mapping_classification'][$sClassification])? '-' :
                    $_CONFIG['effect_mapping_classification'][$sClassification]),
                'VariantOnTranscript/DNA' => $aMapping['DNA'],
                'VariantOnTranscript/RNA' => $aMapping['RNA'],
                'VariantOnTranscript/Protein' => $aMapping['protein'],
            );
        }
        // For comparison reasons.
        ksort($aVOGEntry['vots']);

        // If this entry already exists, simply update the record when needed.
        if (isset($aDataLOVD[$sLOVDKey])) {
            // Variant has been seen already by this center.

            // Make it easier to compare with our array.
            // Build array from JSON object, if we have it.
            if ($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public']) {
                $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] = json_decode($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'], true);
                if ($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] === false
                    || !is_array($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'])) {
                    // Somebody malformed this field...
                    lovd_printIfVerbose(VERBOSITY_LOW,
                        'Error: Variant ID ' . $sVariant . ' has an unparsable JSON object for center ' . $sCenter . '(' . $aCenterIDs[$sCenter] . ').' . "\n\n");
                    die(EXIT_ERROR_DATA_CONTENT_ERROR);
                }
            } else {
                $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public'] = array();
            }
            // Rebuild VOTs.
            if (!$aDataLOVD[$sLOVDKey]['vots']) {
                $aDataLOVD[$sLOVDKey]['vots'] = array();
            } else {
                $aVOTs = explode(';;', $aDataLOVD[$sLOVDKey]['vots']);
                $aDataLOVD[$sLOVDKey]['vots'] = array();
                foreach ($aVOTs as $sVOT) {
                    $aVOT = explode(';', $sVOT);
                    $aDataLOVD[$sLOVDKey]['vots'][$aVOT[0]] = array(
                        'transcriptid' => $aVOT[0],
                        'effectid' => $aVOT[1],
                        'position_c_start' => $aVOT[2],
                        'position_c_start_intron' => $aVOT[3],
                        'position_c_end' => $aVOT[4],
                        'position_c_end_intron' => $aVOT[5],
                        'VariantOnTranscript/Classification' => $aVOT[6],
                        'VariantOnTranscript/DNA' => $aVOT[7],
                        'VariantOnTranscript/RNA' => $aVOT[8],
                        'VariantOnTranscript/Protein' => $aVOT[9],
                    );
                }
                ksort($aDataLOVD[$sLOVDKey]['vots']);
            }

            // Make my life easier, just copy some values.
            $aVOGEntry['id'] = $aDataLOVD[$sLOVDKey]['id'];
            $aVOGEntry['VariantOnGenome/DBID'] = $aDataLOVD[$sLOVDKey]['VariantOnGenome/DBID'];
            $aVOGEntry['VariantOnGenome/Remarks_Non_Public'] = array_merge(
                $aVOGEntry['VariantOnGenome/Remarks_Non_Public'],
                $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks_Non_Public']
            );
            // But still store the new ID, if not yet included.
            foreach ($aVariant['id'] as $sNewID) {
                if (!in_array($sNewID, $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['ids'])) {
                    $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['ids'][] = $sNewID;
                }
            }

            // NOTE: This is debugging code. It checks the differences, and reports them, instead of running the update.
            if ($bDebug) {
                // Reduce the differences, by adapting the LOVD record a bit already.
                if ($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks'] == 'VKGL data sharing initiative Nederland; correct HGVS to be checked') {
                    $aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks'] = $aVOGEntry['VariantOnGenome/Remarks'];
                }
                // Don't mention ins to dups, that's the logical result of our checking.
                if ($aDataLOVD[$sLOVDKey]['type'] == 'ins' && $aVOGEntry['type'] == 'dup') {
                    $aDataLOVD[$sLOVDKey]['type'] = $aVOGEntry['type'];
                }
                // My "Published as" is often better. Calculate how much of the original I have.
                // Differences that we found where mostly c.* variants now mapped to CDS variants. Otherwise, gene symbol changes but keeping the same transcripts.
                // So if we have some kind of percentage, I'm happy already.
                if (!$aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as']
                    || ($nPercentageMatch = similar_text(
                            $aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'],
                            $aVOGEntry['VariantOnGenome/Published_as'])
                        / strlen($aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as']) * 100) >= 40) {
                    // Good enough.
                    $aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'] = $aVOGEntry['VariantOnGenome/Published_as'];
                } else {
                    // Not sure about this one. Keep the difference to report, but add the matching percentage,
                    //  so we can see if we need to lower the threshold.
                    $aVOGEntry['VariantOnGenome/Published_as'] .= ' (' . round($nPercentageMatch, 2) . ')';
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
                    // Also report differences.
                    if ($sKey == 'vots') {
                        // We won't report changes per field here, just per transcript.
                        foreach (array_unique(array_merge(array_keys($aDiff['vots'][0]), array_keys($aDiff['vots'][1]))) as $nTranscriptID) {
                            if (!isset($aDiff['vots'][0][$nTranscriptID])) {
                                $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Added mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                            } elseif (!isset($aDiff['vots'][1][$nTranscriptID])) {
                                $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Removed mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                            } elseif ($aDiff['vots'][0][$nTranscriptID] != $aDiff['vots'][1][$nTranscriptID]) {
                                $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Updated mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                            }
                        }
                    } elseif ($sKey != 'VariantOnGenome/Remarks_Non_Public') {
                        // Don't self-report, of course.
                        $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey] = array($Value, $aVOGEntry[$sKey]);
                    }
                }
            }
            // Because we were building this while building up the diff array:
            if ($aDiff && !$bDebug) {
                $aDiff['VariantOnGenome/Remarks_Non_Public'][1] = $aVOGEntry['VariantOnGenome/Remarks_Non_Public'];
            }

            if ($bDebug) {
                // When the classification changes and becomes just a bit more or less sure, it's fine.
                // Do check if the concluded effect doesn't change.
                if (isset($aDiff['effectid']) && substr($aDiff['effectid'][0], -1) == substr($aDiff['effectid'][1], -1)) {
                    $aEffects = array(
                        substr($aDiff['effectid'][0], 0, 1),
                        substr($aDiff['effectid'][1], 0, 1),
                    );
                    sort($aEffects);
                    if (in_array(implode('', $aEffects), array('13', '35', '57', '79'))) {
                        unset($aDiff['effectid']);
                        // And do the same in the vots.
                        if (isset($aDiff['vots'])) {
                            foreach (array(0, 1) as $nKey) {
                                $aDiff['vots'][$nKey] = array_map(function ($aVOT) {
                                    unset($aVOT['effectid'], $aVOT['VariantOnTranscript/Classification']);
                                    return $aVOT;
                                }, $aDiff['vots'][$nKey]);
                            }
                        }
                    }
                }
                // If diff is only the status change, it's fine.
                if (count($aDiff) == 1 && isset($aDiff['statusid'])) {
                    unset($aDiff['statusid']);
                }
                // Hide the JSON object, we know it works.
                unset($aDiff['VariantOnGenome/Remarks_Non_Public']);
                // Clean VOT changes, by removing VOTs from the diff array that are also in the new data.
                if (isset($aDiff['vots'])) {
                    // Loop the original data's VOTs and see if we can find them in the new data.
                    foreach ($aDiff['vots'][0] as $nTranscriptID => $aLOVDVot) {
                        // Often the problem is that our RNA is better (and sometimes our Protein as well).
                        if (isset($aDiff['vots'][1][$nTranscriptID])
                            && in_array($aDiff['vots'][0][$nTranscriptID]['VariantOnTranscript/RNA'], array('r.(=)', '-'))
                            && in_array($aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/RNA'], array('r.(=)', 'r.(?)', 'r.spl?'))) {
                            $aLOVDVot['VariantOnTranscript/RNA'] = $aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/RNA'];
                            if (in_array($aDiff['vots'][0][$nTranscriptID]['VariantOnTranscript/Protein'], array('p.(=)', '-'))) {
                                $aLOVDVot['VariantOnTranscript/Protein'] = $aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/Protein'];
                            }
                        }
                        // Or, the DNA changed because we fixed it, but the protein change is the same.
                        if (isset($aDiff['vots'][1][$nTranscriptID])
                            && $aDiff['vots'][0][$nTranscriptID]['VariantOnTranscript/DNA'] != $aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/DNA']
                            && $aLOVDVot['VariantOnTranscript/Protein'] == $aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/Protein']) {
                            $aLOVDVot['VariantOnTranscript/DNA'] = $aDiff['vots'][1][$nTranscriptID]['VariantOnTranscript/DNA'];
                        }
                        // If the same, toss.
                        if (isset($aDiff['vots'][1][$nTranscriptID]) && $aLOVDVot == $aDiff['vots'][1][$nTranscriptID]) {
                            // We have this one also in the new data. It's unchanged.
                            unset($aDiff['vots'][0][$nTranscriptID]);
                            unset($aDiff['vots'][1][$nTranscriptID]);
                        }
                    }
                    if (!count($aDiff['vots'][0])) {
                        // No real diff. Just additions by us.
                        unset($aDiff['vots']);
                    }
                }

                if ($aDiff) {
                    var_dump($sVariant . ' (' . count($aVOGEntry['vots']) . ' VOTs)', $aDiff);
                }
                $aVariantsUpdated[$sChromosome] ++;
                continue;
            }



            // Run update, if needed.
            if ($aDiff && !$bDebug) {
                // Update atomically, we don't want half updates.
                $_DB->beginTransaction();

                // Start with the VOTs.
                if (isset($aDiff['vots'])) {
                    foreach (array_unique(array_merge(array_keys($aDiff['vots'][0]), array_keys($aDiff['vots'][1]))) as $nTranscriptID) {
                        if (!isset($aDiff['vots'][0][$nTranscriptID])) {
                            // Add the transcript.
                            $aVOT = $aDiff['vots'][1][$nTranscriptID];
                            $_DB->query('INSERT INTO ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                              (id, ' . implode(', ', array_map(function ($sField) {
                                  return '`' . $sField . '`';
                              }, array_keys($aVOT))) . ')
                              VALUES (?' . str_repeat(', ?', count($aVOT)) . ')', array_merge(array($aVOGEntry['id']), array_values($aVOT)));

                        } elseif (!isset($aDiff['vots'][1][$nTranscriptID])) {
                            // Remove the transcript.
                            $_DB->query('DELETE FROM ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                              WHERE id = ? AND transcriptid = ?', array($aVOGEntry['id'], $nTranscriptID));

                        } elseif ($aDiff['vots'][0][$nTranscriptID] != $aDiff['vots'][1][$nTranscriptID]) {
                            // Update the transcript, remove 'transcriptid' as an updateable field (it shouldn't be there, but still).
                            $aFieldsToUpdate = array_diff_key($aDiff['vots'][1][$nTranscriptID], array('transcriptid' => 0));
                            $_DB->query('UPDATE ' . TABLE_VARIANTS_ON_TRANSCRIPTS . ' SET ' .
                                implode(', ', array_map(function ($sField) {
                                    return '`' . $sField . '` = ?';
                                }, array_keys($aFieldsToUpdate))) . '
                              WHERE id = ? AND transcriptid = ?', array_merge(array_values($aFieldsToUpdate), array($aVOGEntry['id'], $nTranscriptID)));
                        }
                    }
                    unset($aDiff['vots']); // So we don't run into it anymore.
                }

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

                $_DB->query('UPDATE ' . TABLE_VARIANTS . ' SET ' .
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





        } elseif (!$aAddToCache && !$bDebug) {
            // Variant has not been seen yet by this center. Create it in the database.
            // Do this only, if we don't have LOVD variants that need to be cached.

            // Prepare additional data.
            $aVOGEntry['created_date'] = $sNow;
            $aVOGEntry['VariantOnGenome/Remarks_Non_Public'] = json_encode($aVOGEntry['VariantOnGenome/Remarks_Non_Public']);
            // We can be more correct here by adding VOT data, but this function expects that in quite a complex manner.
            $aVOGEntry['VariantOnGenome/DBID'] = lovd_fetchDBID($aVOGEntry);

            // Run atomically, we don't want half inserts.
            $_DB->beginTransaction();

            // Insert the VOG first.
            $aVOTs = $aVOGEntry['vots'];
            unset($aVOGEntry['vots']);
            $aFields = array_keys($aVOGEntry);
            $_DB->query('INSERT INTO ' . TABLE_VARIANTS . '
                             (' . implode(', ', array_map(function ($sField) {
                    return '`' . $sField . '`';
                }, $aFields)) . ')
                         VALUES (?' . str_repeat(', ?', count($aFields) - 1) . ')', array_values($aVOGEntry));
            $aVOGEntry['id'] = $_DB->lastInsertId();

            // Then the VOTs.
            foreach ($aVOTs as $nTranscriptID => $aVOT) {
                // Add the transcript.
                $_DB->query('INSERT INTO ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                                 (id, ' . implode(', ', array_map(function ($sField) {
                        return '`' . $sField . '`';
                    }, array_keys($aVOT))) . ')
                             VALUES (?' . str_repeat(', ?', count($aVOT)) . ')', array_merge(array($aVOGEntry['id']), array_values($aVOT)));
            }

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

// Final message.
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

if ($aAddToCache) {
    $nAddToCache = count($aAddToCache);
    lovd_printIfVerbose(VERBOSITY_LOW,
    'Notice: ' . $nAddToCache . ' LOVD variant' . ($nAddToCache == 1? ' was' : 's were') . ' not found in the VKGL dataset, and also not found in the cache.' . "\n" .
    'This may mean that LOVD contains non-normalized variants, which may not match to the VKGL data' . "\n" .
    ' simply because a different description was used. To be safe, no inserts were done because of this reason.' . "\n" .
    'Please check the list below, and add these variants to the NC cache. After that, run this script again.' . "\n" .
    implode("\n", $aAddToCache) . "\n\n");
}

if ($nWarningsOccurred) {
    die(EXIT_WARNINGS_OCCURRED);
}
?>
