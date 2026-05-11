#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-27 (based on format_raw_VKGL_files.php)
 * Modified    : 2026-05-06
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

class Aggregator
{
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
            'classifications',
            'status',
    ];

    public static function aggregate (string $sFile): Aggregator
    {
        $o = new Aggregator();
        $o->parse($sFile);
        $o->groupByCenter();
        $o->compareCenters();
        return $o;
    }

    public function parse(string $sFile): bool
    {
        // Parse every file, and add the contents to $this->data.
        if (!file_exists($sFile) || !is_readable($sFile)) {
            throw new Exception("File $sFile does not exist or is not readable");
        }
        $aLines = file($sFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!$aLines) {
            throw new \Exception("File $sFile could not be opened");
        }

        // First line should be headers.
        $aHeaders = explode("\t", array_shift($aLines));
        $nHeaders = count($aHeaders);
        $aHeaders = array_map('trim', $aHeaders, array_fill(0,$nHeaders,'"'));

        foreach ($aLines as $nLine => $sLine) {
            $aDataLine = explode("\t", rtrim($sLine));
            // Trim quotes off of the data.
            $aDataLine = array_map(function($sData) {
                return trim($sData,'"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                //We accidently trimmed of empty fields.
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }
           $aVariantObservations = array_combine($aHeaders, $aDataLine);
            //Creating format in which the data will be stored.
            $this->data[$aVariantObservations['genomic_native_normalized']][$aVariantObservations['center']][] = [
                    'type' => $aVariantObservations['type'],
                    'genomic_native_reported' => $aVariantObservations['genomic_native_reported'],
                    'genomic_liftover_normalized' => $aVariantObservations['genomic_liftover_normalized'],
                    'genomic_liftover_reported' => $aVariantObservations['genomic_liftover_reported'],
                    'classification' => $aVariantObservations['classification'],
                    'gene' => $aVariantObservations['gene'],
                    'transcript' => $aVariantObservations['transcript'],
                    'cDNA' => $aVariantObservations['cDNA'],
                    'protein' => $aVariantObservations['protein'],
                    'annotation' => $aVariantObservations['annotation']
                ];
        }
        return true;
    }

    public function groupByCenter(): bool
    {
        //In this function we are checking if there are multiple lines
        //of the same variant within one center.
        foreach ($this->data as $sVariant => $aData) {
            foreach ($aData as $sCenter => $aObservations) {
                if (count($aObservations) == 1) {
                    $aObservations[0]['annotation'] = json_decode($aObservations[0]['annotation'], true);
                    Aggregator::createReportedAs($aObservations[0]);
                    $this->data[$sVariant][$sCenter] = $aObservations[0];
                } else {
                    //If there are more than one line, we need to check or combine the columns.
                    foreach ($aObservations as $i => $aVariantObservation) {
                       $aVariantObservation['annotation'] = json_decode($aVariantObservation['annotation'], true);
                       //This function combines multiple columns to combine them into the column 'annotation'.
                       Aggregator::createReportedAs($aVariantObservation);
                       //Adding classifications to annotation, this way the original classification is saved if the
                       //classification column is changed to something else.
                       $aVariantObservation['annotation']['classification'] = $aVariantObservation['classification'];
                       $aVariantObservations[$i] = $aVariantObservation;
                    }
                    $aMergedVariant = [];
                    foreach (array_keys($aVariantObservations[0]) as $sColumn) {
                        $aValues = [];
                        foreach ($aVariantObservations as $aVariantObservation) {
                            $aValues[] = $aVariantObservation[$sColumn];
                        }
                        //This is where the other columns are checked or combined.
                        if ($sColumn == 'type' || $sColumn == 'genomic_liftover_normalized') {
                            $aMergedVariant[$sColumn] = Aggregator::checkUniqueOrDie($aValues);
                        } elseif ($sColumn == 'classification') {
                            $aMergedVariant[$sColumn] = Aggregator::checkClassifications($aValues);
                        } elseif ($sColumn == 'annotation') {
                            $aMergedVariant[$sColumn] = Aggregator::checkAnnotation($aValues);
                        } else {
                            $aMergedVariant[$sColumn] = Aggregator::Merge($aValues);
                        }
                    }
                    //This is where the created line will be written into the output file.
                    $this->data[$sVariant][$sCenter] = $aMergedVariant;
                    //This is where the data will be written into another output file if a conflict occured.
                    if ($this->data[$sVariant][$sCenter]['classification'] == 'conflicting') {
                        $this->data_rejected[$sVariant][$sCenter]['type'] = $this->data[$sVariant][$sCenter]['type'];
                        $this->data_rejected[$sVariant][$sCenter]['genomic_native_reported'] = $this->data[$sVariant][$sCenter]['genomic_native_reported'];
                        $this->data_rejected[$sVariant][$sCenter]['classifications'] = implode(", ",$this->data[$sVariant][$sCenter]['annotation']['classification']);
                        $this->data_rejected[$sVariant][$sCenter]['status'] = 'internal_opposite';
                    }
                }
            }
        }
        return true;
    }

    public function createReportedAs(array &$aVariantObservation)
    {
        //This function combines the columns: 'gene', 'transcript', 'cDNA', and 'protein' are combined
        //to create the column 'reported_as' in 'annotation'.
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
        $aVariantObservation['annotation']['reported_as'] = $sReportedAs;
        unset($aVariantObservation['gene'], $aVariantObservation['transcript'], $aVariantObservation['cDNA'], $aVariantObservation['protein']);
    }


    public function checkUniqueOrDie($aMustBeUnique): string
    {
        //This functions checks if the values are the same by checking if
        //there is only one unique value.
        //If there are more than one unique value, the script will stop.
        if (count(array_unique($aMustBeUnique)) == 1) {
            $sUnique = $aMustBeUnique[0];
        } else {
            $aFilter = array_filter($aMustBeUnique);
            if (array_unique($aFilter) == 1){
                $sUnique = $aMustBeUnique[0];
            } else {
                throw new \Exception("Variant merging conflict for " . implode(", ", $aMustBeUnique));
            }
        }
        return $sUnique;
    }
    public function checkClassifications($aClassifications): string
    {
        //In this function the classifications are compared.
        //If there is one unique classification, it is kept.
        //If there are more than one unique classification, they need to be compared.
        //A conclusion will be drawn from the comparison.
        if (count(array_unique($aClassifications)) == 1) {
            $sClassification = $aClassifications[0];
        } else {
            $aClassificationsFlip = array_flip($aClassifications);
            if ((isset($aClassificationsFlip['B']) || isset($aClassificationsFlip['LB']))
                    && (isset($aClassificationsFlip['P']) || isset($aClassificationsFlip['LP']))) {
                $sClassification = 'conflicting';
            } elseif (isset($aClassificationsFlip['VUS'])) {
                $sClassification = 'VUS';
            } else {
                if (isset($aClassificationsFlip['B']) && isset($aClassificationsFlip['LB'])) {
                    $sClassification = 'LB';
                } elseif(isset($aClassificationsFlip['P']) && isset($aClassificationsFlip['LP'])) {
                    $sClassification = 'LP';
                }
            }
        }
        return $sClassification;
    }

    public function checkAnnotation($aAnnotation): array
    {
        //This function checks if the column 'annotation' is empty or not.
        //If it is empty it will be returned as an empty array.
        //If there is one 'annotation' array it will be returned with no changes.
        //If there are multiple 'annotation' arrays, they will be merged to form
        //one 'annotation' array. The merged array is returned.
        $aAnnotation = array_filter($aAnnotation);
        if (!$aAnnotation) {
            return [];
        } elseif (count($aAnnotation) == 1) {
            return array_values($aAnnotation);
        } else {
            $aMerged = array_merge_recursive(...$aAnnotation);
            return array_map(function($aUniqueValue){
                if (is_array($aUniqueValue)) {
                    return array_unique($aUniqueValue);
                } else {
                    return $aUniqueValue;
                }
            }, $aMerged);
        }
    }

    public function Merge($aCreateUniqueValues): string
    {
        //This function merges the other columns that have not been checked by the other functions.
        $aCreateUniqueValues = array_filter($aCreateUniqueValues);
        if (count(array_unique($aCreateUniqueValues)) == 1) {
            $sUniqueValues = $aCreateUniqueValues[0];
        } else {
            $sUniqueValues = implode(", ", array_unique($aCreateUniqueValues));
        }
        return $sUniqueValues;
    }

    public function compareCenters(): bool
    {
        //In this function the values between different centers will be compared.
        foreach ($this->data as $sVariant => $aData) {
            if (count($aData) == 1) {
                //If there is one center for a variant, we are looking at the classification to decide the status.
                $sCenter = array_key_first($aData);
                if ($aData[$sCenter]['classification'] == 'conflicting') {
                    $this->data[$sVariant][$sCenter]['status'] = 'internal_opposite';
                } else {
                    $this->data[$sVariant][$sCenter]['status'] = 'single_lab';
                }
            } else {
                $aClassifications = [];
                //If there are multiple centers for one variant, it is checked if one or more
                //of the centers have 'conflicting' as the classification.
                foreach ($aData as $sCenter => $aVariantObservation) {
                    if ($aVariantObservation['classification'] == 'conflicting') {
                        $this->data[$sVariant][$sCenter]['status'] = 'internal_opposite';
                    } else {
                        $aClassifications[$sCenter] = $aVariantObservation['classification'];
                    }
                }
                //Then it will be checked if there are still multiple centers for this variant.
                if (count($aClassifications) == 1) {
                    $this->data[$sVariant][$sCenter]['status'] = 'single_lab';
                } elseif (count($aClassifications) > 1) {
                    //If there are more than one center for the variant, the classifications
                    //are compared to decide the status.
                    $aClassificationsFlip = array_flip($aClassifications);
                    if (count(array_unique($aClassificationsFlip)) == 1) {
                        foreach ($aClassifications as $sCenter => $sClassification) {
                            $this->data[$sVariant][$sCenter]['status'] = 'consensus';
                        }
                    } else {
                         if ((isset($aClassificationsFlip['B']) || isset($aClassificationsFlip['LB']))
                                && (isset($aClassificationsFlip['P']) || isset($aClassificationsFlip['LP']))) {
                             $sClassifications = '';
                             foreach ($aClassifications as $sCenters => $aClassification) {
                                 if ($sClassifications == '') {
                                     $sClassifications .= $sCenters . ": " . $aClassification;
                                 } else {
                                     $sClassifications .= ", " . $sCenters . ": " . $aClassification;
                                 }
                             }
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                //This is where the created line will be written into the output file.
                                $this->data[$sVariant][$sCenter]['status'] = 'external_opposite';
                                //This is where the data will be written into another output file if a conflict occured.
                                $this->data_rejected[$sVariant][$sCenter]['type'] = $this->data[$sVariant][$sCenter]['type'];
                                $this->data_rejected[$sVariant][$sCenter]['genomic_native_reported'] = $this->data[$sVariant][$sCenter]['genomic_native_reported'];
                                $this->data_rejected[$sVariant][$sCenter]['classifications'] = $sClassifications;
                                $this->data_rejected[$sVariant][$sCenter]['status'] = 'external_opposite';
                            }
                        } elseif (isset($aClassificationsFlip['VUS'])) {
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                $this->data[$sVariant][$sCenter]['status'] = 'non_consensus';
                            }
                        }else {
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                $this->data[$sVariant][$sCenter]['status'] = 'consensus';
                            }
                        }
                    }
                }
            }
        }
        return true;
    }

    public function saveFile($sOutputFile): bool
    {
        //Save the data to disk.
        $aData = [implode("\t", $this->data_output_header)];
        foreach ($this->data as $sVariant => $aVariantObservations) {
            foreach ($aVariantObservations as $sCenter => $aVariantObservation) {
                //This is where the columns 'genomic_native_normalized' and 'center' are
                //added to the final file.
                $aVariantObservation['genomic_native_normalized'] = $sVariant;
                $aVariantObservation['center'] = $sCenter;
                //Creating an array which contains all information of the variant.
                $aLine = [];
                foreach ($this->data_output_header as $sField) {
                    $Value = ($aVariantObservation[$sField] ?? '');
                    if ($sField == 'annotation' && $Value) {
                        $Value = json_encode($Value);
                    }
                    $aLine[] = $Value;
                }
                //Imploding the array, which results in all values going in the correct column.
                $aData[] = implode("\t", $aLine);
            }
        }
        $aData[] = '';

        //This is where the filled data file is returned to 'run_pipeline.php' where it is saved as a file.
        return (bool) File_put_contents(
                $sOutputFile,
                implode("\r\n", $aData)
        );
    }

    public function hasConflicts(): bool
    {
        //This functions checks if there are conflicts found in the data.
        return (bool) count($this->data_rejected);
    }

    public function saveConflicts(string $sConflictOutputFile): bool
    {
        // Save conflicts to disk.
        $aData = [implode("\t", $this->data_rejected_output_header)];
        foreach ($this->data_rejected as $sVariant => $aVariantObservations) {
            foreach ($aVariantObservations as $sCenter => $aVariantObservation) {
                //This is where the columns 'genomic_native_normalized' and 'center' are
                //added to the final file.
                $aVariantObservation['genomic_native_normalized'] = $sVariant;
                $aVariantObservation['center'] = $sCenter;
                //Creating an array which contains all information of the variant.
                $aLine = [];
                foreach ($this->data_rejected_output_header as $sField) {
                    $Value = ($aVariantObservation[$sField] ?? '');
                    $aLine[] = $Value;
                }
                //Imploding the array, which results in all values going in the correct column.
                $aData[] = implode("\t", $aLine);
            }
        }
        $aData[] = '';
        //This is where the filled data file is returned to 'run_pipeline.php' where it is saved as a file.
        return (bool) File_put_contents(
                $sConflictOutputFile,
                implode("\r\n", $aData)
        );
    }

}
?>
