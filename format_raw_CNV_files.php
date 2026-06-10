#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2019-11-13
 * Modified    : 2025-08-11
 * Version     : 0.2.2
 *
 * Purpose     : Parses the VKGL center's raw data files (of different formats)
 *               and creates one consensus data file which can then be processed
 *               by the process_VKGL_data.php script.
 *
 * Changelog   : 0.2.2  2025-08-11
 *               Allow for genomic variants starting with "m."; this is normal
 *               for mitochondrial genes.
 *             : 0.2.1  2025-05-01
 *               Added more variant types to lovd_HGVStoVCF();
 *               deletion-insertions and inversions.
 * Changelog   : 0.2.0  2025-02-07
 *               Re-implement the storage of variants and the filtering of
 *               duplicates completely. We were losing variants when only a
 *               single center reported multiple classifications. Fixed that and
 *               handle classification differences neatly (i.e., opposites are
 *               reported and only the center's opinion is removed, not the
 *               entire variant; VUS+anything -> VUS, B+LB -> LB; LP+P -> LP).
 *               Also, the *_link fields can now contain multiple values, and
 *               empty values are removed from the protein fields.
 *               0.1.9  2024-08-28
 *               Silently skip Leiden's WT variants (g.123456=) that were
 *               recently introduced and break this script.
 *               0.1.8  2024-04-19
 *               Sigh... yet another Alissa data signature.
 *               0.1.7  2023-01-10
 *               Added yet another file header signature; perhaps the final one?
 *               It seems now we really have the raw Alissa data.
 *               0.1.6  2022-11-01
 *               Improved warning reporting; they can now easily be grepped.
 *               0.1.5  2021-09-13
 *               Added yet another file header signature, we keep receiving
 *               different files each time.
 *               0.1.4  2021-02-09
 *               Added handling duplicate variants in one file; the VUMC list
 *               now consists of two files that have a small overlap.
 *               0.1.3  2020-06-29
 *               Fixed bug; Now also handling file headers with quotes.
 *               0.1.2  2020-03-23
 *               Fixed bug; no longer assume the centers' files ares sorted
 *               alphabetically.
 *               0.1.1  2019-12-10
 *               Added alternative Alissa format, since we're receiving
 *               something else now.
 *               0.1.0  2019-11-14
 *               Initial release.
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

// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$bDebug = false; // Are we debugging? If so, none of the queries actually take place.
$_CONFIG = array(
    'name' => 'VKGL raw data formatter',
    'version' => '0.2.2',
    'settings_file' => 'settings.json',
    'flags' => array(
        'y' => false,
    ),
    'columns_mandatory' => array(
        // These are the columns that need to be present in order for the file to get processed.
        'dna',
    ),
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
    'header_signatures' => array(
        'HGVS;build;chromosome;classification;description;genes;inside start;inside stop;location;outside start;outside stop;p/q arm;protocol;type' => 'radboud',
    ),
    'mutalyzer_URL' => 'https://v2.mutalyzer.nl/',
    'user' => array(
        // Variables we will be asking the user.
        'consensus_file' => 'vkgl_consensus_' . date('Y-m-d') . '.tsv',
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
// We need at least one argument, the file(s) to convert.
$nArgsRequired = 1;

$sScriptName = array_shift($aArgs);
$nArgs --;
$nWarningsOccurred = 0;

// Determine ROOT_PATH. We need to load the LOVD HGVS library.
define('ROOT_PATH', dirname($sScriptName));
if (!file_exists(ROOT_PATH . '/libs/HGVS-syntax-checker/HGVS.php')) {
    // This script requires the HGVS.php class file from https://github.com/LOVDnl/HGVS-syntax-checker.
    // If not found, double-check if you ran `git submodule init && git submodule update`.
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Could not load the LOVD HGVS library. Please check the installation instructions in README.md.' . "\n\n");
    die(EXIT_ERROR_CONNECTION_PROBLEM);
}
require ROOT_PATH . '/libs/HGVS-syntax-checker/HGVS.php';

if ($nArgs < $nArgsRequired) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n" .
        'Usage: ' . $sScriptName . ' file_center_A.txt [file_center_B.txt [ ... ]] [-y]' . "\n\n");
    die(EXIT_ERROR_ARGS_INSUFFICIENT);
}

