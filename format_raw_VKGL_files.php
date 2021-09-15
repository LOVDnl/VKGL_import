#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * LEIDEN OPEN VARIATION DATABASE (LOVD)
 *
 * Created     : 2019-11-13
 * Modified    : 2021-09-13
 * Version     : 0.1.5
 * For LOVD    : 3.0-26
 *
 * Purpose     : Parses the VKGL center's raw data files (of different formats)
 *               and creates one consensus data file which can then be processed
 *               by the process_VKGL_data.php script.
 *
 * Changelog   : 0.1.5  2021-09-13
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
 * Copyright   : 2004-2021 Leiden University Medical Center; http://www.LUMC.nl/
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
    'version' => '0.1.5',
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
        'transcript',
        'c_dna',
        'protein',
    ),
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
    'header_signatures' => array(
        'alt;c;c_nomen;chromosome;classification;effect;exon;gene;id;last_updated_by;last_updated_on;location;p_nomen;' .
            'ref;start;stop;timestamp;transcript;variant_type' => 'alissa',
        'alt;c_nomen;chromosome;classification;effect;exon;gene;id;last_updated_by;last_updated_on;location;p_nomen;' .
            'ref;start;stop;timestamp;transcript;variant_type' => 'alissa',
        'alt;alt_orig;c_nomen;chrom;chromosome;classification;effect;exon;gene;hgvs_normalized_vkgl;id;' .
            'last_updated_by;last_updated_on;location;p_nomen;pos;ref;ref_orig;significance;start;stop;timestamp;' .
            'transcript;type;variant_type' => 'alissa2',
        'cdna;chromosome;gdna_normalized;geneid;protein;refseq_build;variant_effect' => 'lumc',
        'alt;chromosome;classification;empty;empty;empty;gene;location;ref;start;stop;transcript_or_dna' => 'radboud',
    ),
    'mutalyzer_URL' => 'https://test.mutalyzer.nl/', // Test may be faster than www.mutalyzer.nl.
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





