<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-05-14
 * Modified    : 2026-08-12
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once(__DIR__ . '/libs/HGVS-syntax-checker/HGVS.php');
require_once(__DIR__ . '/libs/HGVS-syntax-checker/caches.php');
require_once(__DIR__ . '/LOVD.php');
use LOVD\HGVS\Caches;
use LOVD\HGVS\HGVS;
use LOVD\HGVS\HGVS_Chromosome;
use LOVD\Log;
use LOVD\LOVD;
use LOVD\Settings;

class Processor
{
    // Class abstracting the processing of the aggregated data in a local LOVD instance.
    private array $center_ids = []; // [center => internal LOVD ID]; the generic VKGL account is added manually.
    private array $data = [];
    private array $data_rejected = [];
    private array $data_rejected_output_header = [
        'center',
        'type',
        'error',
        'genomic_native_normalized',
        'genomic_native_reported',
    ];
    private array $effect_mapping_classification = array(
        'B' => 'benign',
        'LB' => 'likely benign',
        'VUS' => 'VUS',
        'LP' => 'likely pathogenic',
        'P' => 'pathogenic',
    );
    private array $effect_mapping_LOVD = array(
        'B' => 1,
        'LB' => 3,
        'VUS' => 5,
        'LP' => 7,
        'P' => 9,
    );
    private array $statistics = array(
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 0,
    );
    private string $lovd_genome_build = '';
    private $Settings;
    private $Log;

    public static function process (string $sFile, Settings $Settings, Log $Log = null): Processor
    {
        // Process the given data into the LOVD configured in the settings.
        $o = new Processor();
        $o->Settings = $Settings;
        if ($Log) {
            $o->Log = $Log;
        }

        // Connect to LOVD and check if that worked.
        $b = LOVD::connect(($Settings->get('lovd_path') ?? ''), $Log);
        if (!$b) {
            throw new \Exception("Unable to connect to LOVD");
        }

        $o->prepare();
        $o->parse($sFile);
        $o->processData();
        return $o;
    }





    public function getStatistics (): array
    {
        return $this->statistics;
    }





    public function hasErrors (): bool
    {
        return (bool) count($this->data_rejected);
    }





