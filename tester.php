<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-07-29
 * Modified    : 2026-08-10
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

namespace LOVD;
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/log.php');
require_once(__DIR__ . '/LOVD.php');

class Tester
{
    private static array $releases = [
        '2025-02',
        '2025-03',
        '2025-04',
        '2025-05',
        '2025-06',
        '2025-07',
        '2025-08',
    ];

    private static $Settings;

    private static array $outputfiles = [
        'vkgl_data.01-raw.tsv',
        'vkgl_data.02-normalized.tsv',
        'vkgl_data.03-aggregated.tsv',
    ];
    private static $Log;

    public static function deleteReleases()
    {
        // Remove release files, this way the test can be repeated from the beginning.
        foreach (self::$releases as $sRelease) {
            // Check if directory exists.
            if (is_dir(__DIR__ . '/tests/releases/' . $sRelease)) {
                exec('rm -r ' . __DIR__ . '/tests/releases/' . $sRelease, $sRemove, $nCodeResult);
                if ($nCodeResult != 0) {
                    echo "Error " . $nCodeResult. ": " . array_search($nCodeResult, self::$Settings->get('error_codes')) . "\n";
                    echo "Directory $sRelease could not be removed.\n";
                    exit;
                }
            }
        }
        return true;
    }





    public static function runTestReleases()
    {
        // Run the pipeline for each folder, if an error occurs the script should stop.
        foreach (self::$releases as $sRelease) {
            self::$Log->add("Running $sRelease");
            echo "Running $sRelease\n";
            exec('./run_pipeline.php --testing --release=' . $sRelease, $sRunPipeline, $nResultCode);
            if ($nResultCode != 0) {
                echo "Error " . $nResultCode . ": " . array_search($nResultCode, self::$Settings->get('error_codes')) . "\n";
                echo "An error occured in the pipeline.\n";
                echo implode("\n", array_slice($sRunPipeline, -5));
                echo "\n";
                exit;
            }
            foreach (self::$outputfiles as $sOutputFile) {
                $sOutputs = __DIR__ . '/tests/outputs/' . $sRelease . '/' . $sOutputFile;
                $sReleases = __DIR__ . '/tests/releases/' . $sRelease . '/' . $sOutputFile;
                if (md5_file($sOutputs) === md5_file($sReleases)) {
                    echo "Output file " . $sOutputFile . " aligns with expectations\n";
                } else {
                    echo "Output file " . $sOutputFile . " doesn't align with expectations.\n";
                    die(self::$Settings->get('error_codes|EXIT_ERROR_OUTPUT_CONTENT_ERROR'));
                }
            }
        }
        return true;
    }





    public static function test()
    {
        self::$Settings = new Settings( __DIR__ . '/tests/settings.json');
        self::$Log = new Log(__DIR__ . '/tests/status.log');
        self::$Log->add("Removing existing release folders.");
        self::deleteReleases();
        self::$Log->add("Connecting to LOVD.");
        $b = LOVD::connect(self::$Settings->get('lovd_path') ?? '', self::$Log);
        if (!$b) {
            echo "Unable to connect to LOVD.\n";
            die(self::$Settings->get('error_codes|EXIT_ERROR_SETTINGS_CONTENT_ERROR'));
        }
        self::$Log->add("Removing data of test centers from LOVD.");
        foreach (self::$Settings->get('centers') as $aCenterInformation) {
            LOVD::deleteFromDatabase($aCenterInformation['id']);
        }
        self::$Log->add("Starting the test releases.");
        self::runTestReleases();
    }
}
Tester::test();