function lovd_HGVStoVCF ($sVariant) {
    // Function to convert HGVS in sort of VCF. Sort of, because we'll leave REF or ALTs empty and put Ns everywhere.
    // We do not pretend to check the variant. We do not support inversions for this reason.

    $aVCF = array(
        'chr' => '',
        'pos' => '',
        'ref' => '',
        'alt' => '',
    );

    if (preg_match('/^NC_([0-9]+)\.([0-9]+):/', $sVariant, $aRegs)) {
        $aVCF['chr'] = str_replace(
            array('23', '24', '12920'),
            array('X', 'Y', 'M'),
            (string) (int) $aRegs[1]);
        $sVariant = substr($sVariant, strlen($aRegs[0]));
    }

    if (preg_match('/^g.([0-9]+)([A-Z])>([A-Z])$/', $sVariant, $aRegs)) {
        // Substitutions.
        list(,$aVCF['pos'], $aVCF['ref'], $aVCF['alt']) = $aRegs;

    } elseif (preg_match('/^g.([0-9]+)(_([0-9]+))?del$/', $sVariant, $aRegs)) {
        // Deletions.
        $aVCF['pos'] = $aRegs[1];
        $aVCF['alt'] = '.';
        if (empty($aRegs[2])) {
            $aVCF['ref'] = 'N';
        } else {
            $aVCF['ref'] = str_repeat('N', ($aRegs[3] - $aRegs[1] + 1));
        }

    } elseif (preg_match('/^g.([0-9]+)(_([0-9]+))?dup$/', $sVariant, $aRegs)) {
        // Duplications.
        $aVCF['pos'] = $aRegs[1];
        if (empty($aRegs[2])) {
            $aVCF['ref'] = 'N';
            $aVCF['alt'] = 'NN';
        } else {
            $aVCF['ref'] = str_repeat('N', ($aRegs[3] - $aRegs[1]) + 1);
            $aVCF['alt'] = str_repeat('N', strlen($aVCF['ref']) * 2);
        }

    } elseif (preg_match('/^g.([0-9]+)_[0-9]+ins([A-Z]+)$/', $sVariant, $aRegs)) {
        // Insertions.
        // This is totally breaking the VCF standard, but whatever, it's what the VKGL uses.
        $aVCF['pos'] = $aRegs[1];
        $aVCF['ref'] = 'N';
        $aVCF['alt'] = 'N' . $aRegs[2];

    } else {
        return false;
    }

    return $aVCF;
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



// Isolate the center names from the file names.
// Verify these and store.
$aCentersFound = array();
$nCentersFound = 0;

foreach ($aFiles as $nKey => $sFile) {
    list($sName, $sExt) = explode('.', basename($sFile), 2);
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
        str_pad(number_format(($nFile/$nCentersFound)*100, 1), 5, ' ', STR_PAD_LEFT) .
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
        // Invent the header.
        $sLine = implode("\t", array(
            'chromosome',
            'start',
            'stop',
            'ref',
            'alt',
            'gene',
            'transcript_or_dna',
            'empty',
            'empty',
            'location',
            'empty',
            'classification',
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

    // Check headers signature.
    $aSignature = $aHeaders;
    sort($aSignature);
    $sHeaderSignature = implode(';', $aSignature);

    if (!isset($_CONFIG['header_signatures'][$sHeaderSignature])) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File does not conform to any known format: ' . $sFile . ".\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_INCORRECT);
    } else {
        $sFileType = $_CONFIG['header_signatures'][$sHeaderSignature];
    }

    if (!$aHeaders) {
        lovd_printIfVerbose(VERBOSITY_LOW,
            'Error: File does not conform to format; can not find headers.' . "\n\n");
        die(EXIT_ERROR_HEADER_FIELDS_NOT_FOUND);
    }



    while ($sLine = fgets($fInput)) {
        $nLine++;
        $sLine = rtrim($sLine);
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
                'Error: Data line ' . $nLine . ' has ' . count($aDataLine) .
                ' columns instead of the expected ' . $nHeaders . ".\n\n");
            die(EXIT_ERROR_DATA_FIELD_COUNT_INCORRECT);
        }

        $aDataLine = array_combine($aHeaders, $aDataLine);
        // How we group variants, very loosely to make things simple for us.
        $sVariantKey = ''; // Chr,Start,Ref,Alt,Gene,Transcript,cDNA.
        $aValues = array(); // protein => ..., center => classification, center_link => ....
        switch ($sFileType) {
            case 'alissa':
                $sVariantKey = implode('|', array(
                    $aDataLine['chromosome'],
                    $aDataLine['start'],
                    $aDataLine['ref'],
                    $aDataLine['alt'],
                    $aDataLine['gene'],
                    $aDataLine['transcript'],
                    $aDataLine['c_nomen'],
                ));
                $aValues = array(
                    'protein' => str_replace('NULL', '', $aDataLine['p_nomen']),
                    $sCenter => str_replace(array('_', 'vous'), array(' ', 'VUS'), strtolower($aDataLine['classification'])),
                    $sCenter . $_CONFIG['columns_center_suffix'] => $aDataLine['last_updated_by'],
                );
                break;

            case 'alissa2':
                $sVariantKey = implode('|', array(
                    $aDataLine['chromosome'],
                    $aDataLine['start'],
                    $aDataLine['ref_orig'],
                    $aDataLine['alt_orig'],
                    $aDataLine['gene'],
                    $aDataLine['transcript'],
                    $aDataLine['c_nomen'],
                ));
                $aValues = array(
                    'protein' => str_replace('NULL', '', $aDataLine['p_nomen']),
                    $sCenter => str_replace(array('_', 'vous'), array(' ', 'VUS'), strtolower($aDataLine['classification'])),
                    $sCenter . $_CONFIG['columns_center_suffix'] => $aDataLine['last_updated_by'],
                );
                break;

            case 'lumc':
                // Because all data is otherwise in (sort of) VCF fields and I don't want to pull the normalization code
                //  into this script, I'm just creating the (sort of) VCF fields that VKGL is using. This would allow
                //  for the least changes to the processing script, whilst allowing for some merging of LUMC variants
                //  with the other centers.
                $aVariant = lovd_HGVStoVCF($aDataLine['gdna_normalized']);
                if ($aVariant === false) {
                    lovd_printIfVerbose(VERBOSITY_LOW,
                        'Error: Unhandled variant, could not generate VCF fields: ' .
                        $aDataLine['gdna_normalized'] . ".\n\n");
                    die(EXIT_ERROR_DATA_CONTENT_ERROR);
                }

                // We allow for multiple transcript mappings to be sent. Let's just grab the first one.
                list($aDataLine['transcript'], $aDataLine['cdna']) =
                    explode(':', substr($aDataLine['cdna'], 0, strpos($aDataLine['cdna'] . ',', ',')), 2);
                $aDataLine['protein'] = substr($aDataLine['protein'], 0, strpos($aDataLine['protein'] . ',', ','));

                $sVariantKey = implode('|', array(
                    $aVariant['chr'],
                    $aVariant['pos'],
                    $aVariant['ref'],
                    $aVariant['alt'],
                    $aDataLine['geneid'],
                    $aDataLine['transcript'],
                    $aDataLine['cdna'],
                ));
                $aValues = array(
                    'protein' => str_replace('NULL', '', $aDataLine['protein']),
                    $sCenter => str_replace(
                        array(
                            '-?',
                            '-',
                            '+?',
                            '+',
                            '?',
                        ), array(
                            'likely benign',
                            'benign',
                            'likely pathogenic',
                            'pathogenic',
                            'VUS',
                        ), strtolower($aDataLine['variant_effect'])),
                    $sCenter . $_CONFIG['columns_center_suffix'] => $aDataLine['refseq_build'],
                );
                break;

            case 'radboud':
                // The transcript field is a bit of a mix.
                $sTranscript = '';
                $sDNA = '';
                $sProtein = '';
                $aTranscripts = array_map('trim', preg_split('/[,; ]/', $aDataLine['transcript_or_dna']));
                foreach ($aTranscripts as $sDescription) {
                    if (preg_match('/(NM_[0-9]{6,9}\.[0-9]+|ENST[0-9]+\.[0-9])(?:\(' .
                            preg_quote($aDataLine['gene'], '/') .
                            '\))?:(c\.[0-9_+-]+[A-Z>deldupins]+)/', $sDescription, $aRegs)) {
                        // cDNA given; store separate fields.
                        $sTranscript = $aRegs[1];
                        $sDNA = $aRegs[2];
                        continue;
                    } elseif (preg_match('/p\.(\([A-Z][a-z]{2}[0-9]+[A-Z][a-z]{2}\)|[A-Z][a-z]{2}[0-9]+[A-Z][a-z]{2})/',
                            $sDescription, $aRegs)) {
                        // Protein given; store in protein field.
                        $sProtein = $aRegs[0];
                        continue;
                    } elseif (preg_match('/^(Chr[0-9XYM]+\(GRCh[0-9]{2}\):)?g\.[0-9]+([A-Z]>[A-Z]|ins[ACGT]+)$/',
                            $sDescription, $aRegs)) {
                        // Genomic DNA given; store in DNA field.
                        $sDNA = $aRegs[0];
                        continue;
                    }
                }

                $sVariantKey = implode('|', array(
                    preg_replace('/^chr/', '', $aDataLine['chromosome']),
                    $aDataLine['start'],
                    $aDataLine['ref'],
                    $aDataLine['alt'],
                    $aDataLine['gene'],
                    $sTranscript,
                    $sDNA,
                ));
                $aValues = array(
                    'protein' => $sProtein,
                    $sCenter => str_replace(
                        array(
                            'class 1',
                            'class 2',
                            'class 3',
                            'class 4',
                            'class 5',
                        ), array(
                            'benign',
                            'likely benign',
                            'VUS',
                            'likely pathogenic',
                            'pathogenic',
                        ), strtolower($aDataLine['classification'])),
                    $sCenter . $_CONFIG['columns_center_suffix'] => $aDataLine['classification'],
                );
                break;
        }

        if (!$sVariantKey) {
            // Unhandled file type?
            lovd_printIfVerbose(VERBOSITY_LOW,
                'Error: Unhandled file type, could not generate variant key.' . "\n\n");
            die(EXIT_ERROR_DATA_CONTENT_ERROR);
        }

        if (!isset($aData[$sVariantKey])) {
            $aData[$sVariantKey] = array('protein' => array());
        }
        foreach ($aValues as $sKey => $sValue) {
            if ($sKey == 'protein') {
                $aData[$sVariantKey]['protein'][] = $sValue;
            } else {
                // These values cannot already exist.
                if (isset($aData[$sVariantKey][$sKey])) {
                    // Center already seen for this variant?
                    // 2021-02-04; VUMC now delivers *two* files of the same
                    //  format, and with some overlap (right now 16 variants).
                    // So we can't just die here anymore. There are some
                    //  conflicts, which we'll need to drop, but the rest can
                    //  just continue.
                    if ($aData[$sVariantKey][$sCenter] == $aValues[$sCenter]) {
                        // Same classification. It's OK, just overwrite.
                        // Do report.
                        lovd_printIfVerbose(VERBOSITY_HIGH,
                            '                   Warning: Center ' . $sCenter . ' has two entries for same variant key: ' . $sVariantKey . ".\n");
                    } else {
                        // Now we're actually in trouble. Internal conflict.
                        // We won't die, but we need to ignore BOTH entries.
                        lovd_printIfVerbose(VERBOSITY_MEDIUM,
                            '                   Warning: Internal conflict in center ' . $sCenter . ': ' . $aData[$sVariantKey][$sCenter] . ', ' . $aValues[$sCenter] . ".\n" .
                            '                   ID: ' . $sVariantKey . "\n");
                        unset($aData[$sVariantKey]); // Delete variant.
                        continue 2; // Next line in the file.
                    }
                }
                $aData[$sVariantKey][$sKey] = $sValue;
            }
        }
    }

    // Also add center to headers for output.
    $_CONFIG['columns_mandatory'][] = $sCenter;
    $_CONFIG['columns_mandatory'][] = $sCenter . $_CONFIG['columns_center_suffix'];

    lovd_printIfVerbose(VERBOSITY_MEDIUM,
        ' ' . date('H:i:s', time() - $tStart) . ' [' .
        str_pad(number_format(($nFile/$nCentersFound)*100, 1), 5, ' ', STR_PAD_LEFT) .
        '%] VKGL file successfully parsed, currently at ' . count($aData) . ' variants.' . "\n");
}

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    "\n" .
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

    $aLine = array(
        // https://github.com/molgenis/data-transform-vkgl/blob/master/src/main/java/org/molgenis/mappers/VkglTableMapper.java#L10
        substr(hash('sha256',
            $aVariantKey[0] . '_' . $aVariantKey[1] . '_' . $aVariantKey[2] . '_' . $aVariantKey[3] . '_' .
            $aVariantKey[4]), 0, 10), // ID; sha256(chr + "_" + pos + "_" + ref + "_" + alt + "_" + gene).substr(0,10);
        $aVariantKey[0], // Chr.
        $aVariantKey[1], // Pos.
        $aVariantKey[2], // Ref.
        $aVariantKey[3], // Alt.
        $aVariantKey[4], // Gene.
        $aVariantKey[5], // Transcript.
        $aVariantKey[6], // cDNA.
        implode(', ', array_unique($aVariant['protein'])),
    );

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
