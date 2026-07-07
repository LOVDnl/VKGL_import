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
    
}
?>