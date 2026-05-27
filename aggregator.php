#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-04-28
 * Modified    : 2026-05-27
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmers : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>,
 *               Marit de Koster <M.de_Koster@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

class Aggregator
{
    // Class abstracting the aggregation of all variant observations within one center, and checking the variant's classifications between centers.
    private array $data = [];
    private array $data_rejected = [];
    private array $data_output_header = [
        'center',
        'type',
        'genomic_native_normalized',
        'genomic_native_reported',
        'genomic_liftover_normalized',
        'genomic_liftover_reported',
        'classification',
        'status',
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
        'genomic_native_normalized',
        'genomic_native_reported',
    ];

    public static function aggregate (string $sFile): Aggregator
    {
        // Group all variant observations within a center and compare the classifications between centers.
        $o = new Aggregator();
        $o->parse($sFile);
        $o->groupByCenter();
        $o->compareCenters();
        return $o;
    }





    public function mergeAnnotations ($aAnnotations): array
    {
        // Merge the given annotations into one larger array.
        // We only use this method if we have more than one annotation array.

        // Merge $aAnnotations recursively and then go and check the results for each field.
        $aMerged = array_merge_recursive(...$aAnnotations);
        // Check each field in the annotations; if it is an array, only the unique values are used.
        // Avoid using arrays when possible.
        return array_map(function ($Value)
        {
            if (is_array($Value)) {
                $aValues = array_unique(array_filter($Value));
                if (count($aValues) == 1) {
                    return current($aValues);
                } else {
                    return $aValues;
                }
            } else {
                return $Value;
            }
        }, $aMerged);
    }





    public function mergeClassifications ($aClassifications): string
    {
        // Merge all classifications for this variant. We only use this method if we have more than one classification,
        //  so try to come up with a single conclusion.

        // Rules: report opposites; */VUS to VUS; LB/B to LB; LP/P to LP.
        // Flipping the array makes it easier to work with the values;
        //  isset()s are faster than array_search() and in_array().
        $aClassifications = array_flip($aClassifications);
        if ((isset($aClassifications['B']) || isset($aClassifications['LB']))
            && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
            // Internal conflict within center. These are reported in the error file. Change column 'classification'
            // to conflicting, we want to store the conflict to report this in LOVD in a non-public entry.
            $sClassification = 'conflicting';

        } elseif (isset($aClassifications['VUS'])) {
            // VUS and something else, not a conflict. OK, VUS then.
            $sClassification = 'VUS';

        } else {
            // Still multiple values. LB/B to LB, LP/P to LP.
            if (isset($aClassifications['B']) && isset($aClassifications['LB'])) {
                $sClassification = 'LB';
            } elseif (isset($aClassifications['P']) && isset($aClassifications['LP'])) {
                $sClassification = 'LP';
            } else {
                // This should never happen, but still.
                throw new \Exception("Variant merging conflict for classifications " . implode(', ', array_keys($aClassifications)));
            }
        }

        return $sClassification;
    }





