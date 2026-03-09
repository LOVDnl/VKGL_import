#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-27 (based on format_raw_VKGL_files.php)
 * Modified    : 2026-03-09
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once __DIR__ . '/libs/HGVS-syntax-checker/HGVS.php';
use LOVD\HGVS\HGVS;

class Formatter
{
    // Class abstracting the formatting of various formats used in the VKGL project.
    // This code is based on format_raw_VKGL_files.php, first written on 2019-11-13 and last modified 2026-01-15.
    private array $data = [];
    private array $data_rejected = [];
    private array $data_output_header = [
        'center',
        'type',
        'genomic_native',
        'genomic_liftover',
        'classification',
        'gene',
        'transcript',
        'cDNA',
        'protein',
        'annotation'
    ];
    private array $data_rejected_output_header = [
        'center',
        'type',
        'error',
        'data'
    ];

    public static function format (array $aFiles): Formatter
    {
        // Loop the given files and parse them one by one.
        // $aFiles should be the format as used in the status log.
        $o = new Formatter();

        foreach ($aFiles as $sFile => $sCenter) {
            if (is_int($sFile)) {
                // Looks like we got a "normal" array instead of an associative array.
                throw new \Exception("Invalid argument format — the formatter requires the list of files as an associative array: [file => center].");
            }
            $o->parse($sFile, $sCenter);
        }

        return $o;
    }





    public function convertClassification ($sClassification): string
    {
        return match (strtolower(str_replace('_', ' ', $sClassification))) {
            'benign', '-', 'class 1' => 'B',
            'likely benign', '-?', 'class 2' => 'LB',
            'vus', 'vous', '?', 'class 3' => 'VUS',
            'likely pathogenic', '+?', 'class 4' => 'LP',
            'pathogenic', '+', 'class 5' => 'P',
            default => $sClassification,
        };
    }





    public function hasErrors (): bool
    {
        return (bool) count($this->data_rejected);
    }





    public function identifyHeader (array $aHeader): string
    {
        // Identifies the file format and returns the name of the format.
        sort($aHeader);
        $sSignature = implode(';', $aHeader);
        return match ($sSignature) {
            // JSON formats:
            '_id;alternative;build;cNomen;category;chromosome;classification;date;description;display_id;effect;end;exon;gene_symbol;institute;location;maintainer;managed_variant_id;pNomen;position;reference;sub_category;type;variant_id;variant_info' => 'nki_snv_json',
            'created;pathogenicity;posedits' => 'umcg_snv_json',

            // TSV formats:
            // Alissa:
            'alt;c;c_nomen;chromosome;classification;effect;exon;gene;id;last_updated_by;last_updated_on;location;p_nomen;ref;start;stop;timestamp;transcript;variant_type' => 'alissa_snv_tsv',
            'alt;c_nomen;chromosome;classification;effect;exon;gene;id;last_updated_by;last_updated_on;location;p_nomen;ref;start;stop;timestamp;transcript;variant_type' => 'alissa_snv_tsv',
            'alt;alt_orig;c_nomen;chrom;chromosome;classification;effect;exon;gene;hgvs_normalized_vkgl;id;last_updated_by;last_updated_on;location;p_nomen;pos;ref;ref_orig;significance;start;stop;timestamp;transcript;type;variant_type' => 'alissa2_snv_tsv',
            // Apparently, Groningen used to edit the files and added the id and timestamp fields. Alissa files from the SFTP server don't have those fields.
            'alt;c;c_nomen;chromosome;classification;effect;exon;gene;last_updated_by;last_updated_on;location;p_nomen;ref;start;stop;transcript;variant_type' => 'alissa_snv_tsv',
            // 2024-02 + 2024-04; Due to a personnel change at Alissa without a proper handover, manual exports are being generated with yet another signature.
            'alt;c_nomen;chromosome;classification;effect;exon;gene;last_updated_by;last_updated_on;location;p_nomen;ref;start;stop;transcript;variant_type' => 'alissa_snv_tsv',
            // Other:
            'cdna;chromosome;gdna_normalized;geneid;protein;refseq_build;variant_effect' => 'lumc_snv_tsv',
            'alt;category;chromosome;classification;cnomen;effect;end;exon;gene;pnomen;position;ref;region;strand;transcript' => 'nki_snv_tsv',
            'alt;chromosome;classification;empty;empty;empty;gene;location;ref;start;stop;transcript_or_dna' => 'radboud_snv_tsv',
            default => false,
        };
    }





