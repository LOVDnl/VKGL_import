#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-05-14
 * Modified    : 2026-07-07
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once 'libs/HGVS-syntax-checker/HGVS.php';
require_once 'libs/HGVS-syntax-checker/caches.php';
use LOVD\HGVS\HGVS_Chromosome;
use LOVD\HGVS\HGVS;
use LOVD\Log;
use LOVD\Settings;
use LOVD\HGVS\Caches;

class Processor
{
    private array $aCenterIDs = [];

    private array $aCentersFound = [];

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

    private array $_SERVER = [];

    private $Settings;

    private $Log;

    public function connectLOVD()
    {
        // These variables are global scope, this way lovd function can access them.
        global $_CONF, $_DB, $_TABLES, $_SETT;
        // Open connection, and check if user accounts exist.
        $this->Log->add("Connecting to LOVD...");
        // Find LOVD installation, run it's inc-init.php to get DB connection, initiate $_SETT, etc.
        define('ROOT_PATH', $this->Settings->get('lovd_path') . '/');;
        define('FORMAT_ALLOW_TEXTPLAIN', true);
        $_GET['format'] = 'text/plain';
        // To prevent notices when running inc-init.php.
        $this->_SERVER = array_merge($this->_SERVER, array(
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
        // We have error messages suppressed anyway, as the LOVD in question will complain when it tries to define "SSL" as well.
        define('SSL', true);
        require ROOT_PATH . 'inc-init.php';
        require ROOT_PATH . 'inc-lib-form.php';
        require ROOT_PATH . 'inc-lib-variants.php'; // For lovd_fixHGVS().
        ini_set('display_errors', '1'); // We do want to see errors from here on.
        $this->Log->add("Connected...");
    }





    public function getStatistics(): array
    {
        return $this->statistics;
    }





    public function hasErrors (): bool
    {
        return (bool) count($this->data_rejected);
    }





    public function parse(string $sFile): bool
    {
        // These variables are global scope, this way lovd function can access them.
        global $_CONF, $_DB, $_TABLES, $_SETT;
        // Parse every file, and add the contents to $this->data.
        if (!file_exists($sFile) || !is_readable($sFile)) {
            throw new \Exception("File $sFile does not exist or is not readable.");
        }
        $aLines = file($sFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File $sFile could not be opened.");
        }
        // First line should be headers.
        $aHeaders = explode("\t", array_shift($aLines));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));
        // Check given refseq build.
        $nNoCorrectBuildFound = 0;
        $sRefSeqBuildLOVD = $_DB->q('SELECT refseq_build FROM ' . TABLE_CONFIG)->fetchColumn();
        foreach ($aLines as $nLine => $sLine) {
            $aDataLine = explode("\t", rtrim($sLine));
            // Trim quotes off of the data.
            $aDataLine = array_map(function ($sData) {
                return trim($sData, '"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                // We accidentally trimmed of empty fields.
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }
            $aVariant = array_combine($aHeaders, $aDataLine);
            $sCenterName = strtolower($aVariant['center']);
            if (!in_array($sCenterName, $this->aCentersFound)) {
                $this->aCentersFound[] = $sCenterName;
            }
            $aNC = HGVS_Chromosome::getInfoByNC(strstr($aVariant['genomic_native_normalized'], ':', true));
            $aVariant['native_build'] = $aNC['build'];
            // Compare the build from the database to the build from genomic_native_normalized.
            // If the build isn't the same, compare the build from the database to the build from genomic_liftover_normalized
            //  to see if the builds match. If both builds from genomic_native_normalized and genomic_liftover_normalized
            //  don't match the build from the database, the variant will not be added to the dataset.
            // They will be saved in a separate file and a counter is user to track the amount of variants not added to
            //  the dataset.
            if ($aVariant['native_build'] != $sRefSeqBuildLOVD) {
                $aNC = HGVS_Chromosome::getInfoByNC(strstr($aVariant['genomic_liftover_normalized'], ':', true));
                if ($aNC == false || $aNC['build'] != $sRefSeqBuildLOVD) {
                    $this->data_rejected[$nLine][] = array_merge(
                        $aVariant,
                        [
                            'error' => "LOVD has been configured to use $sRefSeqBuildLOVD, this variant uses only {$aVariant['native_build']}.",
                        ]
                    );
                    // A counter to see how many variants won't be added to the dataset.
                    $nNoCorrectBuildFound++;
                    continue;
                } else {
                    $sGenomicNormalized = $aVariant['genomic_liftover_normalized'];
                }
            } else {
                $sGenomicNormalized = $aVariant['genomic_native_normalized'];
            }
            $nCenterID = $this->Settings->get("centers|$sCenterName|id");
            if (!$nCenterID) {
                throw new \Exception("Center $sCenterName does not exist, or ID does not exist.");
            }
            // Check if the id was already assigned to a different center.
            if (in_array($nCenterID, $this->aCenterIDs)) {
                if (!array_key_exists($sCenterName, $this->aCenterIDs)) {
                    throw new \Exception("This ID is already assigned to a different center.");
                } else {
                    $this->aCenterIDs[$sCenterName] = $nCenterID;
                }
            } else {
                $this->aCenterIDs[$sCenterName] = $nCenterID;
            }
            $this->aCenterIDs['VKGL'] = $this->Settings->get('vkgl_generic_id');
            list($sRefSeq,) = explode(':', $sGenomicNormalized, 2);
            // Use the variant description combined with the center id as key, this way it's easier to check if the variant is already in the database.
            $nCenterID = str_pad($nCenterID,5, "0", STR_PAD_LEFT);
            $this->data[$aNC["chr"]. ":" . $sRefSeq][$nCenterID . ":" .$sGenomicNormalized] = $aVariant;
        }
        $this->Log->add("There is/are " . $nNoCorrectBuildFound . " variant(s) of which the build doesn't align with the database.");
        $this->aCentersFound = array_unique($this->aCentersFound);
        $this->nCentersFound = count($this->aCentersFound);
        return true;
    }





    public static function process(string $sFile, Settings $Settings, Log $Log = null): Processor
    {
        $o = new Processor();
        $o->Settings = $Settings;
        if ($Log) {
            $o->Log = $Log;
        }
        $o->connectLOVD();
        $o->parse($sFile);
        $o->processingData($o);
        return $o;
    }





    public function processingData(): bool
    {
        // These variables are global scope, this way lovd function can access them.
        global $_CONF, $_DB, $_TABLES, $_SETT;

        $_SETT = array();

        // Check the given user accounts by using the user IDs ($this->aCenterIDs).
        // Check if the users with ID are found in LOVD.
        // Cast id to UNSIGNED to make sure our ints match.
        $aUsers = $_DB->q('SELECT CAST(id AS UNSIGNED) AS id, name FROM ' . TABLE_USERS . ' WHERE id IN (?' . str_repeat(', ?', count($this->aCenterIDs) - 1) . ') ORDER BY id',
                array_values($this->aCenterIDs))->fetchAllCombine();
        $bAccountsOK = true;
        // Check if the generic VKGL account is found in LOVD.
        $bFound = (isset($aUsers[$this->Settings->get('vkgl_generic_id')]));
        if (!$bFound) {
            $bAccountsOK = false;
            $this->Log->add("The generic vkgl account is not found");
        }

        // The other centers that we have collected from the input file.
        foreach ($this->aCentersFound as $sCenterName) {
            // Check to see if the user can be found in LOVD.
            // If the user can't be found, the script will log which user couldn't be found.
            // We're checking all users, and if one or more users couldn't be found, the pipeline will be stopped.
            $sCenterName = strtolower($sCenterName);
            $bFound = (isset($aUsers[$this->Settings->get('centers')[$sCenterName]['id']]));
            if (!$bFound) {
                $bAccountsOK = false;
                $this->Log->add("The center: $sCenterName could not be found in LOVD");
            }
        }
        if (!$bAccountsOK) {
            // If one or more of the users couldn't be found, the pipeline will stop.
            throw new \Exception(($bAccountsOK ? "" : "Error: Failed to get all LOVD user accounts." . "\n"));
        }
        // We might be running for some time.
        set_time_limit(0);

        // Now correct all cDNA variants, using the cache, and predict RNA and protein.

        // Store all of LOVD's transcripts, we need them; array(id_ncbi => id).
        $aTranscripts = $_DB->q('
            SELECT id_ncbi, id
            FROM ' . TABLE_TRANSCRIPTS . '
            ORDER BY id_ncbi')->fetchAllCombine();

        $aVariantsCreated = array(); // Collects counters per chromosome.
        $aVariantsUpdated = array(); // Collects counters per chromosome.
        $aVariantsDeleted = array(); // Collects counters per chromosome.
        $aVariantsSkipped = array(); // Collects counters per chromosome.
        $sNow = date('Y-m-d H:i:s');

        // Process updates per chromosome. So an update is given per chromosome,
        //  but at the end we show the total amount per category (created, updated, deleted, and skipped).
        // We won't process variants that we can't hold.
        $nMaxDNALength = lovd_getColumnLength(TABLE_VARIANTS, 'VariantOnGenome/DNA'); // Max is 255
        $nMaxPublishedAsLength = lovd_getColumnLength(TABLE_VARIANTS, 'VariantOnGenome/Published_as'); // Max is 100
        $nMaxProteinLength = lovd_getColumnLength(TABLE_VARIANTS_ON_TRANSCRIPTS, 'VariantOnTranscript/Protein'); // Max is 255

        foreach ($this->data as $sChromosomeRefSeq => $aVariants) {
            list($sChromosome, $sRefSeq) = explode(':', $sChromosomeRefSeq, 2);
            // Reset counters.
            $aVariantsCreated[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsUpdated[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsDeleted[$sChromosome] = 0; // Counters per chromosome.
            $aVariantsSkipped[$sChromosome] = 0; // Counters per chromosome.

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
                WHERE vog.chromosome = ? AND vog.created_by IN (?' . str_repeat(', ?', count($this->aCenterIDs) - 1) . ')
                GROUP BY vog.id',
                    array_merge(
                        array($sRefSeq, $sChromosome),
                        array_values($this->aCenterIDs)))->fetchAllGroupAssoc();
            // Check all LOVD data and mark removed data.
            // Older data may not have been fully normalized, and we will find new records even though we already had them.
            foreach ($aDataLOVD as $sLOVDKey => $aLOVDVariant) {
                list($nCenterID, $sLOVDVariant) = explode(':', $sLOVDKey, 2);
                // Perhaps we find that we want to remove this variant.
                $bRemoveVariant = false;
                $sRemoveMessage = '';
                // Check if it exists in the NC cache as a different name. This assumes the variant has been cached before.
                if (!Caches::hasCorrectedNC($sLOVDVariant)) {
                    $sVariantCorrected = $sLOVDVariant;
                } else {
                    $sVariantCorrected = Caches::getCorrectedNC($sLOVDVariant);
                    // Check if this is a cached error message.
                    if (Caches::hasErrors($sLOVDVariant)) {
                        // Variant is actually in error. These are OK to be removed, since we don't want them.
                        // If the variant is still in the source, that's OK, because he will be skipped there, too.
                        $bRemoveVariant = true;
                        $aErrorMessages = json_decode($sVariantCorrected, true);
                        array_walk($aErrorMessages, function (&$sValue, $sError) {
                            $sValue = $sError . ': ' . $sValue;
                        });
                        $sRemoveMessage = 'Variant is in error: ' . implode('; ', $aErrorMessages);
                    } elseif ($sLOVDVariant != $sVariantCorrected) {
                        // LOVD variant is in the cache, and has a different name.

                        // Whoops. From a previous release, we have uncorrected data in LOVD. It won't match this way.
                        // Correct the key; this will make a match possible. The update will then fix the entry's DNA field.
                        $sLOVDNewKey = $nCenterID . ':' . $sVariantCorrected;
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
                }

                if (!$bRemoveVariant && $this->Settings->get('delete_redundant_variants') == 'y'
                        && (!isset($this->data[$sChromosomeRefSeq][$nCenterID . ":" . $sVariantCorrected]))) {
                    // We aren't already removing this variant, but we don't actually see this variant anymore.
                    // The variant is lost, there's nothing to do about it. If the user has indicated so, remove it,
                    //  but mark it only as removed. Later we can always decide to actually remove these entries.
                    $bRemoveVariant = true;
                    $sRemoveMessage = 'Variant no longer found in the VKGL dataset for this center.';
                }

                // Remove variant if needed. Don't touch the Remarks_Non_Public, we don't want to complicate things.
                // Also, don't run this if we don't have to. Check status and current remarks.
                // FIXME: Record variants being removed in the history
                if ($bRemoveVariant) {
                    $sRemoveMessage = 'VKGL data sharing initiative Nederland' .
                        (!$sRemoveMessage ? '' : '; ' . $sRemoveMessage);
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

            $sRefSeqBuildLOVD = $_DB->q('SELECT refseq_build FROM ' . TABLE_CONFIG)->fetchColumn();
            foreach ($aVariants as $sVariantDescription => $aVariant) {
                // See if the correct build (build from the database) is present in genomic_native_normalized
                //  or in genomic_liftover_normalized.
                // We know it's in one of the two, otherwise the variant wouldn't have been added to the dataset,
                //  this check was done while the data was parsed.
                $aNC = HGVS_Chromosome::getInfoByNC(strstr($aVariant['genomic_native_normalized'], ':', true));
                $aVariant['native_build'] = $aNC['build'];
                if ($aVariant['native_build'] == $sRefSeqBuildLOVD) {
                    list($sRefSeq, $sDNA) = explode(':', $aVariant['genomic_native_normalized']);
                    $sGenomicNormalized = $aVariant['genomic_native_normalized'];
                } else {
                    list($sRefSeq, $sDNA) = explode(':', $aVariant['genomic_liftover_normalized']);
                    $sGenomicNormalized = $aVariant['genomic_liftover_normalized'];
                }
                if (!$sRefSeq) {
                    // Eh, no chromosome?
                    throw new \Exception("Error: Cannot get chromosome from variant " . $sRefSeq . ".");
                }
                // LOVD+ has a much shorter DNA field; only 150 characters.
                // Trying to put in a variant that's bigger will crash this process.
                // However, we may also simply find variants longer than 255 characters.
                // We will simply skip whatever is too long.
                if (strlen($sDNA) > $nMaxDNALength) {
                    $aVariantsSkipped[$sChromosome]++;
                    continue;
                }

                // Loop through centers who found this variant.
                // Build variant entry.
                $aPublishedAs = json_decode($aVariant['annotation'], true);
                if (is_array($aPublishedAs['reported_as'])){
                    $aPublishedAs['reported_as'] = implode(",", $aPublishedAs['reported_as']);
                }
                $aVariant['published_as'] = lovd_shortenString($aPublishedAs['reported_as'], $nMaxPublishedAsLength);
                $sCenter = strtolower($aVariant['center']);
                $nCenterID = str_pad($this->aCenterIDs[$sCenter],5, "0", STR_PAD_LEFT);
                $sLOVDKey = $nCenterID . ":" . $sGenomicNormalized;
                if (!$aVariant['published_as'] && $bPublishedAs) {
                    // If the reported_as in column annotation is empty or doesn't exist
                    //  we're looking in the database to see if the column is filled.
                    // If the column is filled, this information will be kept.
                    // If the column is empty, genomic_native_reported or genomic_liftover_reported will be used.
                    // Which one is used is based on which one contains the same build as the database.

                    // Check if key exist and if value is not empty.
                    if (!isset($aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'])) {
                        // Check if the native build is the same as the build from the database.
                        // This decides which 'genomic_?_reported to take.
                        // Do limit the input a bit, 150 should be enough.
                        if ($aVariant['native_build'] == $sRefSeqBuildLOVD) {
                            $aVariant['published_as'] = lovd_shortenString($aVariant['genomic_native_reported'], $nMaxPublishedAsLength);
                        } else {
                            $aVariant['published_as'] = lovd_shortenString($aVariant['genomic_liftover_reported'], $nMaxPublishedAsLength);
                        }
                    } else {
                        $aVariant['published_as'] = $aDataLOVD[$sLOVDKey]['VariantOnGenome/Published_as'];
                    }
                }
                // Add some needed fields; (type, position_start, position_end).
                $HGVS = HGVS::check($sGenomicNormalized);
                $HGVSData = $HGVS->getData();
                if ($HGVSData['type'] == '>'){
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
                    'created_by' => $this->aCenterIDs[$sCenter],
                    // Created_date will be added later, right now we don't have it to prevent unneeded differences.
                    'owned_by' => ($aVariant['status'] == 'single-lab' && $this->Settings->get('public_singlelab_owners') != 'y' ? // Should single-lab entry get the generic VKGL account as owner?
                        $this->Settings->get('vkgl_generic_id') : $this->aCenterIDs[$sCenter]),
                    'statusid' => (string)($aVariant['status'] == 'opposite' ? STATUS_HIDDEN : STATUS_OK),
                    // Don't let internal conflicts cause notices here.
                    'VariantOnGenome/ClinicalClassification' => (!isset($this->effect_mapping_classification[$aVariant['classification']])? '-' :
                        $this->effect_mapping_classification[$aVariant['classification']]),
                    'VariantOnGenome/DNA' => $sDNA, // Can actually also update, if the LOVD data is not correct.
                    'VariantOnGenome/DBID' => '', // FIXME: Will be filled in later for records to be created!
                    'VariantOnGenome/Genetic_origin' => 'CLASSIFICATION record',
                    'VariantOnGenome/Published_as' => $aVariant['published_as'],
                    'VariantOnGenome/Remarks' => 'VKGL data sharing initiative Nederland' . ($aVariant['status'] != 'opposite' ? '' : '; Variant classification is in conflict with a different center.'),
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
                } elseif ($aVariant['type'] == "SNV") {
                    $aCache = Caches::getMapping($sGenomicNormalized);
                    if ($aCache != false) {
                        foreach ($aCache as $sSource => $aMappings) {
                            foreach ($aMappings as $sTranscript => $aMapping) {
                                $aTranscriptNoVersion = explode(".", $sTranscript);
                                $HGVSMapping = HGVS::check($aMapping['c']);
                                $HGVSMappingPos = $HGVSMapping->getData();
                                $aMapping['p'] = lovd_shortenString($aMapping['p'], $nMaxProteinLength);
                                // Check if the transcript already exists in the database.
                                // Starting with the newest version (from $aMappings),
                                //  counting down the version number to see which version is present in the database ($aTranscripts).
                                for ($i = $aTranscriptNoVersion[1]; $i > 0; $i--) {
                                    if (array_key_exists($aTranscriptNoVersion[0] . "." . $i, $aTranscripts)) {
                                        $sTranscriptId = $aTranscripts[$aTranscriptNoVersion[0] . "." . $i];
                                        // Positions: will be filled using the hgvs library.
                                        $aVOGEntry['vots'][$sTranscriptId] = [
                                            'transcriptid' => $sTranscriptId,
                                            'effectid' => $aVOGEntry['effectid'],
                                            'position_c_start' => $HGVSMappingPos['position_start'],
                                            'position_c_start_intron' => $HGVSMappingPos['position_start_intron'],
                                            'position_c_end' => $HGVSMappingPos['position_end'],
                                            'position_c_end_intron' => $HGVSMappingPos['position_end_intron'],
                                            'VariantOnTranscript/DNA' => $aMapping['c'],
                                            'VariantOnTranscript/RNA' => $aMapping['r'],
                                            'VariantOnTranscript/Protein' => $aMapping['p'],
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
                            throw new \Exception("Error: Variant ID $sGenomicNormalized has an unparsable JSON object for center " . $sCenter . "(" . $this->aCenterIDs[$sCenter] . ").");
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
                                'position_c_start' => $aVOT[2],
                                'position_c_start_intron' => $aVOT[3],
                                'position_c_end' => $aVOT[4],
                                'position_c_end_intron' => $aVOT[5],
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

                        $aVariantsUpdated[$sChromosome]++;
                        continue;
                    }
                    // If we get here, there was nothing to update, data is still the same.
                    $aVariantsSkipped[$sChromosome]++;
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
                foreach ($aVOTs as $nTranscriptID => $aVOT) {
                    // Add the transcript.
                    $_DB->q('INSERT INTO ' . TABLE_VARIANTS_ON_TRANSCRIPTS . '
                        (id, ' . implode(', ', array_map(function ($sField) {
                            return '`' . $sField . '`';
                        }, array_keys($aVOT))) . ')
                        VALUES (?' . str_repeat(', ?', count($aVOT)) . ')', array_merge(array($aVOGEntry['id']), array_values($aVOT)));
                }
                // If we get here, everything went well.
                $_DB->commit();

                $aVariantsCreated[$sChromosome]++;
            }

            // Showing count per chromosome.
            $this->Log->add("Chromosome: " . $sChromosome .
                ":\n\tCreated: " . $aVariantsCreated[$sChromosome] .
                "\n\tUpdated: " . $aVariantsUpdated[$sChromosome] .
                "\n\tDeleted: " . $aVariantsDeleted[$sChromosome] .
                "\n\tSkipped: " . $aVariantsSkipped[$sChromosome]);

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
                        WHERE updated_date < ? AND id IN (?' . str_repeat(', ?', count($aGenesUpdated) - 1) . ')',
                            array_merge(array(0, $sNow, $sNow), $aGenesUpdated), false);
                    $nUpdated = $q->rowCount();
                    $this->Log->add('[Totals] Gene(s) updated: ' . $nUpdated . '/' . count($aGenesUpdated) . '.');
                }
            }
        }

        // Total count of variants created, updated, deleted or skipped.
        $this->statistics['created'] = array_sum($aVariantsCreated);
        $this->statistics['updated'] = array_sum($aVariantsUpdated);
        $this->statistics['deleted'] = array_sum($aVariantsDeleted);
        $this->statistics['skipped'] = array_sum($aVariantsSkipped);

        return true;
    }





    public function saveErrors (string $sFile): bool
    {
        // Save errors to disk.
        $aData = [implode("\t", $this->data_rejected_output_header)];
        ksort($this->data_rejected);
        foreach ($this->data_rejected as $aVariants) {
            foreach ($aVariants as $aVariant) {
                $aLine = [];
                foreach ($this->data_rejected_output_header as $sField) {
                    $aLine[] = ($aVariant[$sField] ?? '');
                }
                $aData[] = implode("\t", $aLine);
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
?>