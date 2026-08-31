<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-04-28
 * Modified    : 2026-08-31
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
        $o->determineOverallVariantStatus();
        $o->sortData();
        $o->sortDataRejected();
        return $o;
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
        if ($sReportedAs) {
            $aVariant['annotation']['reported_as'] = $sReportedAs;
            ksort($aVariant['annotation']); // For comparison reasons.
        }
        // Remove the fields from the data to save memory.
        unset($aVariant['gene'], $aVariant['transcript'], $aVariant['cDNA'], $aVariant['protein']);
    }





    public function determineOverallVariantStatus (): bool
    {
        // For each unique variant, compare the observations in the different centers and determine the status of the
        //  variant in the VKGL release; single lab, consensus, non-consensus, or opposite.

        foreach ($this->data as $sVariant => $aCenters) {
            if (count($aCenters) == 1) {
                // If there is one center for a variant, we are looking at the classification to decide the status.
                $sCenter = array_key_first($aCenters);
                if ($aCenters[$sCenter]['classification'] == 'conflicting') {
                    $this->data[$sVariant][$sCenter]['status'] = 'internal-opposite';
                } else {
                    $this->data[$sVariant][$sCenter]['status'] = 'single-lab';
                }

            } else {
                $aClassifications = [];
                // There are multiple centers for this variant; check if one or more of the centers have 'conflicting'
                //  as the classification. If so, exclude them from this comparison.
                foreach ($aCenters as $sCenter => $aVariantObservation) {
                    if ($aVariantObservation['classification'] == 'conflicting') {
                        $this->data[$sVariant][$sCenter]['status'] = 'internal-opposite';
                    } else {
                        // Gather all remaining classifications, so we can compare them.
                        $aClassifications[$sCenter] = $aVariantObservation['classification'];
                    }
                }

                // Do we still have multiple centers for this variant?
                if (count($aClassifications) == 1) {
                    $this->data[$sVariant][array_key_first($aClassifications)]['status'] = 'single-lab';

                } elseif (count($aClassifications) > 1) {
                    // We still have more than one center left. Determine the status of the variant; consensus,
                    //  non-consensus, or opposite?
                    if (count(array_unique($aClassifications)) == 1) {
                        // There is only one unique classification between the centers.
                        $sStatus = 'consensus';

                    } else {
                        // We have multiple classifications for this variant. Although mergeClassifications() was built
                        //  for merging the classifications within one center, we can re-use its logic, so we don't have
                        //  to repeat that here.
                        $sStatus = match ($this->mergeClassifications($aClassifications)) {
                            'conflicting' => 'external-opposite',
                            'VUS' => 'non-consensus',
                            default => 'consensus',
                        };

                        if ($sStatus == 'external-opposite') {
                            // Log this opposite for the labs, so they can fix it. Build a string with the
                            //  classifications for each center, so the labs can see what's going on.
                            $sClassifications = implode(', ',
                                array_map(function ($sCenter, $sClassification)
                                {
                                    return $sCenter . ': ' . $sClassification;
                                }, array_keys($aClassifications), $aClassifications)
                            );
                            // Now log this opposite, once for each center so they can easily search the error file.
                            // NOTE: Classifications shown here are pre-grouped.
                            //       E.g., any P/LP combo has already been reduced to LP. That may cause confusion.
                            foreach ($aClassifications as $sCenter => $sClassification) {
                                $this->data_rejected[$sCenter][] = array_merge(
                                    $aCenters[$sCenter],
                                    [
                                        'error' => "External conflict (opposite), classifications: $sClassifications.",
                                    ]
                                );
                            }
                        }
                    }

                    // Store the same status for all centers that have classifications.
                    foreach ($aClassifications as $sCenter => $sClassification) {
                        $this->data[$sVariant][$sCenter]['status'] = $sStatus;
                    }
                }
            }
        }

        return true;
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
                    $this->createReportedAs($aObservations[0]);
                    // Note that this leaves the annotation field unpacked.
                    $this->data[$sVariant][$sCenter] = $aObservations[0];

                } else {
                    // We have more than one observation of the same variant. Check or combine the columns.
                    foreach ($aObservations as $i => $aObservation) {
                        // First, create the "reported_as" field and add that to the "annotation" column.
                        $aObservation['annotation'] = json_decode($aObservation['annotation'], true);
                        // Note that this method also removes redundant fields from $aObservation.
                        $this->createReportedAs($aObservation);
                        // Also add the classification to the annotation, so we can always find back the original
                        //  classifications of all observations (we may update the classification after this).
                        $aObservation['annotation']['classifications'] = $aObservation['classification'];
                        ksort($aObservation['annotation']); // For comparison reasons.
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
                        // The values of the column 'classification' will be handled separately.
                        // The column 'annotation' will be merged recursively.
                        // For each of the remaining columns, the values are combined into a single string.
                        if ($sColumn == 'type' || $sColumn == 'genomic_liftover_normalized') {
                            // Disallow having more than one unique value.
                            // NOTE: This prevents CNVs and SNVs from getting merged, which would be bad.
                            //       If that ever happens, we may need to handle that and aggregate separately?
                            throw new \Exception("Variant merging conflict for $sVariant in $sCenter, field $sColumn contains non-unique values " . implode(', ', $aValues));

                        } elseif ($sColumn == 'classification') {
                            // Compare all classifications and try to come up with a consensus value.
                            $aMergedVariant[$sColumn] = $this->mergeClassifications($aValues);

                        } elseif ($sColumn == 'annotation') {
                            // Merge the annotation arrays recursively.
                            $aMergedVariant[$sColumn] = $this->mergeAnnotations($aValues);

                        } else {
                            // For all other columns, simply combine the values into a single string.
                            $aMergedVariant[$sColumn] = implode(', ', $aValues);
                        }
                    }

                    // Don't pollute the annotations field with unneeded info, though.
                    if (!empty($aMergedVariant['annotation']['classifications'])
                        && is_string($aMergedVariant['annotation']['classifications'])) {
                        unset($aMergedVariant['annotation']['classifications']);
                    }

                    // Save the merged entry, regardless of whether a conflict occurred.
                    $this->data[$sVariant][$sCenter] = $aMergedVariant;

                    // If comparing the classifications resulted in an internal conflict,
                    //  store the data in a report file that will be sent to the labs.
                    if ($aMergedVariant['classification'] == 'conflicting') {
                        $this->data_rejected[$sCenter][] = array_merge(
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
        return (bool) count($this->data_rejected);
    }





    public function mergeAnnotations ($aAnnotations): array
    {
        // Merge the given annotations into one larger array.
        // We only use this method if we have more than one annotation array.

        // Merge $aAnnotations recursively and then go and check the results for each field.
        $aMerged = array_merge_recursive(...$aAnnotations);
        ksort($aMerged); // For comparison reasons.
        // Check each field in the annotations; if it is an array, only the unique values are used.
        // Avoid using arrays when possible.
        return array_map(function ($Value)
        {
            if (is_array($Value)) {
                $aValues = array_unique(array_filter($Value));
                if (count($aValues) == 1) {
                    return current($aValues);
                } else {
                    sort($aValues); // For comparison reasons.
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
        foreach ($this->data as $aVariants) {
            foreach ($aVariants as $aVariant) {
                $aLine = [];
                foreach ($this->data_output_header as $sField) {
                    $Value = ($aVariant[$sField] ?? '');
                    if ($sField == 'annotation' && $Value) {
                        $Value = json_encode($Value, JSON_UNESCAPED_UNICODE);
                    }
                    $aLine[] = $Value;
                }
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
        foreach ($this->data_rejected as $aVariants) {
            foreach ($aVariants as $aVariant) {
                $aLine = [];
                foreach ($this->data_rejected_output_header as $sField) {
                    $aLine[] = ($aVariant[$sField] ?? '');
                }
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





    public function sortData (): void
    {
        // Sort the data; this has no functional effect other than that it helps compare files between releases.

        // The data was stored per variant and then per center, but we want this sorted per center.
        $aData = [];
        foreach ($this->data as $aCenters) {
            foreach ($aCenters as $sCenter => $aVariant) {
                $aData[$sCenter][] = $aVariant;
            }
        }
        $this->data = $aData;

        // Start by sorting by center.
        ksort($this->data);

        // Then loop per center and sort those variants on the variant type and genomic_native_normalized fields.
        foreach ($this->data as $sCenter => $aVariants) {
            usort($aVariants, function ($a, $b) {
                // First, sort on variant type. If we had stored the data per type, this would have been much easier.
                // Only then, sort by the DNA field.
                $n = strcmp($a['type'], $b['type']);
                if ($n) {
                    // Types are not the same.
                    return $n;
                }
                // Types are the same. Sort on DNA.
                return strcmp($a['genomic_native_normalized'], $b['genomic_native_normalized']);
            });
            $this->data[$sCenter] = $aVariants;
        }
    }





    public function sortDataRejected (): void
    {
        // Sort the rejected data; this has no functional effect other than that it helps compare files between releases.

        // Start by sorting by center.
        ksort($this->data_rejected);

        // Then loop per center and sort those variants on the variant type and genomic_native_normalized fields.
        foreach ($this->data_rejected as $sCenter => $aVariants) {
            usort($aVariants, function ($a, $b) {
                // First, sort on variant type. If we had stored the data per type, this would have been much easier.
                // Only then, sort by the DNA field.
                $n = strcmp($a['type'], $b['type']);
                if ($n) {
                    // Types are not the same.
                    return $n;
                }
                // Types are the same. Sort on DNA.
                return strcmp($a['genomic_native_normalized'], $b['genomic_native_normalized']);
            });
            $this->data_rejected[$sCenter] = $aVariants;
        }
    }
}
?>
