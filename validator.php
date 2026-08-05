<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-05-12
 * Modified    : 2026-08-05
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
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
        $o->validateAggregatedData($aPreviousData, $aCurrentData, $aCutoffs);
        return $o;
    }





    public function getStatistics (): array
    {
        return $this->statistics;
    }





    public function validateAggregatedData (array $aPreviousData, array $aCurrentData, array $aValidationCutoffs): bool
    {
        // The aggregated data file is compared to the previous release's aggregated data file
        //  to measure the number of created, updated, and deleted variants.

        // The arrays have keys in "center:variant" format, so we can do quick checks on those.
        $aPreviousVariants = array_keys($aPreviousData);
        $aCurrentVariants = array_keys($aCurrentData);

        // Now, we can easily determine counts for created and deleted variants.
        $nCreated = count(array_diff($aCurrentVariants, $aPreviousVariants));
        $nDeleted = count(array_diff($aPreviousVariants, $aCurrentVariants));

        // Now, check all variants in both data sets to see if they have been updated.
        $aUpdatedVariantKeys = array_intersect($aCurrentVariants, $aPreviousVariants);
        $nUpdated = 0;
        foreach ($aUpdatedVariantKeys as $sVariantKey) {
            if ($aCurrentData[$sVariantKey] !== $aPreviousData[$sVariantKey]) {
                $nUpdated ++;
            }
        }
        $nTotalChanges = $nCreated + $nUpdated + $nDeleted;

        // Check if the counts are below the expected values, using cutoffs that we got from the settings.
        $nMaxCreated = ($aValidationCutoffs['created'] ?? 0);
        $nMaxUpdated = ($aValidationCutoffs['updated'] ?? 0);
        $nMaxDeleted = ($aValidationCutoffs['deleted'] ?? 0);
        if ($nCreated > $nMaxCreated) {
            throw new \Exception("The number of variants created ($nCreated) is too high (cutoff: $nMaxCreated)");
        } elseif ($nUpdated > $nMaxUpdated) {
            throw new \Exception("The number of variants updated ($nUpdated) is too high (cutoff: $nMaxUpdated)");
        } elseif ($nDeleted > $nMaxDeleted) {
            throw new \Exception("The number of variants deleted ($nDeleted) too high (cutoff: $nMaxDeleted)");
        } elseif (!$nTotalChanges) {
            throw new \Exception("There is nothing to do; this release does not introduce any changes");
        }

        // Now, collect the statistics, so the pipeline can collect it from us.
        $aStatistics = [
            'centers' => [],
            'status' => [],
            'errors' => [],
            'internal_conflicts' => [],
            'diff' => [
                'created' => $nCreated,
                'updated' => $nUpdated,
                'deleted' => $nDeleted,
                'total' => $nTotalChanges,
            ],
        ];

        foreach ($aCurrentData as $aVariant) {
            // Store the number of variants per center.
            $aStatistics['centers'][$aVariant['center']] = ($aStatistics['centers'][$aVariant['center']] ?? 0) + 1;

            // Store the number of variants per status, but store internal conflicts separately.
            // Also, external opposites are stored simply as "opposite" as the distinction is no longer relevant.
            if ($aVariant['status'] == 'internal_opposite') {
                $aStatistics['internal_conflicts'][$aVariant['center']] = ($aStatistics['internal_conflicts'][$aVariant['center']] ?? 0) + 1;
            } elseif ($aVariant['status'] == 'external_opposite') {
                $aStatistics['status']['opposite'] = ($aStatistics['status']['opposite'] ?? 0) + 1;
            } else {
                $aStatistics['status'][$aVariant['status']] = ($aStatistics['status'][$aVariant['status']] ?? 0) + 1;
            }
        }

        // Sort the key values in alphabetical order and then store the statistics internally.
        ksort($aStatistics['centers']);
        ksort($aStatistics['status']);
        ksort($aStatistics['internal_conflicts']);
        $this->statistics = $aStatistics;

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
        foreach ($aLines as $sLine) {
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
