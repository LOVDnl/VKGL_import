#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2020-04-02
 * Modified    : 2020-06-30
 * Version     : 0.2
 * For LOVD    : 3.0-24
 *
 * Purpose     : Checks the NC cache and extends the mapping cache using the new
 *               Variant Validator object.
 *
 * Changelog   : 0.1    2020-04-03
 *               Initial release.
 *
 * Copyright   : 2004-2020 Leiden University Medical Center; http://www.LUMC.nl/
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

// Default settings. We won't verify any setting, that's up to the process script.
$_CONFIG = array(
    'name' => 'VKGL cache verification using Variant Validator',
    'version' => '0.2',
    'settings_file' => 'settings.json',
    'VV_URL' => 'https://www35.lamp.le.ac.uk/', // Test instance with the latest LOVD endpoint. // www.variantvalidator.org.
    'user' => array(
        // We don't have defaults, we load everything from the settings file.
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





// Parse command line options.
$aArgs = $_SERVER['argv'];
$nArgs = $_SERVER['argc'];

$sScriptName = array_shift($aArgs);
$nArgs --;
$nWarningsOccurred = 0;

if ($nArgs > 1) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        $_CONFIG['name'] . ' v' . $_CONFIG['version'] . '.' . "\n" .
        'Usage: ' . $sScriptName . ' [mapping_cache_VV.txt]' . "\n\n");
    die(EXIT_ERROR_ARGS_NOT_UNDERSTOOD);
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

    } else {
        // Argument not recognized.
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: Argument ' . $sArg . ' not understood.' . "\n\n");
        die(EXIT_ERROR_ARGS_NOT_UNDERSTOOD);
    }
}
$bCron = (empty($_SERVER['REMOTE_ADDR']) && empty($_SERVER['TERM']));
define('VERBOSITY', ($bCron? 5 : 7));
$tStart = time() + date('Z', 0); // Correct for timezone, otherwise the start value is not 0.

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



// Get settings file, if it exists.
$_SETT = array();
if (!file_exists($_CONFIG['settings_file'])
    || !is_file($_CONFIG['settings_file']) || !is_readable($_CONFIG['settings_file'])
    || !($_SETT = json_decode(file_get_contents($_CONFIG['settings_file']), true))) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Unreadable settings file.' . "\n\n");
    die(EXIT_ERROR_SETTINGS_UNREADABLE);
}

// The settings file always replaces the standard defaults.
$_CONFIG['user'] = array_merge($_CONFIG['user'], $_SETT);





// Open connection, we need LOVD for the VV object.
lovd_printIfVerbose(VERBOSITY_HIGH,
    '  Connecting to LOVD...');

// Find LOVD installation, run it's inc-init.php to get DB connection, initiate $_SETT, etc.
define('ROOT_PATH', $_CONFIG['user']['lovd_path'] . '/');
define('FORMAT_ALLOW_TEXTPLAIN', true);
$_GET['format'] = 'text/plain';
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

// Check if VV works.
$_VV = false;
if (file_exists(ROOT_PATH . 'class/variant_validator.php')) {
    require ROOT_PATH . 'class/variant_validator.php';
    $_VV = new LOVD_VV($_CONFIG['VV_URL']);
}
if (!$_VV || !$_VV->test()) {
    lovd_printIfVerbose(VERBOSITY_LOW,
        'Error: Could not initialize Variant Validator object.' . "\n\n");
    die(EXIT_ERROR_CACHE_CANT_CREATE);
}