// Parse arguments and flags.
$aFiles = array();
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

    } elseif (file_exists($sArg)) {
        $aFiles[] = $sArg;
    }
}
$bCron = (empty($_SERVER['REMOTE_ADDR']) && empty($_SERVER['TERM']));
define('VERBOSITY', ($bCron? 5 : 7));
// Record the start of the script, but correct for the timezone. This way, (time() - $tStart) doesn't seem to make sense
//  to us human readers, but when used in combination with date('H:i:s', ...) to format hours, minutes, and seconds
//  spent, it all makes sense. Note that date("H:i:s", 0) only returns 00:00:00 when your timezone is GMT.
$tStart = time() + date('Z', 0);

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n");





// Check files passed as an argument.
foreach ($aFiles as $sFile) {
    if (!file_exists($sFile) || !is_file($sFile)) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Input is not a file:' . $sFile . ".\n\n");
        die(EXIT_ERROR_INPUT_NOT_A_FILE);
    }
    if (!is_readable($sFile)) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Unreadable input file:' . $sFile . ".\n\n");
        die(EXIT_ERROR_INPUT_UNREADABLE);
    }
}



// Isolate the center names from the file names.
// Verify these and store.
$aCentersFound = array();
$nCentersFound = 0;

foreach ($aFiles as $nKey => $sFile) {
    list($sName, $sExt) = explode('.', basename($sFile), 2);
    // If the name contains a date, take that off.
    if (preg_match('/^([^0-9]+)[_-][0-9-]+$/', $sName, $aRegs)) {
        $sName = strtolower($aRegs[1]);
    }
    $aCentersFound[] = $sName;
    $nCentersFound ++;

    // Make file key in array, so we can store metadata.
    $aFiles[$sFile] = $sName;
    unset($aFiles[$nKey]);
}





// Get settings file, if it exists.
$_SETT = array();
if (file_exists($_CONFIG['settings_file']) && is_file($_CONFIG['settings_file'])
    && is_readable($_CONFIG['settings_file'])) {
    if (!($_SETT = json_decode(file_get_contents($_CONFIG['settings_file']), true))) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Unreadable settings file.' . "\n\n");
        die(EXIT_ERROR_SETTINGS_UNREADABLE);
    }
}

// The settings file always replaces the standard defaults.
$_CONFIG['user'] = array_merge($_CONFIG['user'], $_SETT);



// Loop the settings. If we have a center in there, and the file does not exist, we surely need to bail out.
foreach ($_CONFIG['user'] as $sKey => $sVal) {
    if (preg_match('/^center_(.+)_id$/', $sKey, $aRegs)) {
        $sCenter = $aRegs[1];
        if (!in_array($sCenter, $aCentersFound)) {
            lovd_printIfVerbose(VERBOSITY_LOW,
                'Error: Settings mention center ' . $sCenter . ' but have not located its source file.' . "\n" .
                'Please make sure the source files are named properly, and their names start with the name of the center.' . "\n\n");
            die(EXIT_ERROR_ARGS_INSUFFICIENT);
        }
    }
}



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
    lovd_verifySettings('consensus_file', 'File to write resulting consensus data to', 'string', '');
}