    public function parse (string $sFile, string $sCenter): bool
    {
        // Parse every file, and add the contents to $this->data.
        if (!file_exists($sFile) || !is_readable($sFile)) {
            throw new \Exception("File $sFile does not exist or is not readable");
        }

        if (strrchr($sFile, '.') == '.json') {
            // JSON is handled differently.
            return $this->parseJSON($sFile, $sCenter);
        }

        $aLines = file($sFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File $sFile could not be opened");
        }

        // The Radboud data doesn't have a header :(
        if ($sCenter == 'radboud_mumc') {
            // Invent the header.
            array_unshift(
                $aLines,
                implode("\t",
                    [
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
                    ]
                )
            );
        }

        // First line should be headers.
        $aHeaders = explode("\t", strtolower(array_shift($aLines)));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));

        // OK, now collect the signature and figure out what format this is.
        $sFileType = $this->identifyHeader($aHeaders);
        if (!$sFileType) {
            throw new \Exception("Can not identify data format for $sFile");
        }
        $sDataType = (str_contains($sFileType, '_cnv_')? 'CNV' : 'SNV');

        foreach ($aLines as $nLine => $sLine) {
            $nLine++;
            $aDataLine = explode("\t", rtrim($sLine));
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
                $this->data_rejected[$sCenter][$sDataType][] = [
                    'error' => "Error: Data line $nLine has " . count($aDataLine) . " columns instead of the expected $nHeaders.",
                    'data' => json_encode($sLine, JSON_UNESCAPED_UNICODE),
                ];
                continue;
            }

            $aVariant = array_combine($aHeaders, $aDataLine);
            switch ($sFileType) {
                case 'alissa2_snv_tsv':
                    $aVariant['ref'] = $aVariant['ref_orig'];
                    $aVariant['alt'] = $aVariant['alt_orig'];
                case 'alissa_snv_tsv':
                    // Alissa data was always hg19.
                    $this->data[$sCenter][$sDataType][] = [
                        'genomic_native' => "hg19:{$aVariant['chromosome']}:{$aVariant['start']}:{$aVariant['ref']}:{$aVariant['alt']}",
                        'classification' => $this->convertClassification($aVariant['classification']),
                        'gene' => $aVariant['gene'],
                        'transcript' => $aVariant['transcript'],
                        'cDNA' => $aVariant['c_nomen'],
                        'protein' => str_replace('NULL', '', $aVariant['p_nomen']),
                        'annotation' => [
                            'last_updated_by' => $aVariant['last_updated_by'],
                            'last_updated_on' => strstr($aVariant['last_updated_on'], '.', true),
                        ],
                    ];
                    break;

                case 'lumc_snv_tsv':
                    // 2024-08-28 Since the July run, LUMC has WT variants (e.g., g.123456=). I guess they come from
                    //  Moon. Nonetheless, we should get rid of them. Just skip them silently.
                    if (str_ends_with($aVariant['gdna_normalized'], '=')) {
                        // Yup, a WT variant. Silently skip it.
                        continue 2;
                    }

                    // We allow for multiple transcript mappings to be sent. Let's just grab the first one.
                    list($aVariant['transcript'], $aVariant['cdna']) =
                            explode(':', substr($aVariant['cdna'], 0, strpos($aVariant['cdna'] . ',', ',')), 2);
                    $aVariant['protein'] = substr($aVariant['protein'], 0, strpos($aVariant['protein'] . ',', ','));

                    $this->data[$sCenter][$sDataType][] = [
                        'genomic_native' => $aVariant['gdna_normalized'],
                        'classification' => $this->convertClassification($aVariant['variant_effect']),
                        'gene' => $aVariant['geneid'],
                        'transcript' => $aVariant['transcript'],
                        'cDNA' => $aVariant['cdna'],
                        'protein' => str_replace('NULL', '', $aVariant['protein']),
                    ];
                    break;

                case 'nki_snv_tsv':
                    // NKI tsv data is always hg19.
                    $this->data[$sCenter][$sDataType][] = [
                            'genomic_native' => "hg19:{$aVariant['chromosome']}:{$aVariant['position']}:{$aVariant['ref']}:{$aVariant['alt']}",
                            'classification' => $this->convertClassification($aVariant['classification']),
                            'gene' => $aVariant['gene'],
                            'transcript' => $aVariant['transcript'],
                            'cDNA' => $aVariant['cnomen'],
                            'protein' => $aVariant['pnomen'],
                    ];
                    break;

                case 'radboud_snv_tsv':
                    // The transcript field is a disaster; it can contain any kind of information.
                    $sTranscript = '';
                    $sDNA = '';
                    $sProtein = '';
                    $aTranscripts = array_map('trim', preg_split('/[,; ]/', $aVariant['transcript_or_dna']));
                    foreach ($aTranscripts as $sDescription) {
                        if (!$sDescription || ctype_digit($sDescription)) {
                            continue;
                        } elseif (str_starts_with($sDescription, 'M_')) {
                            // This happens quite a bit.
                            $sDescription = 'N' . $sDescription;
                        }
                        // We used to have all kinds of code trying to fix shit. Let's not. We have LOVD/HGVS for this.
                        // Yes, it's much slower. But it's also much better.
                        $HGVS = HGVS::check($sDescription);
                        switch ($HGVS->getIdentifiedAs()) {
                            case 'reference_sequence':
                                $sTranscript = $HGVS->getCorrectedValue();
                                break 2;
                            case 'full_variant_DNA':
                                $sTranscript = $HGVS->ReferenceSequence->getCorrectedValue(); // This can be empty, when we received ":c.100del".
                                $sDNA = $HGVS->Variant->getCorrectedValue();
                                break 2;
                            case 'variant_DNA':
                                $sDNA = $HGVS->getCorrectedValue();
                                break 2;
                            case 'gene_symbol':
                            case 'genome_build':
                                // Nah, we have that already. Try a next value.
                                continue 2;
                        }

                        // If we got here, LOVD/HGVS didn't recognize it.
                        // Currently, however, protein descriptions aren't handled by LOVD/HGVS yet.
                        if (preg_match('/p\.(\([A-Z][a-z]{2}[0-9]+[A-Z][a-z]{2}\)|[A-Z][a-z]{2}[0-9]+[A-Z][a-z]{2})/',
                                $sDescription, $aRegs)) {
                            // Protein given; store in protein field.
                            $sProtein = $aRegs[0];
                        }
                    }

                    // The Radboud tsv data is always hg19.
                    $this->data[$sCenter][$sDataType][] = [
                            'genomic_native' => "hg19:{$aVariant['chromosome']}:{$aVariant['start']}:{$aVariant['ref']}:{$aVariant['alt']}",
                            'classification' => $this->convertClassification($aVariant['classification']),
                            'gene' => $aVariant['gene'],
                            'transcript' => $sTranscript,
                            'cDNA' => $sDNA,
                            'protein' => $sProtein,
                    ];
                    break;

                default:
                    // We forgot to implement something here.
                    throw new \Exception("Unhandled TSV format ($sFileType) for $sFile");
            }
        }

        return true;
    }





    public function parseJSON (string $sFile, string $sCenter): bool
    {
        // Parse the JSON file and add the contents to $this->data.
        $aJSON = json_decode(file_get_contents($sFile), true);
        if (!$aJSON) {
            throw new \Exception("File $sFile could not be parsed as JSON");
        }

        // The UMCG JSON file has one key: variants.
        if (is_array($aJSON) && array_keys($aJSON) == ['variants']) {
            $aJSON = $aJSON['variants'];
        }

        // The keys of this array should all be numeric; it should be an array of objects.
        if (array_filter(array_keys($aJSON), 'is_string') || !is_array(current($aJSON))
                || !array_filter(array_keys(current($aJSON)), 'is_string')) {
            // String keys in this array, first child is not an array, or first child does not have string keys.
            throw new \Exception("JSON data is not an array of objects in $sFile");
        }

        // OK, now collect the signature and figure out what format this is.
        $sFileType = $this->identifyHeader(array_keys(current($aJSON)));
        if (!$sFileType) {
            throw new \Exception("Can not identify JSON format for $sFile");
        }

        foreach ($aJSON as $aVariant) {
            switch ($sFileType) {
                case 'nki_snv_json':
                    // Skip artefacts.
                    if (strtolower($aVariant['classification']) == 'artefact') {
                        continue 2;
                    }

                    // Simply add the data to the set.
                    $this->data[$sCenter]['SNV'][] = [
                        'genomic_native' => "GRCh{$aVariant['build']}:{$aVariant['chromosome']}:{$aVariant['position']}:{$aVariant['reference']}:{$aVariant['alternative']}",
                        'classification' => $this->convertClassification($aVariant['classification']),
                        'gene' => $aVariant['gene_symbol']['hgnc_symbol'],
                        // I found two examples where the primary transcript had a different cDNA description than the
                        //  MANE transcript, and that the cDNA description matched the primary transcript.
                        //  So we'll use that.
                        'transcript' => $aVariant['gene_symbol']['primary_transcripts'][0],
                        'cDNA' => $aVariant['cNomen'],
                        'protein' => $aVariant['pNomen'],
                        'annotation' => [
                            'date' => $aVariant['date']['$date'],
                            'maintainers' => $aVariant['maintainer'], // Usually contains one person, but occasionally, multiple.
                        ],
                    ];
                    break;

                case 'umcg_snv_json':
                    // Reject variants without a pathogenicity (just a handful).
                    if (empty($aVariant['pathogenicity'])) {
                        $this->data_rejected[$sCenter]['SNV'][] = [
                            'error' => 'Error: Variant does not contain a pathogenicity.',
                            'data' => json_encode($aVariant, JSON_UNESCAPED_UNICODE),
                        ];
                        continue 2;
                    }

                    // We usually have two variant sets. If we have two, we'll assume that hg19 is the native one,
                    //  because hg19 has slightly more variants. Start by sorting the array on the genome builds.
                    usort($aVariant['posedits'], function ($a, $b) {
                        return strcmp($a['human_reference'], $b['human_reference']);
                    });
                    // The first position (thus, the oldest build) will be considered the native one.
                    list($aNative, $aLiftOver) = array_pad($aVariant['posedits'], 2, []);
                    // This should never happen, but I'm not going to assume it won't ever happen in the future.
                    if (!$aNative) {
                        // We didn't find a genomic variant.
                        $this->data_rejected[$sCenter]['SNV'][] = [
                            'error' => 'Error: Variant does not contain any variant data.',
                            'data' => json_encode($aVariant, JSON_UNESCAPED_UNICODE),
                        ];
                        continue 2;
                    }

                    // This data is a bit more complex, so we should build it carefully.
                    $aLine = [];
                    // Some variants are very large; ref is then set to one base, and alt is set to null.
                    // We then can't use the VCF fields, but have to rely on the HGVS fields.
                    // In all other cases, we ignore the HGVS field because it's often wrong.
                    foreach (['genomic_native' => $aNative, 'genomic_liftover' => $aLiftOver] as $sField => $aData) {
                        if ($aData) {
                            if ($aData['alt'] === null) {
                                $aLine[$sField] = $aData['hgvs'];
                            } else {
                                $aLine[$sField] = "{$aData['human_reference']}:{$aData['chromosome']}:{$aData['start']}:{$aData['ref']}:{$aData['alt']}";
                            }
                        }
                    }
                    $this->data[$sCenter]['SNV'][] = array_merge(
                        $aLine,
                        [
                            'classification' => $this->convertClassification($aVariant['pathogenicity']),
                            // We earlier received a JSON format with gene, transcript, cDNA, etc. Later, we got a format that lacks these fields.
                            // Keeping this in here, in case we'll later get the better format again.
                            // 'gene' => $aVariant['gene_symbol']['hgnc_symbol'],
                            // 'transcript' => $aVariant['gene_symbol']['primary_transcripts'][0],
                            // 'cDNA' => $aVariant['cNomen'],
                            // 'protein' => $aVariant['pNomen'],
                            'annotation' => ['created' => strstr($aVariant['created'], '.', true)],
                        ]
                    );
                    break;

                default:
                    // We forgot to implement something here.
                    throw new \Exception("Unhandled JSON format ($sFileType) for $sFile");
            }
        }

        return true;
    }





    public function save (string $sFile): bool
    {
        // Save the data to disk.
        $aData = [implode("\t", $this->data_output_header)];
        foreach ($this->data as $sCenter => $aCenter) {
            foreach ($aCenter as $sType => $aVariants) {
                foreach ($aVariants as $aVariant) {
                    $aVariant['center'] = $sCenter;
                    $aVariant['type'] = $sType;
                    $aLine = [];
                    foreach ($this->data_output_header as $sField) {
                        $Value = ($aVariant[$sField] ?? '');
                        if ($sField == 'annotation' && $Value) {
                            $Value = json_encode($Value, JSON_UNESCAPED_UNICODE);
                        }
                        $aLine[] = $Value;
                    }
                    $aData[] = implode("\t", $aLine);
                }
            }
        }
        $aData[] = '';

        // Save the data.
        return (bool) file_put_contents(
            $sFile,
            implode("\r\n", $aData)
        );
    }





    public function saveErrors (string $sFile): bool
    {
        // Save errors to disk.
        $aData = [implode("\t", $this->data_rejected_output_header)];
        foreach ($this->data_rejected as $sCenter => $aCenter) {
            foreach ($aCenter as $sType => $aVariants) {
                foreach ($aVariants as $aVariant) {
                    $aVariant['center'] = $sCenter;
                    $aVariant['type'] = $sType;
                    $aLine = [];
                    foreach ($this->data_rejected_output_header as $sField) {
                        $aLine[] = ($aVariant[$sField] ?? '');
                    }
                    $aData[] = implode("\t", $aLine);
                }
            }
        }
        $aData[] = '';

        // Save the data.
        return (bool) file_put_contents(
            $sFile,
            implode("\r\n", $aData)
        );
    }
}

// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$_CONFIG = array(
    'name' => 'VKGL raw data formatter',
    'version' => '0.2.4',
    'flags' => array(
        'y' => false,
    ),
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
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
    } else {
        // Eh?
        var_dump("bad arg: $sArg");
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

// Loop through files and load all data, grouping the entries in memory.
$aData = array();
// Sort on center names, but keep file names.
// I don't want to sort on the keys, because files can be in different directories.
asort($aFiles);

// Now, we'll figure out how to handle multiple entries per variant.
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [  0.0%] Checking VKGL data for intra-center duplicates...' . "\n");

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

        // OK, there are multiple entries. Not neccessarily a problem yet.
        // Simplify storing the _link field.
        $aData[$sVariantKey][$sCenter . $_CONFIG['columns_center_suffix']] = implode(
            ', ',
            array_unique($aVariant[$sCenter . $_CONFIG['columns_center_suffix']])
        );

        // Now, check the classifications.
        $aClassifications = array_unique($aVariant[$sCenter]);
        if (count($aClassifications) == 1) {
            // Simple, just one classification.
            $aData[$sVariantKey][$sCenter] = current($aClassifications);
            // Do report.
            lovd_printIfVerbose(VERBOSITY_HIGH,
                '                   Warning: Center ' . $sCenter . ' has two entries for the same variant. ID: ' . $sVariantKey . "\n");

        } else {
            // Now we're actually in trouble. Internal conflict.
            // First, report the issue.
            lovd_printIfVerbose(VERBOSITY_MEDIUM,
                '                   Warning: Center ' . $sCenter . ' has an internal conflict; ' . implode(', ', $aClassifications) . '. ID: ' . $sVariantKey . "\n");

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

    // Drop the variant if now empty.
    if (count($aData[$sVariantKey]) == 1) {
        // We have only the protein field left, this variant is now empty.
        unset($aData[$sVariantKey]);
    }
}

lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [' .
    str_pad(number_format(100, 1), 5, ' ', STR_PAD_LEFT) .
    '%] VKGL data successfully cleaned, currently at ' . count($aData) . ' variants.' . "\n\n");

// Final message.
$nVariants = count($aData);
lovd_printIfVerbose(VERBOSITY_MEDIUM,
    ' ' . date('H:i:s', time() - $tStart) . ' [100.0%] ' . $nVariants . ' variants stored.' . "\n\n");

if ($nWarningsOccurred) {
    die(EXIT_WARNINGS_OCCURRED);
}
?>