    public function parse (string $sFile): bool
    {
        // Parse the data file and add the contents to $this->data.
        if (!file_exists($sFile) || !is_readable($sFile)) {
            throw new \Exception("File $sFile does not exist or is not readable");
        }

        $aLines = file($sFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File $sFile could not be opened");
        }

        // First line should be headers. No need to use strtolower() here, this is our own format.
        $aHeaders = explode("\t", array_shift($aLines));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));

        foreach ($aLines as $sLine) {
            $aDataLine = explode("\t", rtrim($sLine));
            // Trim quotes off of the data.
            $aDataLine = array_map(function ($sData) {
                return trim($sData, '"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                // We accidentally trimmed off empty fields.
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }
            $aVariant = array_combine($aHeaders, $aDataLine);

            // Collect all necessary data about the variant's builds.
            $aVariant['native_info'] = HGVS_Chromosome::getInfoByNC(strstr($aVariant['genomic_native_normalized'], ':', true));
            $aVariant['DNA'][$aVariant['native_info']['build']] = $aVariant['genomic_native_normalized'];
            // Note: this may store false, when we have no liftover value.
            $aVariant['liftover_info'] = HGVS_Chromosome::getInfoByNC(strstr($aVariant['genomic_liftover_normalized'], ':', true));
            if ($aVariant['liftover_info']) {
                $aVariant['DNA'][$aVariant['liftover_info']['build']] = $aVariant['genomic_liftover_normalized'];

                // Also check if both descriptions are using the same chromosome. They should, but you never know.
                if ($aVariant['native_info']['chr'] != $aVariant['liftover_info']['chr']) {
                    $this->data_rejected[] = array_merge(
                        $aVariant,
                        [
                            'error' => "Variant's native and liftover descriptions are on different chromosomes: {$aVariant['native_info']['chr']} and {$aVariant['liftover_info']['chr']}.",
                        ]
                    );
                    continue;
                }
            }

            // Check if this variant's build(s) match the LOVD's build. If this variant does not have a description on
            //  LOVD's build, the variant will not be added to the dataset, but saved to the error file, instead.
            if (!isset($aVariant['DNA'][$this->lovd_genome_build])) {
                $this->data_rejected[] = array_merge(
                    $aVariant,
                    [
                        'error' => "LOVD has been configured to use {$this->lovd_genome_build}, this variant has descriptions only on " . implode(' and ', array_keys($aVariant['DNA'])) . '.',
                    ]
                );
                continue;
            }

            $nCenterID = (int) ($this->center_ids[$aVariant['center']] ?? 0);
            if (!$nCenterID) {
                throw new \Exception("Center {$aVariant['center']} is not configured in the settings, or no LOVD ID has been set");
            }

            // Store the data by NC+chr value, then by center ID + variant description.
            // That way, we can easily check if a variant is in the data file.
            $sChrKey = strstr($aVariant['DNA'][$this->lovd_genome_build], ':', true) . ':' . $aVariant['native_info']['chr'];
            $sVariantKey = str_pad($nCenterID, 5, '0', STR_PAD_LEFT) . ':' . $aVariant['DNA'][$this->lovd_genome_build];
            $this->data[$sChrKey][$sVariantKey] = $aVariant;
        }

        // Make sure we run everything in the correct order, from chr1 to 22, then X, Y, and M.
        ksort($this->data);

        $nNoCorrectBuildFound = count($this->data_rejected);
        if ($nNoCorrectBuildFound) {
            $this->Log->add("Found $nNoCorrectBuildFound variant" . ($nNoCorrectBuildFound == 1? '' : 's') . " without a description on {$this->lovd_genome_build}; cannot process this variant in this LOVD instance.");
        }
        return true;
    }





    public function prepare (): void
    {
        // Prepare by collecting relevant information and doing some checks.

        // We need this a lot, so query it here.
        $this->lovd_genome_build = LOVD::getGenomeBuild();

        // Check if we have all the settings for the centers filled in OK.
        foreach ($this->Settings->get('centers') as $sCenter => $aCenter) {
            if (isset($aCenter['id'])) {
                $this->center_ids[$sCenter] = (int) $aCenter['id'];
            }
        }

        $bAccountsOK = true;
        $this->center_ids['generic_vkgl_account'] = (int) ($this->Settings->get('vkgl_generic_id') ?? 0);
        if (!$this->center_ids['generic_vkgl_account']) {
            $bAccountsOK = false;
            $this->Log->add("The generic VKGL account ID is not configured; please add vkgl_generic_id to the settings.json file.");
        }

        // Make sure all IDs are unique. Flip the array and check the count.
        $aCenterIDsToNames = array_flip($this->center_ids);
        if (count($aCenterIDsToNames) != count($this->center_ids)) {
            // There is a problem. At least one of the IDs is non-unique.
            $bAccountsOK = false;
            foreach ($this->center_ids as $sCenter => $nCenterID) {
                if ($sCenter != $aCenterIDsToNames[$nCenterID]) {
                    $this->Log->add("LOVD user ID #{$nCenterID} is assigned to both {$aCenterIDsToNames[$nCenterID]} and {$sCenter}.");
                }
            }
        }

        // Now make sure that the accounts exist in LOVD.
        // Check the given user accounts by using the user IDs ($this->center_ids).
        // Check if the users with ID are found in LOVD.
        $aUsers = LOVD::getUsers($this->center_ids);
        $aUserIDsMissing = array_diff(array_values($this->center_ids), array_keys($aUsers));
        foreach ($aUserIDsMissing as $nUserID) {
            $bAccountsOK = false;
            $this->Log->add("LOVD user ID #{$nUserID} not found in LOVD but assigned to {$aCenterIDsToNames[$nUserID]}.");
        }

        if (!$bAccountsOK) {
            $this->Log->add("Please check your settings and try again.");
            throw new \Exception('Failed to get all LOVD user accounts — see the status log');
        }
    }





    public function processData (): bool
    {
        // Process the data into the LOVD instance.
        global $_CONF, $_DB, $_TABLES, $_SETT, $_T;

        // Store all of LOVD's transcripts, we need them; array(id_ncbi => id).
        $aTranscripts = LOVD::getAllTranscripts();

        $aVariantsCreated = array(); // Collects counters per chromosome.
        $aVariantsUpdated = array(); // Collects counters per chromosome.
        $aVariantsDeleted = array(); // Collects counters per chromosome.
        $aVariantsSkipped = array(); // Collects counters per chromosome.
        $sNow = date('Y-m-d H:i:s');

        // Process updates per chromosome but show progress over the total number of variants.
        // We won't process variants that we can't hold. We could shorten the variant, but:
        // 1) If that shortening is not done exactly as before, linking database entries to file contents fails, and
        // 2) Contracting the VOG/DNA description may actually create non-unique values for unique variants, and
        // 3) Anyway, labs won't be able to match the DNA variant.
        // We're only losing a handful of variants by skipping them if the VOG/DNA field is too long.
        $nMaxVOGDNALength = lovd_getColumnLength(TABLE_VARIANTS, 'VariantOnGenome/DNA');
        $nMaxVOGPublishedAsLength = lovd_getColumnLength(TABLE_VARIANTS, 'VariantOnGenome/Published_as');
        $nMaxVOTDNALength = lovd_getColumnLength(TABLE_VARIANTS_ON_TRANSCRIPTS, 'VariantOnTranscript/DNA');
        $nMaxVOTProteinLength = lovd_getColumnLength(TABLE_VARIANTS_ON_TRANSCRIPTS, 'VariantOnTranscript/Protein');

        // Check if we actually have some columns that we use, activated.
        // These are optional, so we don't want to die if we don't have them.
        $aActiveCols = $_DB->q('
            SELECT colid FROM ' . TABLE_ACTIVE_COLS . '
            WHERE colid IN (?, ?, ?, ?, ?)',
            array(
                'VariantOnGenome/Genetic_origin',
                'VariantOnGenome/Published_as',
                'VariantOnGenome/Remarks',
                'VariantOnGenome/Remarks_Non_Public',
                'VariantOnGenome/ClinicalClassification',
            ))->fetchAllColumn();
        $bGeneticOrigin = in_array('VariantOnGenome/Genetic_origin', $aActiveCols);
        $bPublishedAs = in_array('VariantOnGenome/Published_as', $aActiveCols);
        $bRemarks = in_array('VariantOnGenome/Remarks', $aActiveCols);
        $bRemarksNonPublic = in_array('VariantOnGenome/Remarks_Non_Public', $aActiveCols);
        $bClassification = in_array('VariantOnGenome/ClinicalClassification', $aActiveCols);

        foreach ($this->data as $sChrKey => $aVariants) {
            list($sRefSeq, $sChromosome) = explode(':', $sChrKey, 2);
            // Reset counters.
            $aVariantsCreated[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsUpdated[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsDeleted[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsSkipped[$sChromosome] = 0; // Counters per chromosome.

            // Load the data currently in the database.
            // Note, that if there are two entries of the same variant by the same center, we see only *one*.
            $_DB->q('SET group_concat_max_len = 10000');
            $aDataLOVD = $_DB->q('
                SELECT CONCAT(vog.created_by, ":", ?, ":", vog.`VariantOnGenome/DNA`) AS ID,
                    vog.id, vog.allele, vog.effectid, vog.chromosome, vog.position_g_start, vog.position_g_end, vog.type,
                    vog.created_by, vog.owned_by, vog.statusid, vog.`VariantOnGenome/DNA`,
                    vog.`VariantOnGenome/DBID`, ' .
                    (!$bGeneticOrigin ? '' : 'vog.`VariantOnGenome/Genetic_origin`, ') .
                    (!$bPublishedAs ? '' : 'vog.`VariantOnGenome/Published_as`, ') .
                    (!$bRemarks ? '' : 'vog.`VariantOnGenome/Remarks`, ') .
                    (!$bRemarksNonPublic ? '' : 'vog.`VariantOnGenome/Remarks_Non_Public`, ') .
                    (!$bClassification ? '' : 'IFNULL(NULLIF(vog.`VariantOnGenome/ClinicalClassification`, ""), "-") AS `VariantOnGenome/ClinicalClassification`,') . '
                    GROUP_CONCAT(vot.transcriptid, ";", vot.effectid, ";",
                        IFNULL(vot.position_c_start, "0"), ";",
                        IFNULL(vot.position_c_start_intron, "0"), ";",
                        IFNULL(vot.position_c_end, "0"), ";",
                        IFNULL(vot.position_c_end_intron, "0"), ";",
                        IFNULL(NULLIF(vot.`VariantOnTranscript/DNA`, ""), "-"), ";",
                        IFNULL(NULLIF(vot.`VariantOnTranscript/RNA`, ""), "-"), ";",
                        IFNULL(NULLIF(vot.`VariantOnTranscript/Protein`, ""), "-") SEPARATOR ";;") AS vots
                FROM ' . TABLE_VARIANTS . ' AS vog LEFT OUTER JOIN ' . TABLE_VARIANTS_ON_TRANSCRIPTS . ' AS vot USING (id)
                WHERE vog.chromosome = ? AND vog.created_by IN (?' . str_repeat(', ?', count($this->center_ids) - 1) . ')
                GROUP BY vog.id',
                    array_merge(
                        array($sRefSeq, $sChromosome),
                        array_values($this->center_ids)))->fetchAllGroupAssoc();



            // Check all LOVD data and mark data as removed when it's not present in the data file anymore.
            $sRemoveMessage = 'VKGL data sharing initiative Nederland; Variant no longer found in the VKGL dataset for this center.';
            foreach ($aDataLOVD as $sLOVDKey => $aLOVDVariant) {
                // Remove variant if needed. Don't touch the Remarks_Non_Public, we don't want to complicate things.
                // Also, don't run this if we don't have to. Check status and current remarks.
                if (!isset($this->data[$sChrKey][$sLOVDKey])) {
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
                        $aVariantsDeleted[$sChromosome] ++;
                    }
                    unset($aDataLOVD[$sLOVDKey]);
                }
            }



            // Now, loop the data in the data file and process each variant.
            foreach ($aVariants as $sLOVDKey => $aVariant) {
                list($nCenterID, $sVariant) = explode(':', $sLOVDKey, 2);
                $sDNA = substr(strstr($sVariant, ':'), 1);

                // LOVD+ has a much shorter DNA field; only 150 characters.
                // Trying to put in a variant that's bigger will crash this process.
                // However, we may also simply find variants longer than 255 characters.
                // We will simply skip whatever is too long. See comments above explaining the reasoning.
                if (strlen($sDNA) > $nMaxVOGDNALength) {
                    $aVariantsSkipped[$sChromosome] ++;
                    continue;
                }

                // Build variant entry.
                $aVariant['annotation'] = json_decode($aVariant['annotation'], true);
                if (empty($aVariant['annotation']['reported_as'])) {
                    // If we didn't have a "reported_as" value in the variant's annotation, check the database if we
                    //  already have a value there. If so, just use that. If we have nothing, default to our
                    //  genomic_native_reported field.
                    if (!empty($aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'])) {
                        $aVariant['published_as'] = $aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'];
                    } else {
                        $aVariant['published_as'] = $aVariant['genomic_native_reported'];
                    }
                } elseif (is_array($aVariant['annotation']['reported_as'])) {
                    $aVariant['published_as'] = implode(', ', $aVariant['annotation']['reported_as']);
                } else {
                    $aVariant['published_as'] = $aVariant['annotation']['reported_as'];
                }
                // Do limit the input, depending on the field size.
                if (strlen($aVariant['published_as']) > $nMaxVOGPublishedAsLength) {
                    $aVariant['published_as'] = $this->shortenProteinInsertions($aVariant['published_as']);
                    // Is more needed?
                    if (strlen($aVariant['published_as']) > $nMaxVOGPublishedAsLength) {
                        $aVariant['published_as'] = $this->shortenDNAInsertions($aVariant['published_as']);
                    }
                }

                // Add some needed fields; (type, position_start, position_end).
                $HGVS = HGVS::check($sVariant);
                $HGVSData = $HGVS->getData();
                if ($HGVSData['type'] == '>') {
                    // Backward compatible with LOVD3.
                    $HGVSData['type'] = 'subst';
                }
                $aVOGEntry = array(
                    'id' => null,
                    'allele' => '0', // Unknown.
                    // Don't let internal conflicts cause notices here.
                    'effectid' => (!isset($this->effect_mapping_LOVD[$aVariant['classification']]) ? 0 :
                        $this->effect_mapping_LOVD[$aVariant['classification']]) .
                        // Default to "Not curated" for concluded effect, unless a user filled something in already.
                        (!isset($aDataLOVD[$sLOVDKey]) ? '0' : substr($aDataLOVD[$sLOVDKey]['effectid'], -1)),
                    'chromosome' => $sChromosome,
                    'position_g_start' => $HGVSData['position_start'],
                    'position_g_end' => $HGVSData['position_end'],
                    'type' => $HGVSData['type'],
                    'created_by' => $nCenterID,
                    // Created_date will be added later, right now we don't have it to prevent unneeded differences.
                    'owned_by' => ($aVariant['status'] == 'single-lab' && $this->Settings->get('public_singlelab_owners') != 'y' ? // Should single-lab entry get the generic VKGL account as owner?
                        $this->center_ids['generic_vkgl_account'] : $nCenterID),
                    'statusid' => (str_ends_with($aVariant['status'], 'opposite')? STATUS_HIDDEN : STATUS_OK),
                    // Don't let internal conflicts cause notices here.
                    'VariantOnGenome/ClinicalClassification' => (!isset($this->effect_mapping_classification[$aVariant['classification']])? '-' :
                        $this->effect_mapping_classification[$aVariant['classification']]),
                    'VariantOnGenome/DNA' => $sDNA, // Can actually also update, if the LOVD data is not correct.
                    'VariantOnGenome/DBID' => '', // FIXME: Will be filled in later for records to be created!
                    'VariantOnGenome/Genetic_origin' => 'CLASSIFICATION record',
                    'VariantOnGenome/Published_as' => $aVariant['published_as'],
                    'VariantOnGenome/Remarks' => 'VKGL data sharing initiative Nederland' .
                        (!str_ends_with($aVariant['status'], 'opposite')? '' : '; Variant classification is in conflict with' . ($aVariant['status'] == 'internal-opposite' ? 'in this center.' : ' a different center.')),
                    'VariantOnGenome/Remarks_Non_Public' => array(
                        'warning' => 'Do not remove or edit this field!',
                        'updates' => array(),
                    ),
                    'vots' => array(),
                );

                // Some of these columns are optional.
                if (!$bClassification) {
                    unset($aVOGEntry['VariantOnGenome/ClinicalClassification']);
                }
                if (!$bGeneticOrigin) {
                    unset($aVOGEntry['VariantOnGenome/Genetic_origin']);
                }
                if (!$bPublishedAs) {
                    unset($aVOGEntry['VariantOnGenome/Published_as']);
                }
                if (!$bRemarks) {
                    unset($aVOGEntry['VariantOnGenome/Remarks']);
                }
                if (!$bRemarksNonPublic) {
                    unset($aVOGEntry['VariantOnGenome/Remarks_Non_Public']);
                }

                // Fill VOTs.
                // Check if the variant is CNV, this one doesn't need a vot (variant on transcript).
                if ($aVariant['type'] == "CNV") {
                    $aVOGEntry['vots'] = array();
                } elseif ($aVariant['type'] == 'SNV') {
                    $aCache = Caches::getMapping($sVariant);
                    if ($aCache != false) {
                        foreach ($aCache as $sSource => $aMappings) {
                            foreach ($aMappings as $sTranscript => $aMapping) {
                                $aTranscriptNoVersion = explode(".", $sTranscript);
                                $HGVSMapping = HGVS::check($aMapping['c']);
                                $HGVSMappingPos = $HGVSMapping->getData();
                                // Check if the transcript already exists in the database.
                                // Starting with the newest version (from $aMappings),
                                //  counting down the version number to see which version is present in the database ($aTranscripts).
                                for ($i = $aTranscriptNoVersion[1]; $i > 0; $i--) {
                                    if (array_key_exists($aTranscriptNoVersion[0] . "." . $i, $aTranscripts)) {
                                        // Shorten the DNA and protein fields, if needed.
                                        if (strlen($aMapping['c']) > $nMaxVOTDNALength) {
                                            $aMapping['c'] = $this->shortenDNAInsertions($aMapping['c']);
                                        }
                                        if (strlen($aMapping['p']) > $nMaxVOTProteinLength) {
                                            $aMapping['p'] = $this->shortenProteinInsertions($aMapping['p']);
                                        }
                                        $sTranscriptId = $aTranscripts[$aTranscriptNoVersion[0] . "." . $i];
                                        $aVOGEntry['vots'][$sTranscriptId] = [
                                            'transcriptid' => $sTranscriptId,
                                            'effectid' => $aVOGEntry['effectid'],
                                            'position_c_start' => ($HGVSMappingPos['position_start'] ?? null),
                                            'position_c_start_intron' => ($HGVSMappingPos['position_start_intron'] ?? null),
                                            'position_c_end' => ($HGVSMappingPos['position_end'] ?? null),
                                            'position_c_end_intron' => ($HGVSMappingPos['position_end_intron'] ?? null),
                                            'VariantOnTranscript/DNA' => ($aMapping['c'] ?: '-'),
                                            'VariantOnTranscript/RNA' => ($aMapping['r'] ?: '-'),
                                            'VariantOnTranscript/Protein' => ($aMapping['p'] ?: '-'),
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    // For comparison reasons.
                    ksort($aVOGEntry['vots']);
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
                            throw new \Exception("Variant ID $sVariant has an unparsable JSON object for center {$aVariant['center']} ($nCenterID)");
                        }
                    } elseif ($bRemarksNonPublic) {
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
                                'position_c_start' => ($aVOT[2] ?: null),
                                'position_c_start_intron' => ($aVOT[3] ?: null),
                                'position_c_end' => ($aVOT[4] ?: null),
                                'position_c_end_intron' => ($aVOT[5] ?: null),
                                'VariantOnTranscript/DNA' => $aVOT[6],
                                'VariantOnTranscript/RNA' => $aVOT[7],
                                'VariantOnTranscript/Protein' => $aVOT[8],
                            );
                        }
                        ksort($aDataLOVD[$sLOVDKey]['vots']);
                        // If $aVOGEntry is empty, we will fill it using the database ($aDataLOVD[$sLOVDKey]['vots'].
                        if (!$aVOGEntry['vots']) {
                            $aVOGEntry['vots'] = $aDataLOVD[$sLOVDKey]['vots'];
                        }
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
                    // Determine if there are any differences.
                    $aDiff = array();
                    foreach ($aDataLOVD[$sLOVDKey] as $sKey => $Value) {
                        if (!isset($aVOGEntry[$sKey]) || $Value != $aVOGEntry[$sKey]) {
                            $aDiff[$sKey] = array(
                                $Value,
                                (!isset($aVOGEntry[$sKey]) ? 'NULL' : $aVOGEntry[$sKey]),
                            );
                            if ($bRemarksNonPublic) {
                                // Also report differences.
                                if ($sKey == 'vots') {
                                    // We won't report changes per field here, just per transcript.
                                    // But, only report differences in VOTs that aren't the classification.
                                    // We can't just remove the effectid from $aDiff, as that array is
                                    //  being used to process the diff into the DB. So, hide it in the comparison only.
                                    $aTmpClassification = array('effectid' => 99); // Value doesn't actually matter.
                                    foreach (array_unique(array_merge(array_keys($aDiff['vots'][0]), array_keys($aDiff['vots'][1]))) as $nTranscriptID) {
                                        if (!isset($aDiff['vots'][0][$nTranscriptID])) {
                                            $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Added mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                                        } elseif (!isset($aDiff['vots'][1][$nTranscriptID])) {
                                            $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Removed mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                                        } elseif (array_diff_key($aDiff['vots'][0][$nTranscriptID], $aTmpClassification) != array_diff_key($aDiff['vots'][1][$nTranscriptID], $aTmpClassification)) {
                                            // VOT is different, outside of the effectid fields.
                                            $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey][] = 'Updated mapping to transcript ' . array_search($nTranscriptID, $aTranscripts) . '.';
                                        }
                                    }
                                } elseif ($sKey != 'VariantOnGenome/Remarks_Non_Public') {
                                    // Don't self-report, of course.
                                    $aVOGEntry['VariantOnGenome/Remarks_Non_Public']['updates'][$sNow][$sKey] = array($Value, $aVOGEntry[$sKey]);
                                }
                            }
                        }
                    }

                    // Because we were building this while building up the diff array:
                    if ($bRemarksNonPublic && $aDiff) {
                        $aDiff['VariantOnGenome/Remarks_Non_Public'][1] = $aVOGEntry['VariantOnGenome/Remarks_Non_Public'];
                    }

                    // Run update, if needed.
                    if ($aDiff) {
                        // Update atomically, we don't want half updates.
                        $_DB->beginTransaction();

                        // Start with the VOTs.
                        if (isset($aDiff['vots'])) {
                            foreach (array_unique(array_merge(array_keys($aDiff['vots'][0]), array_keys($aDiff['vots'][1]))) as $nTranscriptID) {
                                if (!isset($aDiff['vots'][0][$nTranscriptID])) {
                                    // Add the transcript.
                                    $aVOT = $aDiff['vots'][1][$nTranscriptID];
                                    $_DB->q('INSERT INTO ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                                        (id, ' . implode(', ', array_map(function ($sField) {
                                            return '`' . $sField . '`';
                                        }, array_keys($aVOT))) . ')
                                        VALUES (?' . str_repeat(', ?', count($aVOT)) . ')', array_merge(array($aVOGEntry['id']), array_values($aVOT)));
                                } elseif (!isset($aDiff['vots'][1][$nTranscriptID])) {
                                    // Remove the transcript.
                                    $_DB->q('DELETE FROM ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                                        WHERE id = ? AND transcriptid = ?', array($aVOGEntry['id'], $nTranscriptID));
                                } elseif ($aDiff['vots'][0][$nTranscriptID] != $aDiff['vots'][1][$nTranscriptID]) {
                                    // Update the transcript, remove 'transcriptid' as an updateable field (it shouldn't be there, but still).
                                    $aFieldsToUpdate = array_diff_key($aDiff['vots'][1][$nTranscriptID], array('transcriptid' => 0));
                                    $_DB->q('UPDATE ' . TABLE_VARIANTS_ON_TRANSCRIPTS . ' SET ' .
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

                        $_DB->q('UPDATE ' . TABLE_VARIANTS . ' SET ' .
                            implode(', ', array_map(function ($sField) {
                                return '`' . $sField . '` = ?';
                            }, array_keys($aFieldsToUpdate))) . '
                            WHERE id = ?', array_merge(array_values($aFieldsToUpdate), array($aVOGEntry['id'])));

                        // If we get here, everything went well.
                        $_DB->commit();


                        //str_contains
                        if ($aDataLOVD[$sLOVDKey]['statusid'] == STATUS_HIDDEN && str_contains($aDataLOVD[$sLOVDKey]['VariantOnGenome/Remarks'], 'no longer found')){
                            $aVariantsCreated[$sChromosome] ++;
                        } else {
                            $aVariantsUpdated[$sChromosome] ++;
                        }
                        continue;
                    }
                    // If we get here, there was nothing to update, data is still the same.
                    $aVariantsSkipped[$sChromosome] ++;
                    continue;
                }

                // Variant hasn't been seen yet by this center. Create it in the database.
                // Do this only, if we don't have LOVD variants that need to be cached.

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
                $aVOTs = $aVOGEntry['vots'];
                unset($aVOGEntry['vots']);
                $aFields = array_keys($aVOGEntry);
                $_DB->q('INSERT INTO ' . TABLE_VARIANTS . '
                    (' . implode(', ', array_map(function ($sField) {
                        return '`' . $sField . '`';
                    }, $aFields)) . ')
                    VALUES (?' . str_repeat(', ?', count($aFields) - 1) . ')', array_values($aVOGEntry));
                $aVOGEntry['id'] = $_DB->lastInsertId();

                // Then the VOTs.
                foreach ($aVOTs as $aVOT) {
                    // Add the transcript.
                    $_DB->q('INSERT INTO ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                        (id, ' . implode(', ', array_map(function ($sField) {
                            return '`' . $sField . '`';
                        }, array_keys($aVOT))) . ')
                        VALUES (?' . str_repeat(', ?', count($aVOT)) . ')', array_merge(array($aVOGEntry['id']), array_values($aVOT)));
                }
                // If we get here, everything went well.
                $_DB->commit();

                $aVariantsCreated[$sChromosome] ++;
            }

            // Showing count per chromosome.
            $this->Log->add("Chromosome: " . $sChromosome .
                ":\n\tCreated: " . $aVariantsCreated[$sChromosome] .
                "\n\tUpdated: " . $aVariantsUpdated[$sChromosome] .
                "\n\tDeleted: " . $aVariantsDeleted[$sChromosome] .
                "\n\tSkipped: " . $aVariantsSkipped[$sChromosome]);


        }

        // Total count of variants created, updated, deleted or skipped.
        $this->statistics['created'] = array_sum($aVariantsCreated);
        $this->statistics['updated'] = array_sum($aVariantsUpdated);
        $this->statistics['deleted'] = array_sum($aVariantsDeleted);
        $this->statistics['skipped'] = array_sum($aVariantsSkipped);

        $this->Log->add("Total variants created: " . array_sum($aVariantsCreated) .
        "\nTotal variants updated: " . array_sum($aVariantsUpdated) .
        "\nTotal variants deleted: " . array_sum($aVariantsDeleted) .
        "\nTotal variants skipped: " . array_sum($aVariantsSkipped));

        if (!LOVD_plus) {
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
                    WHERE (updated_date IS NULL OR updated_date < ?) AND id IN (?' . str_repeat(', ?', count($aGenesUpdated) - 1) . ')',
                        array_merge(array(0, $sNow, $sNow), $aGenesUpdated));
                $nUpdated = $q->rowCount();
                $this->Log->add('[Totals] Gene(s) updated: ' . $nUpdated . '/' . count($aGenesUpdated) . '.');
            }
        }

        return true;
    }





    public function saveErrors (string $sFile): bool
    {
        // Save errors to disk.
        // No need to sort anything; the input file was already fully sorted.
        $aData = [implode("\t", $this->data_rejected_output_header)];
        foreach ($this->data_rejected as $aVariant) {
            $aLine = [];
            foreach ($this->data_rejected_output_header as $sField) {
                $aLine[] = ($aVariant[$sField] ?? '');
            }
            $aData[] = implode("\t", $aLine);
        }
        $aData[] = '';

        // Save the data.
        return (bool) file_put_contents(
            $sFile,
            implode("\r\n", $aData)
        );
    }





    private function shortenDNAInsertions ($sDNA)
    {
        // Radically shortens DNA insertions. We could do this more intelligently and shorten only what's necessary,
        //  but we don't use this for VOG/DNA entries anyway and doing this right would make this a lot more complex.
        if (preg_match('/ins([ACGTN]+)\b/', $sDNA, $aRegs)) {
            $sDNA = str_replace($aRegs[0], 'insN[' . strlen($aRegs[1]) . ']', $sDNA);
        }

        return $sDNA;
    }





    private function shortenProteinInsertions ($sProtein)
    {
        // Radically shortens protein insertions. We could do this more intelligently and shorten only what's necessary,
        //  but this is for annotation only and doing this right would make this a lot more complex.
        if (preg_match('/ins(([A-Z][a-z]{2})+)\b/', $sProtein, $aRegs)) {
            $sProtein = str_replace($aRegs[0], 'insXaa[' . (strlen($aRegs[1]) / 3) . ']', $sProtein);
        }

        return $sProtein;
    }
}
?>