lovd_printIfVerbose(VERBOSITY_MEDIUM, "\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Parsing VKGL files...' . "\n");





// Loop through files and load all data, grouping the entries in memory.
$aData = array();
// Sort on center names, but keep file names.
// I don't want to sort on the keys, because files can be in different directories.
asort($aFiles);
// Sort center list then too, because we'll loop it later and we need to keep the order the same.
sort($aCentersFound);
$nFile = 0;
foreach ($aFiles as $sFile => $sCenter) {
    lovd_printIfVerbose(VERBOSITY_MEDIUM,
        ' ' . date('H:i:s', time() - $tStart) . ' [' .
        str_pad(number_format(($nFile/$nCentersFound)*90, 1), 5, ' ', STR_PAD_LEFT) .
        '%] Parsing VKGL file for center ' . $sCenter . '...' . "\n");
    $nFile ++;

    $aHeaders = array();
    $nHeaders = 0;
    $nLine = 0;
    $sFileType = '';

    $fInput = fopen($sFile, 'r');
    if ($fInput === false) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Can not open file:' . $sFile . ".\n\n");
        die(EXIT_ERROR_INPUT_CANT_OPEN);
    }

    // The Radboud data doesn't have a header :(
    if ($sCenter == 'radboud_mumc') {
        // Invent the header for the Radboud/MUMC+ data.
        $sLine = implode("\t", array(
            'description',    // seq[GRCh37] 11pterp15.5(0_926088)x3
            'HGVS',           // NC_000011.9:g.(0)_(926088_959436)dup
            'build',          // GRCh37
            'chromosome',     // chr11
            'inside start',   // 1
            'inside stop',    // 926088
            'outside start',  // 1
            'outside stop',   // 959436
            'type',           // DUPLICATION
            'p/q arm',        // pter
            'location',       // p15.5
            'genes',          // ANO9,AP006621.5,AP2A2,ATHL1,B4GALNT4,BET1L,C11ORF35,CD151,CDHR5,CEND1,CHID1,DEAF1,DRD4,EFCAB4A,EPS8L2,HRAS,IFITM1,IFITM2,IFITM3,IFITM5,IRF7,LRRC56,NLRP6,ODF3,PDDC1,PHRF1,PIDD,PKP3,PNPLA2,POLR2L,PSMD13,PTDSS2,RASSF7,RIC8A,RNH1,RPLP2,SCGB1C1,SCT,SIGIRR,SIRT3,SLC25A22,TALDO1,TMEM80,TSPAN4
            'classification', // class 4
            'protocol',       // Exome
        ));

    } else {
        // Loop through data until we get a header.
        while ($sLine = fgets($fInput)) {
            $nLine++;
            $sLine = strtolower(rtrim($sLine));
            if (!$sLine) {
                continue;
            }
            break;
        }
    }

    // First line should be headers.
    $aHeaders = explode("\t", $sLine);
    $nHeaders = count($aHeaders);
    $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));

    // Check header's signature.
    $aSignature = $aHeaders;
    sort($aSignature);
    $sHeaderSignature = implode(';', $aSignature);

    if (!isset($_CONFIG['header_signatures'][$sHeaderSignature])) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File does not conform to any known format: ' . $sFile . ".\n({$sHeaderSignature})\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_INCORRECT);
    } else {
        $sFileType = $_CONFIG['header_signatures'][$sHeaderSignature];
    }

    if (!$aHeaders) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File does not conform to format; can not find headers.' . "\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_NOT_FOUND);
    }



    // Also add center to headers for output.
    $_CONFIG['columns_mandatory'][] = $sCenter;
    $_CONFIG['columns_mandatory'][] = $sCenter . $_CONFIG['columns_center_suffix'];

    lovd_printIfVerbose(VERBOSITY_MEDIUM,
        ' ' . date('H:i:s', time() - $tStart) . ' [' .
        str_pad(number_format(($nFile/$nCentersFound)*90, 1), 5, ' ', STR_PAD_LEFT) .
        '%] VKGL file successfully parsed, currently at ' . count($aData) . ' variants.' . "\n");
}

// Now, we'll figure out how to handle multiple entries per variant.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' .
    str_pad(number_format(90, 1), 5, ' ', STR_PAD_LEFT) .
    '%] Checking VKGL data for intra-center duplicates...' . "\n");

