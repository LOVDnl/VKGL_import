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
    
}
?>