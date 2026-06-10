#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-27 (based on format_raw_VKGL_files.php)
 * Modified    : 2026-06-09
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once(__DIR__ . '/libs/HGVS-syntax-checker/HGVS.php');
use LOVD\HGVS\HGVS;
use LOVD\HGVS\HGVS_CNV;

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
                throw new \Exception("Invalid argument format — the formatter requires the list of files as an associative array: [file => center]");
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
            'created;pathogenicity;posedits' => 'umcg_json',

            // CSV formats:
            // Illumina Emedgene:
            'alt;chromosome;created reference build;diseases (omim id);end;error;overlap %;pathogenicity;position;ref;transcript;vartype' => 'emedgene_csv',

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
            'change;chromosome;classification;classification date;classification system;classification tags;conditions;end;gene summary;genes;genome build;inheritance;interpretation text;references;source;start;submitter;submitting org' => 'franklin_cnv_tsv',
            'cdna;chromosome;gdna_normalized;geneid;protein;refseq_build;variant_effect' => 'lumc_snv_tsv',
            'alt;category;chromosome;classification;cnomen;effect;end;exon;gene;pnomen;position;ref;region;strand;transcript' => 'nki_snv_tsv',
            'chromosome;clinical phenotypes;cnv classification;constitutional/acquired variant;end postition;flanking normals - pter;flanking normals - qter;genome build;genomic nomenclature;inheritance;internal identifier;international system for human cytogenomic nomenclature;lab upload date;list of overlapping genes (hgnc);number of copies / upd;parental origin;phenotype (hpo);start and end chromosome band;start position;timestamp last processed;type of cnv;type of platform;type of test' => 'nxclinical_cnv_tsv',
            'build;chromosome;classification;description;genes;hgvs;inside start;inside stop;location;outside start;outside stop;p/q arm;protocol;type' => 'radboud_cnv_tsv',
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

        // Illumina Emedgene gives us .csv files. Just convert to TSV.
        if (strrchr($sFile, '.') == '.csv') {
            foreach ($aLines as $i => $sLine) {
                $aLines[$i] = str_replace(',', "\t", $sLine);
            }
        }

        // The Radboud data doesn't have a header :(
        if ($sCenter == 'radboud_mumc') {
            // Invent the header.
            if (substr_count(rtrim($aLines[0]), "\t") == 11 && str_starts_with($aLines[0], 'chr')) {
                // Radboud SNV data, with 12 columns.
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

            } elseif (substr_count($aLines[0], "\t") == 13 && in_array(substr($aLines[0], 0, 3), ['arr', 'seq'])) {
                // Radboud CNV data, with 14 columns.
                array_unshift(
                    $aLines,
                    implode("\t",
                        [
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
                            'genes',          // ANO9,AP006621.5,AP2A2,ATHL1,B4GALNT4,BET1L,(...etc...)
                            'classification', // class 4
                            'protocol',       // Exome
                        ]
                    )
                );
            }
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
                    if ($aVariant['chromosome'] == 'MT') {
                        // Chromosome "MT" is not accepted by the HGVS library; make it "M", instead.
                        $aVariant['chromosome'] = 'M';
                    }
                    $this->data[$sCenter][$sDataType][] = [
                        'genomic_native' => "hg19:{$aVariant['chromosome']}:{$aVariant['start']}:{$aVariant['ref']}:{$aVariant['alt']}",
                        'classification' => $this->convertClassification($aVariant['classification']),
                        'gene' => $aVariant['gene'],
                        'transcript' => $aVariant['transcript'],
                        'cDNA' => $aVariant['c_nomen'],
                        'protein' => str_replace('NULL', '', $aVariant['p_nomen']),
                        'annotation' => [
                            'source' => 'Alissa',
                            'last_updated_by' => $aVariant['last_updated_by'],
                            'last_updated_on' => strstr($aVariant['last_updated_on'], '.', true),
                        ],
                    ];
                    break;

                case 'emedgene_csv':
                    // This format actually contains both CNVs and SNVs. We'll handle them both here.
                    if ($aVariant['vartype'] == 'SNV') {
                        // Simple SNV. Result will be, e.g., "GRCh37:1:211832061:CA:C".
                        $sVariant = "{$aVariant['created reference build']}:{$aVariant['chromosome']}:{$aVariant['position']}:{$aVariant['ref']}:{$aVariant['alt']}";
                    } else {
                        // CNV. Result will be, e.g., "GRCh37:1:1_1068640DEL".
                        $sVariant = "{$aVariant['created reference build']}:{$aVariant['chromosome']}:{$aVariant['position']}_{$aVariant['end']}{$aVariant['vartype']}";
                        $aVariant['vartype'] = 'CNV';
                    }

                    $this->data[$sCenter][$aVariant['vartype']][] = [
                        'source' => 'Emedgene',
                        'genomic_native' => $sVariant,
                        'classification' => $this->convertClassification($aVariant['pathogenicity']),
                        'transcript' => $aVariant['transcript'],
                    ];
                    break;

                case 'franklin_cnv_tsv':
                    // Filtering is needed. Somehow, there is quite some useless data here.
                    // Insertions can't be used. The positions are often the same, and if they're not, the descriptions
                    //  clearly indicate it's not a dup of the region indicated by the positions. This makes no sense.
                    if ($aVariant['change'] == 'INSERTION') {
                        continue 2;
                    }

                    // Then there are "breakends". Start and end are always the same. No clue what this is.
                    if ($aVariant['change'] == 'BREAKEND') {
                        continue 2;
                    }

                    // The classification can be set to FALSE or NONE. Erm...
                    if ($aVariant['classification'] == 'FALSE' || $aVariant['classification'] == 'NONE') {
                        continue 2;
                    }

                    // Store what we have.
                    $this->data[$sCenter][$sDataType][] = [
                        'genomic_native' => "{$aVariant['chromosome']}({$aVariant['genome build']}):g.{$aVariant['start']}_{$aVariant['end']}" . strtolower(substr($aVariant['change'], 0, 3)),
                        'classification' => $this->convertClassification($aVariant['classification']),
                        // 'gene' => $aVariant['genes'], // We can have many genes here, but this isn't useful to store for CNVs.
                        'annotation' => [
                            'source' => 'Franklin',
                            'submitter' => $aVariant['submitter'],
                            'classification_date' => $aVariant['classification date'],
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

                case 'nxclinical_cnv_tsv':
                    // NxClinical format, which is a manual export and won't be updated often.

                    // Collect the parts needed to build the HGVS description.
                    switch ($aVariant['number of copies / upd']) {
                        // The copy number represents the number of times the sequence is present, where
                        //  0 means a homozygous deletion,
                        //  1 means a heterozygous deletion,
                        //  3 means a heterozygous duplication, and
                        //  4 means a homozygous duplication (all for autosomes only).
                        // This number is sometimes missing for variants affecting the X/Y chromosomes.
                        case '0':
                            $sVariantType = 'del';
                            $sZygosity = 'homozygous';
                            break;

                        case '1':
                            $sVariantType = 'del';
                            $sZygosity = 'heterozygous';
                            break;

                        case '3':
                            $sVariantType = 'dup';
                            $sZygosity = 'heterozygous';
                            break;

                        case '4':
                            $sVariantType = 'dup';
                            $sZygosity = 'homozygous';
                            break;

                        case '':
                            // We didn't get a copy number, so we'll have to guess it.
                            // This only happens with chrX and chrY.
                            // Take the variant type from a different field.
                            $sVariantType = ($aVariant['type of cnv'] == 'gain'? 'dup' : 'del');
                            $sZygosity = 'unknown'; // The default.
                            break;
                    }

                    // Store what we have.
                    $this->data[$sCenter][$sDataType][] = [
                        'genomic_native' => "{$aVariant['chromosome']}({$aVariant['genome build']}):g.{$aVariant['start position']}_{$aVariant['end postition']}" . $sVariantType,
                        'classification' => $this->convertClassification($aVariant['cnv classification']),
                        'annotation' => [
                            'source' => 'NxClinical',
                            'platform' => $aVariant['type of platform'],
                            'zygosity' => $sZygosity,
                        ],
                    ];
                    break;

                case 'radboud_cnv_tsv':
                    // This file contains lots of redundant information. Up to three completely separate variant
                    //  descriptions can be created from the information in this file. To ensure data integrity, we'll
                    //  store these variant descriptions in an array and compare them. If they conflict between each
                    //  other, we'll discard the line.
                    // The first variant description is based on the column 'description'.
                    // The second one is located in the 'HGVS' column, but not always fully valid.
                    // The third one has to be built by using multiple columns: 'inside start', 'inside stop',
                    //  'outside start', 'outside stop', and the variant type.
                    $aHGVSDescriptions = array();
                    $sZygosity = 'Unknown';
                    $sPlatform = '';

                    // Start with creating the first HGVS expression from the CNV notation.
                    $HGVS = HGVS_CNV::check($aVariant['description']);
                    // If the corrected value gets full confidence, we'll take it.
                    if (current($HGVS->getCorrectedValues()) == 1) {
                        $sZygosity = $HGVS->getData()['zygosity'];
                        $sPlatform = $HGVS->getData()['platform'];
                        $aHGVSDescriptions[] = $HGVS->getCorrectedValue();

                        // However, if we have a WT variant and the data indicates it's an inversion, fix this.
                        if (substr(current($aHGVSDescriptions), -1) == '=' && $aVariant['type'] == 'INVERSION') {
                            $aHGVSDescriptions = [substr(current($aHGVSDescriptions),0, -1) . 'inv'];
                        }
                    }

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

                case 'umcg_json':
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
                    // This format contains SNVs as well as CNVs. For the latter, ref has one base, and alt is null.
                    // We then can't use the VCF fields, but have to rely on the HGVS fields.
                    // In all other cases, we ignore the HGVS field because it's often wrong.
                    $sDataType = 'SNV';
                    foreach (['genomic_native' => $aNative, 'genomic_liftover' => $aLiftOver] as $sField => $aData) {
                        if ($aData) {
                            if ($aData['alt'] === null) {
                                // This is a CNV. Use the HGVS field.
                                $aLine[$sField] = $aData['hgvs'];
                                $sDataType = 'CNV';
                            } else {
                                $aLine[$sField] = "{$aData['human_reference']}:{$aData['chromosome']}:{$aData['start']}:{$aData['ref']}:{$aData['alt']}";
                            }
                        }
                    }
                    $this->data[$sCenter][$sDataType][] = array_merge(
                        $aLine,
                        [
                            'classification' => $this->convertClassification($aVariant['pathogenicity']),
                            // We earlier received a JSON format with gene, transcript, cDNA, etc. Later, we got a format that lacks these fields.
                            // Keeping this in here, in case we'll later get the better format again.
                            // 'gene' => $aVariant['gene_symbol']['hgnc_symbol'],
                            // 'transcript' => $aVariant['gene_symbol']['primary_transcripts'][0],
                            // 'cDNA' => $aVariant['cNomen'],
                            // 'protein' => $aVariant['pNomen'],
                            'annotation' => ['created' => strstr($aVariant['created'], '.', true), 'reported-as' => $aVariant['posedits'][0]['emg']],
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





/*
// Default settings. Everything in 'user' will be verified with the user, and stored in settings.json.
$_CONFIG = array(
    'columns_center_suffix' => '_link', // This is how we recognize a center, because it also has a *_link column.
);



// Isolate the center names from the file names.
// Verify these and store.
$aCentersFound = array();

// Loop through files and load all data, grouping the entries in memory.
$aData = array();

// Now, we'll figure out how to handle multiple entries per variant.
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
            print('                   Warning: Center ' . $sCenter . ' has two entries for the same variant. ID: ' . $sVariantKey . "\n");

        } else {
            // Now we're actually in trouble. Internal conflict.
            // First, report the issue.
            print('                   Warning: Center ' . $sCenter . ' has an internal conflict; ' . implode(', ', $aClassifications) . '. ID: ' . $sVariantKey . "\n");

            $bB   = in_array('benign', $aClassifications);
            $bLB  = in_array('likely benign', $aClassifications);
            $bVUS = in_array('VUS', $aClassifications);
            $bLP  = in_array('likely pathogenic', $aClassifications);
            $bP   = in_array('pathogenic', $aClassifications);
            // Rules: report opposites; anything+VUS to VUS; LB+B to LB; LP+P to LP.
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
*/
?>
