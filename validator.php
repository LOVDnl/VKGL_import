#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-05-12
 * Modified    : 2026-05-29
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <m.de_koster@lumc.nl>
 *
 *************/

namespace LOVD\VKGL;

class Validator
{
    // Class abstracting the validation of the aggregated data by comparing it to the previous release's data file.
    private array $statistics = [];

    public static function validate (string $sPreviousFile, string $sCurrentFile, array $aCutoffs): Validator
    {
        $o = new Validator();
        $aPreviousData = $o->parse($sPreviousFile);
        $aCurrentData = $o->parse($sCurrentFile);
        $o->compareOldAndNew($aPreviousData, $aCurrentData, $aCutoffs);
        return $o;
    }





    public function getStatistics (): array
    {
        return $this->statistics;
    }





    public function CompareOldAndNew (array $aPreviousData, array $aCurrentData, array $aValidationCutoffs): bool
    {
        // The created files (old and new) from the aggregator script are compared to decide if variants were
        //  deleted, created or updated.

        // To see if a variant was deleted or created.
        $aKeysNew = array_keys($aCurrentData);
        $aKeysOld = array_keys($aPreviousData);
        // Saves the variants in an array.
        $aCreated = array_diff($aKeysNew, $aKeysOld);
        $aDeleted = array_diff($aKeysOld, $aKeysNew);
        // Check if the keys are the same.
        // If the keys are the same, the values are compared.
        // If the values are the same, the variant is unchanged, otherwise it has been updated.
        $aDifferentKeys = array_intersect($aKeysNew, $aKeysOld);
        $aUpdated = [];
        foreach ($aDifferentKeys as $sDifferentKey) {
            $aCurrentValues = $aCurrentData[$sDifferentKey];
            $aPreviousValues = $aPreviousData[$sDifferentKey];
            if ($aCurrentValues !== $aPreviousValues) {
                $aUpdated[] = $sDifferentKey;
            }
        }
        $nTotalChanges = count($aCreated) + count($aUpdated) + count($aDeleted);
        // Check if too many variants have changed (deletion, creation or updated).
        if (count($aUpdated) > ($aValidationCutoffs['updated'] ?? 0)) {
            throw new \Exception("The number of variants updated is too high");
        } elseif (count($aCreated) > ($aValidationCutoffs['created'] ?? 0)) {
            throw new \Exception("The number of variants created is too high");
        } elseif (count($aDeleted) > ($aValidationCutoffs['deleted'] ?? 0)) {
            throw new \Exception("The number of variants deleted is too high");
        } elseif (!$nTotalChanges) {
            throw new \Exception("There is nothing to do; this release does not introduce any changes");
        } else {
            // This where the data will be saved to set it in statistics.json.
            $aCenters = [];
            $aStatus = [];
            $aInternalConflicts = [];
            foreach ($aCurrentData as $aObservations) {
                $aCenters[] = strtolower($aObservations['center']);
                // Because the number of external_opposites per center are saved separately,
                //  only external_opposite will be saved in status and can be changed to opposite.
                if ($aObservations['status'] == 'external_opposite') {
                    $aObservations['status'] = 'opposite';
                } elseif ($aObservations['status'] == 'internal_opposite') {
                    $aInternalConflicts[] = strtolower($aObservations['center']);
                }
                $aStatus[] = $aObservations['status'];
            }
            // Counts the number of variants per center.
            $aCountCenters = array_count_values($aCenters);
            // Counts the number of variants per status.
            $aCountStatus = array_count_values($aStatus);
            // Counts the number of internal_opposites per center.
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
            $this->statistics = $aStatistics;
        }
        return true;
    }





    public function parse (string $sFile): array
    {
        // Parse the given data file and return the data as an array.

        // Check if the file exists, is readable, and can be opened.
        if (!file_exists($sFile) || !is_readable($sFile) || !is_file($sFile)) {
            throw new \Exception("File $sFile does not exist or is not readable");
        }
        $aLines = file($sFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File $sFile could not be opened");
        }

        // Read the data and construct an array.
        // First line should be headers. No need to use strtolower() here, this is our own format.
        $aHeaders = explode("\t", array_shift($aLines));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0, $nHeaders, '"'));

        $aData = [];
        foreach ($aLines as $nLine => $sLine) {
            $aDataLine = explode("\t", rtrim($sLine));
            // Trim quotes off of the data.
            $aDataLine = array_map(function($sData) {
                return trim($sData, '"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                // We accidentally trimmed off empty fields.
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }

            $aVariant = array_combine($aHeaders, $aDataLine);
            // Store the data grouped by the center and normalized variant description together in one key value.
            // This allows us to easily run diffs.
            $aData[$aVariant['center'] . ':' . $aVariant['genomic_native_normalized']] = $aVariant;
        }

        return $aData;
    }
}