    public function compareCenters (): bool
    {
        // In this function the values between different centers will be compared.
        foreach ($this->data as $sVariant => $aCenters) {
            if (count($aCenters) == 1) {
                // If there is one center for a variant, we are looking at the classification to decide the status.
                $sCenter = array_key_first($aCenters);
                if ($aCenters[$sCenter]['classification'] == 'conflicting') {
                    $this->data[$sVariant][$sCenter]['status'] = 'internal_opposite';
                } else {
                    $this->data[$sVariant][$sCenter]['status'] = 'single_lab';
                }
            } else {
                $aClassifications = [];
                // If there are multiple centers for one variant, it is checked if one or more
                // of the centers have 'conflicting' as the classification.
                foreach ($aCenters as $sCenter => $aVariantObservation) {
                    if ($aVariantObservation['classification'] == 'conflicting') {
                        $this->data[$sVariant][$sCenter]['status'] = 'internal_opposite';
                    } else {
                        $aClassifications[$sCenter] = $aVariantObservation['classification'];
                    }
                }
                // Then it will be checked if there are still multiple centers for this variant.
                if (count($aClassifications) == 1) {
                    $this->data[$sVariant][array_key_first($aClassifications)]['status'] = 'single_lab';
                } elseif (count($aClassifications) > 1) {
                    // If there are more than one center for the variant, the classifications
                    // are compared to decide the status.
                    // Flipping the array makes the values unique and makes it easier to work with the values;
                    // This way we can count the amount of keys, the classifications have become the keys.
                    $aClassificationsFlip = array_flip($aClassifications);
                    if (count($aClassificationsFlip) == 1) {
                        // If the classifications align, we get here.
                        // One unique value, everybody agrees.
                        foreach ($aClassifications as $sCenter => $sClassification) {
                            $this->data[$sVariant][$sCenter]['status'] = 'consensus';
                        }
                    } else {
                        // We get here if the classifications are different between centers.
                        // isset()s are faster than array_search() and in_array().
                        if ((isset($aClassificationsFlip['B']) || isset($aClassificationsFlip['LB']))
                                && (isset($aClassificationsFlip['P']) || isset($aClassificationsFlip['LP']))) {
                            // Opposite.
                            // A string is built with the classification for each center, this way the user
                            //  can sort based on center and still get the information on why there is a conflict.
                            $sClassifications = '';
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                if ($sClassifications == '') {
                                    $sClassifications .= $sCenter . ": " . $sClassification;
                                } else {
                                    $sClassifications .= ", " . $sCenter . ": " . $sClassification;
                                }
                            }
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                // This is where the data will be saved if the classification resulted in a conflict.
                                $this->data_rejected[$sVariant][$sCenter] = [
                                    'type' => $this->data[$sVariant][$sCenter]['type'],
                                    'genomic_native_reported' => $this->data[$sVariant][$sCenter]['genomic_native_reported'],
                                    'classifications' => $sClassifications,
                                    'status' => 'external_opposite'
                                ];
                            }
                            $sStatus = 'external_opposite';
                        } elseif (isset($aClassificationsFlip['VUS'])) {
                            $sStatus = 'non_consensus';
                        } else {
                            $sStatus = 'consensus';
                        }
                        foreach ($aClassifications as $sCenter => $sClassification) {
                            $this->data[$sVariant][$sCenter]['status'] = $sStatus;
                        }
                    }
                }
            }
        }
        return true;
    }





    public function createReportedAs (array &$aVariant): void
    {
        // Combine the "gene", "transcript", "cDNA", and "protein" columns into one description for the "reported_as"
        //  field in the "annotation" column. Not all fields may be present, so we'll need to check all combinations.
        // The full format is "GENE transcript:cDNA (protein)". When fewer fields are present, the missing fields will
        //  be adapted, e.g.:
        // "IVD NM_002225.5:c.265G>A (p.(Val89Ile))",
        // "IVD NM_002225.5",
        // "IVD c.265G>A",
        // "NM_002225.5:c.265G>A",
        // "IVD NM_002225.5 p.(Val89Ile)", etc.
        $sReportedAs = $aVariant['gene'];
        if ($aVariant['transcript']) {
            $sReportedAs .= (!$sReportedAs? '' : ' ') . $aVariant['transcript'];
        }
        if ($aVariant['cDNA']) {
            $sReportedAs .= (!$sReportedAs? '' : ($aVariant['transcript']? ':' : ' ')) . $aVariant['cDNA'];
        }
        if ($aVariant['protein']) {
            if ($aVariant['cDNA']) {
                $sReportedAs .= ' (' . $aVariant['protein'] . ')';
            } else {
                $sReportedAs .= (!$sReportedAs? '' : ' ') . $aVariant['protein'];
            }
        }
        $aVariant['annotation']['reported_as'] = $sReportedAs;
        // Remove the fields from the data to save memory.
        unset($aVariant['gene'], $aVariant['transcript'], $aVariant['cDNA'], $aVariant['protein']);
    }





    public function groupByCenter (): bool
    {
        // Group all observations of the same variant within one center. We can have multiple observations because
        //  variants aren't always reported using the same description. However, we've grouped on the normalized variant
        //  description, and we'll check all fields. Some fields are merged (e.g., annotation), some fields are compared
        //  carefully to create a new value (e.g., classification), and some fields MUST have the same value
        //  (e.g., type).

        foreach ($this->data as $sVariant => $aCenters) {
            foreach ($aCenters as $sCenter => $aObservations) {
                if (count($aObservations) == 1) {
                    // If the variant is found once in a center, we don't need to group anything.
                    // However, we will create the "reported_as" field and add that to the "annotation" column.
                    $aObservations[0]['annotation'] = json_decode($aObservations[0]['annotation'], true);
                    // Note that this method also removes redundant fields from $aObservations[0].
                    Aggregator::createReportedAs($aObservations[0]);
                    // Note that this leaves the annotation field unpacked.
                    $this->data[$sVariant][$sCenter] = $aObservations[0];

                } else {
                    // We have more than one observation of the same variant. Check or combine the columns.
                    foreach ($aObservations as $i => $aObservation) {
                        // First, create the "reported_as" field and add that to the "annotation" column.
                        $aObservation['annotation'] = json_decode($aObservation['annotation'], true);
                        // Note that this method also removes redundant fields from $aObservation.
                        Aggregator::createReportedAs($aObservation);
                        // Also add the classification to the annotation, so we can always find back the original
                        //  classifications of all observations (we may update the classification after this).
                        $aObservation['annotation']['classifications'] = $aObservation['classification'];
                        // Note that this leaves the annotation field unpacked.
                        $aObservations[$i] = $aObservation;
                    }

                    // Per column, check how we will merge the variant observations, and then build up the new entry.
                    $aMergedVariant = [];
                    foreach (array_keys($aObservations[0]) as $sColumn) {
                        // Combine all values for this column in $aValues, then check the column to see how to proceed.
                        $aValues = [];
                        foreach ($aObservations as $aObservation) {
                            $aValues[] = $aObservation[$sColumn];
                        }
                        // Only store the unique, non-empty values.
                        $aValues = array_unique(array_filter($aValues), SORT_REGULAR);

                        // Handle empty fields and unique values directly.
                        if (!$aValues) {
                            $aMergedVariant[$sColumn] = '';
                            continue;
                        } elseif (count($aValues) == 1) {
                            $aMergedVariant[$sColumn] = current($aValues);
                            continue;
                        }

                        // Different strategies will be applied in the process of comparing values.
                        // For some columns the values MUST be unique, otherwise the script will be stopped.
                        // The values of the column 'classifications' will be handled separately.
                        // The column 'annotation' will be merged recursively.
                        // For each of the remaining columns, the values are combined into a single string.
                        if ($sColumn == 'type' || $sColumn == 'genomic_liftover_normalized') {
                            // Disallow having more than one unique value.
                            throw new \Exception("Variant merging conflict for $sVariant in $sCenter, field $sColumn contains non-unique values " . implode(', ', $aValues));

                        } elseif ($sColumn == 'classification') {
                            // Compare all classifications and try to come up with a consensus value.
                            $aMergedVariant[$sColumn] = Aggregator::mergeClassifications($aValues);

                        } elseif ($sColumn == 'annotation') {
                            // Merge the annotation arrays recursively.
                            $aMergedVariant[$sColumn] = Aggregator::mergeAnnotations($aValues);

                        } else {
                            // For all other columns, simply combine the values into a single string.
                            $aMergedVariant[$sColumn] = implode(', ', $aValues);
                        }
                    }

                    // Save the merged entry, regardless of whether a conflict occurred.
                    $this->data[$sVariant][$sCenter] = $aMergedVariant;

                    // If comparing the classifications resulted in an internal conflict,
                    //  store the data in a report file that will be sent to the labs.
                    if ($aMergedVariant['classification'] == 'conflicting') {
                        $this->data_rejected[$sVariant][$sCenter] = array_merge(
                            $aMergedVariant,
                            [
                                'error' => 'Internal conflict, classifications: ' . implode(', ', $aMergedVariant['annotation']['classifications']) . '.',
                            ]
                        );
                    }
                }
            }
        }

        return true;
    }





    public function hasErrors (): bool
    {
        // This functions checks if there are conflicts found in the data.
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
            // Store the data grouped by the normalized variant description, then per center.
            // This allows us to easily group the multiple observations within one center and then compare the data
            //  between centers.
            $this->data[$aVariant['genomic_native_normalized']][$aVariant['center']][] = $aVariant;
        }

        return true;
    }





    public function save (string $sFile): bool
    {
        // Save the data to disk.
        $aData = [implode("\t", $this->data_output_header)];
        foreach ($this->data as $sVariant => $aVariantObservations) {
            foreach ($aVariantObservations as $sCenter => $aVariantObservation) {
                // Creating an array which contains all information of the variant.
                $aLine = [];
                foreach ($this->data_output_header as $sField) {
                    $Value = ($aVariantObservation[$sField] ?? '');
                    if ($sField == 'annotation' && $Value) {
                        $Value = json_encode($Value, JSON_UNESCAPED_UNICODE);
                    }
                    $aLine[] = $Value;
                }
                // Imploding the array, which results in all values going in the correct column.
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





    public function saveErrors (string $sFile): bool
    {
        // Save errors to disk.
        $aData = [implode("\t", $this->data_rejected_output_header)];
        foreach ($this->data_rejected as $sVariant => $aVariantObservations) {
            foreach ($aVariantObservations as $sCenter => $aVariantObservation) {
                // Creating an array which contains all information of the variant.
                $aLine = [];
                foreach ($this->data_rejected_output_header as $sField) {
                    $aLine[] = ($aVariantObservation[$sField] ?? '');
                }
                // Imploding the array, which results in all values going in the correct column.
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