foreach ($aData as $sVariantKey => $aVariant) {
    foreach ($aCentersFound as $sCenter) {
        // Does this center even know this variant?
        if (!isset($aVariant[$sCenter])) {
            // Nope.
            continue;

        } elseif (count($aVariant[$sCenter]) == 1) {
            // No duplicates, all cool.
            foreach ([$sCenter, $sCenter . $_CONFIG['columns_center_suffix']] as $sKey) {
                $aData[$sVariantKey][$sKey] = current($aVariant[$sKey]);
            }
            continue;
        }

        // OK, there are multiple entries. Not necessarily a problem yet.
        // Simplify storing the _link field.
        $aValues = array_unique($aVariant[$sCenter . $_CONFIG['columns_center_suffix']]);
        sort($aValues);
        $aData[$sVariantKey][$sCenter . $_CONFIG['columns_center_suffix']] = implode(', ', $aValues);

        // Now, check the classifications.
        $aClassifications = array_unique($aVariant[$sCenter]);
        if (count($aClassifications) == 1) {
            // Simple, just one classification.
            $aData[$sVariantKey][$sCenter] = current($aClassifications);
            // Do report.
//            lovd_printIfVerbose(VERBOSITY_HIGH,
//                '                   Warning: Center ' . $sCenter . ' has two entries for the same variant. ID: ' . $sVariantKey . "\n");

        } else {
            // Now we're actually in trouble. Internal conflict.
            // First, report the issue.
//            lovd_printIfVerbose(VERBOSITY_MEDIUM,
//                '                   Warning: Center ' . $sCenter . ' has an internal conflict; ' . implode(', ', $aClassifications) . '. ID: ' . $sVariantKey . "\n");

            $bB   = in_array('benign', $aClassifications);
            $bLB  = in_array('likely benign', $aClassifications);
            $bVUS = in_array('VUS', $aClassifications);
            $bLP  = in_array('likely pathogenic', $aClassifications);
            $bP   = in_array('pathogenic', $aClassifications);
            // Rules: report opposites; */VUS to VUS; LB/B to LB; LP/P to LP.
            if (($bB || $bLB) && ($bLP || $bP)) {
                // Internal conflict within center; a conflict that we can't resolve.
                unset($aData[$sVariantKey][$sCenter]);
                unset($aData[$sVariantKey][$sCenter . $_CONFIG['columns_center_suffix']]);

            } elseif ($bVUS) {
                // VUS and something else, not a conflict. OK, VUS then.
                $aData[$sVariantKey][$sCenter] = 'VUS';

            } elseif ($bB && $bLB) {
                // B + LB. LB, then.
                $aData[$sVariantKey][$sCenter] = 'likely benign';

            } elseif ($bLP && $bP) { // Deliberately no else. If we messed up somewhere, we want to know.
                // LP + P. LP, then.
                $aData[$sVariantKey][$sCenter] = 'likely pathogenic';
            }
        }
    }
}

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' .
    str_pad(number_format(100, 1), 5, ' ', STR_PAD_LEFT) .
    '%] VKGL data successfully cleaned, currently at ' . count($aData) . ' variants.' . "\n\n" .
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Writing consensus data file...' . "\n");





// Write header first.
$fOutput = fopen($_CONFIG['user']['consensus_file'], 'w');
if ($fOutput === false) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Can not open file for writing:' . $_CONFIG['user']['consensus_file'] . ".\n\n");
    die(EXIT_ERROR_CACHE_CANT_CREATE);
}
fputs($fOutput, implode("\t", $_CONFIG['columns_mandatory']) . "\r\n");



// Loop data and write to file.
foreach ($aData as $sVariantKey => $aVariant) {
    // Decompose the key again to Chr, Pos, Ref, Alt, Gene, Transcript, cDNA.
    $aVariantKey = explode('|', $sVariantKey);

    // For CNVs, the only thing we'll store is the HGVS description and each center's classification.
    $aLine = array($sVariantKey);

    // Loop centers.
    foreach ($aCentersFound as $sCenter) {
        if (isset($aVariant[$sCenter])) {
            $aLine[] = $aVariant[$sCenter];
        } else {
            $aLine[] = '';
        }
        if (isset($aVariant[$sCenter . $_CONFIG['columns_center_suffix']])) {
            $aLine[] = $aVariant[$sCenter . $_CONFIG['columns_center_suffix']];
        } else {
            $aLine[] = '';
        }
    }

    // Write data.
    fputs($fOutput, implode("\t", $aLine) . "\r\n");
}

// Final message.
$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] ' . $nVariants . ' variants stored.' . "\n\n");

if ($nWarningsOccurred) {
    die(EXIT_WARNINGS_OCCURRED);
}
?>
