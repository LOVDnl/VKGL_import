#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-04-28
 * Modified    : 2026-05-18
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
        'genomic_native_normalized',
        'genomic_native_reported',
        'classification',
        'status',
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





    public function checkAnnotation ($aAnnotations): array
    {
        // This function checks if the column 'annotation' is empty or not.
        // If it is empty it will be returned as an empty array.
        // If there is one 'annotation' array it will be returned with no changes.
        // If there are multiple 'annotation' arrays, they will be merged to form
        // one 'annotation' array. The merged array is returned.
        $aAnnotations = array_filter($aAnnotations);
        if (!$aAnnotations) {
            return [];
        } elseif (count($aAnnotations) == 1) {
            return current($aAnnotations);
        } else {
            // This is where the arrays in $aAnnotations are merged recursively,
            //  this way the arrays are merged correctly.
            // This way the zygosity, protocol, and reported_as stay seperated.
            $aMerged = array_merge_recursive(...$aAnnotations);
            // Now the arrays are merged, the next step is to check for each
            //  part (zygosity, protocol, and reported_as) to see if they are an array.
            // If it is an array, only the unique values are used.
            // If it is not an array, it means there is only one value.
            return array_map(function($aUniqueValue) {
                if (is_array($aUniqueValue)) {
                    return array_unique($aUniqueValue);
                } else {
                    return $aUniqueValue;
                }
            }, $aMerged);
        }
    }





    public function checkClassifications ($aClassifications): string
    {
        // In this function the classifications are compared.
        // If there is one unique classification, it is kept.
        // If there are more than one unique classification, they need to be compared.
        // A conclusion will be drawn from the comparison.
        if (count(array_unique($aClassifications)) == 1) {
            $sClassification = $aClassifications[0];
        } else {
            // We have seen multiple classifications of this variant.
            // Rules: report opposites; */VUS to VUS; LB/B to LB; LP/P to LP.
            // Flipping the array makes the values unique and makes it easier to work with the values;
            //  isset()s are faster than array_search() and in_array().
            $aClassifications = array_flip($aClassifications);
            if ((isset($aClassifications['B']) || isset($aClassifications['LB']))
                    && (isset($aClassifications['P']) || isset($aClassifications['LP']))) {
                // Internal conflict within center. These are reported in the opposites file.
                // Change column 'classification' to conflicting, we want to store the conflict to report this in LOVD in a non-public entry.
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
                }
            }
        }
        return $sClassification;
    }





    public function checkUniqueOrDie ($aMustBeUnique): string
    {
        // This functions checks if the values are the same by checking if
        //  there is only one unique value.
        // If there are more than one unique value, the script will stop.
        $aMustBeUnique = array_unique($aMustBeUnique);
        if (count($aMustBeUnique) == 1) {
            $sUnique = $aMustBeUnique[0];
        } else {
            $aMustBeUnique = array_filter($aMustBeUnique);
            if (count($aMustBeUnique) == 1) {
                $sUnique = current($aMustBeUnique);
            } else {
                throw new \Exception("Variant merging conflict for " . implode(", ", $aMustBeUnique));
            }
        }
        return $sUnique;
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
                                    'classification' => $sClassifications,
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





    public function createReportedAs (array &$aVariantObservation)
    {
        // This function combines the columns: 'gene', 'transcript', 'cDNA', and 'protein' are combined
        //  to create the string 'reported_as' in 'annotation'.
        // The string 'reported_as' will follow a specific format, but because
        //  there is a possibility not all columns contain values the format may not always
        //  be complete, this is the reason if-loops are used to check.
        // The format is as follows: gene transcript:cDNA (protein)
        // Examples:
        // "reported_as":"NM_002529.4" (contains only transcript)
        // "reported_as":"FAM87A NM_005874.1 (NP_560236.2)" (contains gene, transcript, and protein)
        $sReportedAs = '';
        if ($aVariantObservation['gene']) {
            $sReportedAs .= $aVariantObservation['gene'];
        }
        if ($aVariantObservation['transcript'] && $aVariantObservation['gene']) {
            $sReportedAs .= " " . $aVariantObservation['transcript'];
        } elseif ($aVariantObservation['transcript']) {
            $sReportedAs .= $aVariantObservation['transcript'];
        }
        if ($aVariantObservation['cDNA'] && $aVariantObservation['transcript']) {
            $sReportedAs .= ":" . $aVariantObservation['cDNA'];
        } elseif ($aVariantObservation['cDNA']) {
            $sReportedAs .= " " . $aVariantObservation['cDNA'];
        }
        if ($aVariantObservation['protein']) {
            $sReportedAs .= " (" . $aVariantObservation['protein'] . ")";
        }
        $sReportedAs = ltrim($sReportedAs, " ");
        $aVariantObservation['annotation']['reported_as'] = $sReportedAs;
        unset($aVariantObservation['gene'], $aVariantObservation['transcript'], $aVariantObservation['cDNA'], $aVariantObservation['protein']);
    }





    public function groupByCenter (): bool
    {
        // In this function the data is grouped based on center, this way
        //  all variants from the same center are together.
        // The next step is to check the amount of a variant within one center.
        // If there are more than one, the columns are compared to see if they are different.
        // Some columns will be merged (example: if there are different genes), some will give
        //  an error (example: if the type is different), and sometimes a conclusion is drawn
        //  based on the difference (example: the classification will say 'conflicting'
        //  if the classifications are opposite).
        foreach ($this->data as $sVariant => $aData) {
            foreach ($aData as $sCenter => $aObservations) {
                if (count($aObservations) == 1) {
                    // If the variant is found once in a center, the 'annotation' column is decoded to be able to add
                    //  different values to the column. In this case the columns 'gene', 'transcript', 'cDNA', 'protein'
                    //  will combine in an array with key 'reported_as' which will be added to 'annotation'.
                    $aObservations[0]['annotation'] = json_decode($aObservations[0]['annotation'], true);
                    Aggregator::createReportedAs($aObservations[0]);
                    $this->data[$sVariant][$sCenter] = $aObservations[0];
                } else {
                    // If there are more than one line, we need to check or combine the columns.
                    foreach ($aObservations as $i => $aObservation) {
                        $aObservation['annotation'] = json_decode($aObservation['annotation'], true);
                        // This function will combine the columns 'gene', 'transcript', 'cDNA', and 'protein' into one array
                        //  which will be combined with the existing column 'annotation' to form the new 'annotation' column.
                        Aggregator::createReportedAs($aObservation);
                        // Adding classifications to annotation, this way the original classification is saved if the
                        //  classification column is changed to something else.
                        $aObservation['annotation']['classification'] = $aObservation['classification'];
                        $aObservations[$i] = $aObservation;
                    }
                    // This is where the data is saved after checking what needs to be done with the value of each column
                    //  if multiple of the same variant were found within the same center.
                    $aMergedVariant = [];
                    foreach (array_keys($aObservations[0]) as $sColumn) {
                        // For each column the information of one variant (which has been found multiple times in the same center)
                        //  will be combined in $aValues. This way the values can be compared based on column.
                        $aValues = [];
                        foreach ($aObservations as $aObservation) {
                            $aValues[] = $aObservation[$sColumn];
                        }
                        // The columns 'type', 'genomic_liftover_normalized', 'classification', 'annotation'
                        //  'genomic_native_reported', and 'genomic_liftover_reported' will be compared individually.
                        // Different strategies will be applied in the process of comparing values.
                        // For some columns the values MUST be unique, otherwise the script will be stopped.
                        // The values of the column 'classifications' will be handled separately.
                        // The column 'annotation' will be merged recursively.
                        // For each of the remaining columns the values are combined into a single string.
                        if ($sColumn == 'type' || $sColumn == 'genomic_liftover_normalized') {
                            $aMergedVariant[$sColumn] = Aggregator::checkUniqueOrDie($aValues);
                        } elseif ($sColumn == 'classification') {
                            $aMergedVariant[$sColumn] = Aggregator::checkClassifications($aValues);
                        } elseif ($sColumn == 'annotation') {
                            $aMergedVariant[$sColumn] = Aggregator::checkAnnotation($aValues);
                        } else {
                            $aMergedVariant[$sColumn] = implode(", ", array_unique(array_filter($aValues)));
                        }
                    }
                    // This is where the created line will be saved if no error or conflict occurred.
                    $this->data[$sVariant][$sCenter] = $aMergedVariant;
                    // This is where the data will be saved if the classification resulted in a conflict.
                    if ($this->data[$sVariant][$sCenter]['classification'] == 'conflicting') {
                        $this->data_rejected[$sVariant][$sCenter] = [
                            'type' => $this->data[$sVariant][$sCenter]['type'],
                            'genomic_native_reported' => $this->data[$sVariant][$sCenter]['genomic_native_reported'],
                            'classification' => implode(", ",$this->data[$sVariant][$sCenter]['annotation']['classification']),
                            'status' => 'internal_opposite'
                        ];
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

            $aVariantObservations = array_combine($aHeaders, $aDataLine);
            // Store the data grouped by the normalized variant description, then per center.
            // This allows us to easily group the multiple observations within one center and then compare the data
            //  between centers.
            $sGenomicNativeNormalized = $aVariantObservations['genomic_native_normalized'];
            $sCenter = $aVariantObservations['center'];
            $this->data[$sGenomicNativeNormalized][$sCenter][] = $aVariantObservations;
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