// Load the caches, create if they don't exist. They can only not exist, when the defaults are used.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [      ] Loading Mutalyzer cache files...' . "\n");
$_CACHE = array();
foreach (array('mutalyzer_cache_NC', 'mutalyzer_cache_mapping') as $sKeyName) {
    $_CACHE[$sKeyName] = array();
    if (!file_exists($_CONFIG['user'][$sKeyName])
        || !is_file($_CONFIG['user'][$sKeyName]) || !is_readable($_CONFIG['user'][$sKeyName])) {
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
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Verifying variants and mappings...' . "\n");

$nVariants = count($_CACHE['mutalyzer_cache_NC']) - 1; // -1 because the header gets counted, too.
// We might be running for some time.
set_time_limit(0);





// Correct all genomic variants, using the cache. Skip substitutions.
// And don't bother using the database, we'll assume the cache knows it all.
$nVariantsDone = 0;
$nVariantsAddedToCache = 0;
$nPercentageComplete = 0; // Integer of percentage with one decimal (!), so you can see the progress.
$tProgressReported = microtime(true); // Don't report progress again within a certain amount of time.
$nAPICalls = 0;
$nAPICallsReported = 0;
$nSecondsWaiting = 0; // Will be reset now and then.
foreach ($_CACHE['mutalyzer_cache_NC'] as $sVariant => $sVariantCorrected) {
    // Skip the header.
    if ($sVariant{0} == '#') {
        continue;
    }
    // Also skip EREF errors. We know VV handles them well, but because these
    //  are not in the mapping cache, we keep resending them to VV.
    if (in_array(substr($sVariantCorrected, 0, 8), array('{"EREF":', '{"ERANGE', 'null'))) {
        $nVariantsDone ++;
        continue; // Next variant.
    }

    // The variant may be in the NC cache, but not yet in the mapping cache.
    // This happens when the NC cache is used by another application,
    //  which doesn't build the mapping cache as well.
    // Check if we need this call, if the variant is missing in one of
    //  the two caches or if our Variant Validator method is missing.
    // Note that this means that we'll skip variants that may be unverified,
    //  if they map to a variant that has been verified already.
    // Since we're just verifying Mutalyzer corrections, I think that's OK.
    $bUpdateCache = (!isset($_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected])
        || !in_array('VV', $_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected]['methods']));

    // Update cache if needed.
    if ($bUpdateCache) {
        $tStartWait = microtime(true);
        // Call VV, don't limit to certain transcripts, we don't know which ones we'll want.
        $aResult = $_VV->verifyGenomicAndPredictProtein($sVariant);
        $nSecondsWaiting += (microtime(true) - $tStartWait);
        $nAPICalls ++;
        if (!$aResult) {
            // Strange, VV failed completely for this variant.
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                    floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Error: Variant Validator failed for variant ' . $sVariant . ".\n" .
                '                   {' . $sVariant . '|' . $sVariantCorrected . '|FALSE||Error: Variant Validator failed.}' . "\n");
            $nWarningsOccurred ++;
            $nVariantsDone ++;
            continue; // Next variant.
        }

        // If VV complains that the variant description has been corrected, we ignore this.
        unset($aResult['warnings']['WCORRECTED']);

        // If VV throws an error, at least make sure Mutalyzer has the same error.
        if ($aResult['errors']) {
            // Check if errors match.
            // Note that we don't get here anymore, for errors that we skip.
            // Keeping this in case we disable that skip.
            if (isset($aResult['errors']['EREF']) && substr($sVariantCorrected, 0, 8) == '{"EREF":') {
                // Errors match.
                unset($aResult['errors']['EREF']);
            } elseif (isset($aResult['errors']['ERANGE']) && substr($sVariantCorrected, 0, 10) == '{"ERANGE":') {
                // Errors match.
                unset($aResult['errors']['ERANGE']);
            } elseif (isset($aResult['errors']['ESYNTAX']) && $sVariantCorrected == 'null') {
                // Errors match.
                unset($aResult['errors']['ESYNTAX']);
            }
            if ($aResult['errors']) {
                // Assume errors mismatch, catch these errors and fix when we run into them.
                // For now, VV fails on ERANGE errors, so we won't get here (yet).
                // This happens when VV throws an error that Mutalyzer didn't.
                lovd_printIfVerbose(VERBOSITY_MEDIUM,
                    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                        floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                        5, ' ', STR_PAD_LEFT) . '%] Error: Variant Validator error disagrees for variant ' . $sVariant . ".\n" .
                    '                   {' . $sVariant . '|' . $sVariantCorrected . '|' . $aResult['data']['DNA'] . '|' . implode(';', $aResult['warnings']) . '|Error: Variant Validator error disagrees: ' . implode(';', $aResult['errors']) . '}' . "\n");
                $nWarningsOccurred ++;
            }
            $nVariantsDone ++;
            continue; // Next variant.

        } elseif ($aResult['data']['DNA'] != $sVariantCorrected) {
            // Variant threw no error, but Variant Validator disagrees with Mutalyzer.
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format(
                    floor($nVariantsDone * 1000 / $nVariants) / 10, 1),
                    5, ' ', STR_PAD_LEFT) . '%] Error: Variant predictors disagree for variant ' . $sVariant . ".\n" .
                '                   {' . $sVariant . '|' . $sVariantCorrected . '|' . $aResult['data']['DNA'] . '|' . implode(';', $aResult['warnings']) . '|Error: Variant predictors disagree.}' . "\n");
            $nWarningsOccurred ++;
            $nVariantsDone ++;
            continue; // Next variant.
        }

        // When we get there, both variant predictors agreed.
        // Now loop through the mappings we got from VV, and see if we can
        //  extend/update the current mapping cache.
        // We'll store all mappings, since we don't know which ones we want.
        foreach ($aResult['data']['transcript_mappings'] as $sRefSeq => $aMapping) {
            // Position converter descriptions are *not* normalized.
            // Freely overwrite mappings if we haven't added our method to it yet.
            $_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected][$sRefSeq] =
                array(
                    'c' => $aMapping['DNA'],
                    'p' => $aMapping['protein'],
                );

            // But wait, did we just fill in a protein field for a non-coding transcript?
            if (substr($sRefSeq, 1, 1) == 'R') {
                unset($_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected][$sRefSeq]['p']);
            }
        }
        // Add our method to the list as well, so we won't repeat this.
        $_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected]['methods'][] = 'VV';

        // Add to mapping cache.
        file_put_contents($_CONFIG['user']['mutalyzer_cache_mapping'],
         $sVariantCorrected . "\t" . json_encode($_CACHE['mutalyzer_cache_mapping'][$sVariantCorrected]) . "\n", FILE_APPEND);
        $nVariantsAddedToCache ++;
    }

    // Print update, for every percentage changed.
    $nVariantsDone ++;
    if ((microtime(true) - $tProgressReported) > 5 && $nVariantsDone != $nVariants
        && floor($nVariantsDone * 1000 / $nVariants) != $nPercentageComplete) {
        $nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
        $nAPICallsToReport = ($nAPICalls - $nAPICallsReported);
        $nRateToReport = ($nSecondsWaiting / $nAPICallsToReport);
        lovd_printIfVerbose(VERBOSITY_MEDIUM,
            ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
                5, ' ', STR_PAD_LEFT) . '%] ' .
            str_pad($nVariantsDone, strlen($nVariants), ' ', STR_PAD_LEFT) . ' variants verified... (' .
            $nAPICallsToReport . ' API calls' . (!$nAPICallsToReport? '' : ' @ ' . number_format($nRateToReport, 2) . 's') . ")\n");
        // Reset the stats.
        $nAPICallsReported = $nAPICalls;
        $nSecondsWaiting = 0;
        $tProgressReported = microtime(true); // Don't report again for a certain amount of time.
    }
}

// Last message.
$nPercentageComplete = floor($nVariantsDone * 1000 / $nVariants);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' . str_pad(number_format($nPercentageComplete / 10, 1),
        5, ' ', STR_PAD_LEFT) . '%] ' .
    $nVariantsDone . ' variants verified.' . "\n" .
    '                   Mappings added to cache: ' . $nVariantsAddedToCache . ".\n\n");





// Final counts.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [Totals] Variants seen   : ' . $nVariants . ".\n" .
    (!$nWarningsOccurred? '' :
        '                   Warning(s) count: ' . $nWarningsOccurred . ".\n")
      . "\n");

if ($nWarningsOccurred) {
    die(EXIT_WARNINGS_OCCURRED);
}
?>
