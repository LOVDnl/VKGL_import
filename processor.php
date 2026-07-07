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
}
?>