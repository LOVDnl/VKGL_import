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
    private array $data_output_header = [
            'center',
            'type',
            'genomic_native_normalized',
            'genomic_native_reported',
            'genomic_liftover_normalized',
            'genomic_liftover_reported',
            'classification',
            'gene',
            'transcript',
            'cDNA',
            'protein',
            'annotation',
            'status'
    ];

    public static function aggregate (string $sFile): Aggregator
    {
        $o = new Aggregator();
        $o->parse($sFile);
        $o->groupByCenter();
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
            // Trim quotes off of the data
            $aDataLine = array_map(function($sData) {
                return trim($sData,'"');
            }, $aDataLine);
            $nDataColumns = count($aDataLine);
            if ($nHeaders > $nDataColumns) {
                $aDataLine = array_pad($aDataLine, $nHeaders, '');
            }
           $aVariantObservations = array_combine($aHeaders, $aDataLine);
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
        foreach ($this->data as $sVariant => $aData) {
            foreach ($aData as $sCenter => $aObservations) {
                if (count($aObservations) == 1) {
                    $aObservations[0]['annotation'] = json_decode($aObservations[0]['annotation'], true);
                    Aggregator::createReportedAs($aObservations[0]);
                    $this->data[$sVariant][$sCenter] = $aObservations[0];
                } else {
                    foreach ($aObservations as $i => $aVariantObservation) {
                       $aVariantObservation['annotation'] = json_decode($aVariantObservation['annotation'], true);
                       Aggregator::createReportedAs($aVariantObservation);
                       $aVariantObservation['annotation']['classification'] = $aVariantObservation['classification'];
                       $aVariantObservations[$i] = $aVariantObservation;
                    }
                    $aMergedVariant = [];
                    foreach (array_keys($aVariantObservations[0]) as $sColumn) {
                        $aValues = [];
                        foreach ($aVariantObservations as $aVariantObservation) {
                            $aValues[] = $aVariantObservation[$sColumn];
                        }
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
                    $this->data[$sVariant][$sCenter] = $aMergedVariant;
                }
            }
        }
        return true;
    }

    public function createReportedAs(array &$aVariantObservation)
    {
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
        $aCreateUniqueValues = array_filter($aCreateUniqueValues);
        if (count(array_unique($aCreateUniqueValues)) == 1) {
            $sUniqueValues = $aCreateUniqueValues[0];
        } else {
            $sUniqueValues = implode(", ", array_unique($aCreateUniqueValues));
        }
        return $sUniqueValues;
    }

}
?>
