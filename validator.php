#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-05-12
 * Modified    : 2026-05-28
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <m.de_koster@lumc.nl>
 *
 *************/

namespace LOVD\VKGL;
require_once(ROOT_PATH . '/settings.php');
use LOVD\Settings;

class Validator
{
    // Class abstracting the validation of the aggregated data by comparing it to the previous release's data file.

    public static function validate (string $sFileNew, string $sFileOld): Validator
    {
        $o = new Validator();
        $aPreviousData = $o->parse($sFileOld);
        $aCurrentData = $o->parse($sFileNew);
        $o->compareOldAndNew($aPreviousData, $aCurrentData);
        return $o;
    }





    public function checkIfFileExistAndReadable(string $sFile): array
    {
        // Checking if the files (old and new) created by the aggregator script exist,
        //  are readable, and can be opened.
        if (!file_exists($sFile) || !is_readable($sFile)) {
            throw new \Exception("File {$sFile} does not exist or is not readable!");
        }
        $aLines = file($sFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File {$sFile} could not be opened!");
        }
        return $aLines;
    }





    public function CompareOldAndNew (array $aArrayOld, array $aArrayNew): bool
    {
        // The created files (old and new) from the aggregator script are compared to decide if variants were
        //  deleted, created or updated.
        // The next step is to add information of the newest aggregator file to statistics.json.
        // The amount per center, the amount per status, and the amount of internal_opposites per center.
        $oSettingsCutoff = new Settings(substr_replace(RELEASE_PATH, '', -8, 8) . '/settings.json');
        $oStatistics = new Settings(substr_replace(RELEASE_PATH, '', -8, 8) . '/statistics.json');
        // The name of the current directory, so it can be used later.
        $sDirectory = substr(RELEASE_PATH, -7);
        // To see if a variant was deleted or created.
        $aKeysNew = array_keys($aArrayNew);
        $aKeysOld = array_keys($aArrayOld);
        // Saves the variants in an array.
        $aCreated = array_diff($aKeysNew, $aKeysOld);
        $aDeleted = array_diff($aKeysOld, $aKeysNew);
        // Check if the keys are the same.
        // If the keys are the same, the values are compared.
        // If the values are the same, the variant is unchanged, otherwise it has been updated.
        $aDifferentKeys = array_intersect($aKeysNew, $aKeysOld);
        $aUpdated = [];
        $aUnchanged = [];
        foreach ($aDifferentKeys as $sDifferentKey) {
            $aValuesNew = $aArrayNew[$sDifferentKey];
            $aValuesOld = $aArrayOld[$sDifferentKey];
            if ($aValuesNew !== $aValuesOld) {
                $aUpdated[] = $sDifferentKey;
            } else {
                $aUnchanged[] = $sDifferentKey;
            }
        }
        $nTotal = count($aUpdated) + count($aUnchanged) + count($aCreated);
        $SaveStatistics = $oSettingsCutoff->get('validation_cutoffs|aggregated');
        // Check if to many variants have changed (deletion, creation or updated).
        if (round(count($aUpdated) / $nTotal * 100, 2) > $SaveStatistics['updated']) {
            throw new \Exception("The amount of variants updated is to high!");
        } elseif (round(count($aCreated) / $nTotal * 100, 2) > $SaveStatistics['created']) {
            throw new \Exception("The amount of variants created is to high!");
        } elseif (round(count($aDeleted) / $nTotal * 100, 2) > $SaveStatistics['deleted']) {
            throw new \Exception("The amount of variants deleted is to high!");
        } else {
            // This where the data will be saved to set it in statistics.json.
            $aCenters = [];
            $aStatus = [];
            $aInternalConflicts = [];
            foreach ($aArrayNew as $sKeyNew => $aObservations) {
                $aCenters[] = strtolower($aObservations['center']);
                // Because the amount of external_opposites per center are saved separately,
                //  only external_opposite will be saved in status and can be changed to opposite.
                if ($aObservations['status'] == 'external_opposite') {
                    $aObservations['status'] = 'opposite';
                } elseif ($aObservations['status'] == 'internal_opposite') {
                    $aInternalConflicts[] = strtolower($aObservations['center']);
                }
                $aStatus[] = $aObservations['status'];
            }
            // Counts the amount of variants per center.
            $aCountCenters = array_count_values($aCenters);
            // Counts the amount of variants per status.
            $aCountStatus = array_count_values($aStatus);
            // Counts the amount of internal_opposites per center.
            $aCountInternalConflicts = array_count_values($aInternalConflicts);
            // Because internal_opposite is saved separately, it is not needed in status.
            unset($aCountStatus['internal_opposite']);
            // Sorts the key values in alphabetical order.
            ksort($aCountCenters);
            ksort($aCountStatus);
            ksort($aCountInternalConflicts);
            $aStatistics = [
                'centers' => $aCountCenters,
                'status' => $aCountStatus,
                'internal_conflicts' => $aCountInternalConflicts,
            ];
            // Created data is set in statistics.json.
            $oStatistics->set($sDirectory, $aStatistics);
        }
        return true;
    }





    public function parse (string $sFile): array
    {
        // Parse the given data file and return the data as an array.

        // Check if the file exists, is readable, and can be opened.
        $aLines = Validator::checkIfFileExistAndReadable($sFile);
        $aArray = Validator::putFileintoArray($aLines);
        return $aArray;
    }





    public function putFileintoArray(array $aLinesFile): array
    {
        // First line should be headers.
        $aHeaders = explode("\t", array_shift($aLinesFile));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));
        $aData = [];
        foreach ($aLinesFile as $nLine => $sLine) {
            $aDataLine = explode("\t", rtrim($sLine));
            // Trim quotes off of the data.
            $aDataLine = array_map(function($sData) {
                return trim($sData, '"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                // We accidentally trimmed off empty field(s).
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }
            $aVariant = array_combine($aHeaders, $aDataLine);
            $sCenterVariant = $aVariant['center'] . ':' . $aVariant['genomic_native_normalized'];
            // Creating format in which the data will be stored.
            $aData[$sCenterVariant] = $aVariant;
        }
        return $aData;
    }
}