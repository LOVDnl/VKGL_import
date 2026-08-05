<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-03-10
 * Modified    : 2026-08-05
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once(__DIR__ . '/libs/HGVS-syntax-checker/caches.php');
require_once(__DIR__ . '/libs/HGVS-syntax-checker/HGVS.php');
use LOVD\HGVS\Caches;
use LOVD\HGVS\HGVS;
use LOVD\HGVS\HGVS_VCF;
use LOVD\Log;

class Normalizer
{
    // Class abstracting the normalization of all the variants from the data file.
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
        'genomic_native_reported',
    ];
    private array $settings = [];
    private array $statistics = [];
    private $Log; // Holding the Log object so we can log our progress.

    public static function normalize (string $sFile, Log $Log = null, array $aSettings = []): Normalizer
    {
        // Parse the given file and normalize the data.
        $o = new Normalizer();
        if ($Log) {
            $o->Log = $Log; // So we can log our progress, not leaving the user in the dark.
        }
        if ($aSettings) {
            $o->settings = $aSettings; // For the VV settings and to collect transcripts from the remote LOVDs.
        }
        $o->parse($sFile);
        Caches::setVVOptions($aSettings['VV'] ?? []); // Pass on the VV settings that we got from the pipeline.
        $o->normalizeData();
        $o->sortData();
        return $o;
    }





    public function getStatistics (): array
    {
        return $this->statistics;
    }





    public function hasErrors (): bool
    {
        return (bool) count($this->data_rejected);
    }





    public function normalizeData (): bool
    {
        // Normalize all the stored data, converting non-HGVS to HGVS, normalizing everything,
        //  and collecting liftover and mapping information for all variants.

        $nVariants = array_sum(array_map('count', $this->data));
        $nLineLength = 100; // The width of the screen before we wrap to a new line.
        $nCharactersPrinted = 0; // To count when we should wrap.
        $iVariant = 0;
        foreach ($this->data as $sCenter => $aVariants) {
            foreach ($aVariants as $i => $aVariant) {
                $iVariant++;

                // Keep the reported as for reporting and to allow users to connect the results with their own data.
                $aVariant['genomic_native_reported'] = $aVariant['genomic_native'];
                $aVariant['genomic_liftover_reported'] = $aVariant['genomic_liftover'];

                // First off, normalize the input. Also try to predict the build,
                //  so the Caches class doesn't need to figure it out again.
                $sBuild = false; // By default, we'll let Caches figure this one out.
                if (str_starts_with($aVariant['genomic_native'], 'NC_')) {
                    // This is HGVS already.
                    $HGVS = HGVS::checkVariant($aVariant['genomic_native']);
                } else {
                    // We received VCF or VCF-like descriptions (CNVs).
                    if ($aVariant['type'] == 'SNV') {
                        // These are real VCFs and need to be processed as such.
                        // E.g., "GRCh37:1:211832061:C:CAAAAAAAAAAAAAAAAAAA".
                        $HGVS = HGVS_VCF::check($aVariant['genomic_native']);
                        $sBuild = ($HGVS->Genome->getCorrectedValue() ?? false);
                    } else {
                        // CNVs aren't described as real VCFs, so need special handling.
                        $HGVS = HGVS::check($aVariant['genomic_native']);
                        if ($HGVS->ReferenceSequence->hasProperty('Genome')) {
                            // CNV data formatted as, e.g., "GRCh37:1:1_1068640DEL".
                            $sBuild = ($HGVS->ReferenceSequence->Genome->getCorrectedValue() ?? false);
                        } elseif ($HGVS->ReferenceSequence->hasProperty('Chromosome') && $HGVS->ReferenceSequence->Chromosome->hasProperty('Genome')) {
                            // CNV data formatted as, e.g., "chr16(HG38):g.2447302_2494972dup".
                            $sBuild = ($HGVS->ReferenceSequence->Chromosome->Genome->getCorrectedValue() ?? false);
                        }
                    }
                }

                // Check if the syntax is OK; if not, we can try to fix some issues.
                if (!$HGVS->isValid()) {
                    // Hmm... the variant is invalid. Only handle situations where we know what to do.
                    // Especially CNVs can have some known issues; check these and ignore what we don't care about.
                    $aMessages = array_diff_key(
                        $HGVS->getMessages(),
                        array_flip(
                            [
                                'WNOTSUPPORTED',  // Anything not supported by VV.
                                'WREFSEQMISSING', // From "GRCh37:1:1_1068640DEL".
                                'WPREFIXMISSING', // From "GRCh37:1:1_1068640DEL".
                                'WWRONGCASE',     // From "GRCh37:1:1_1068640DEL".
                                'WWRONGPREFIX',   // An chrM:g variant that got corrected to chrM:m, all good!
                            ]
                        )
                    );

                    if (array_keys($aMessages) == ['WVCFDOTREF']) {
                        // An empty REF is bad. But there is a fix.
                        // We verified that the most illogical fix is the correct one (Alissa has this issue).
                        $HGVS->setCorrectedValue($HGVS->getCorrectedValue(1));

                    } elseif ($aMessages) {
                        // Currently, we get EPOSITIONLIMIT here or WSAMEPOSITIONS. Both are from CNVs only.
                        // Report the variant instead of processing it any further. Let's collect some information.
                        $aVariant['error'] = implode(
                            ' ',
                            array_map(
                                function ($sCode, $sMessage)
                                {
                                    return "$sCode: $sMessage";
                                }, array_keys($aMessages), array_values($aMessages)
                            )
                        );
                        if ($aVariant['annotation']) {
                            $aVariant['annotation'] = json_decode($aVariant['annotation'], true);
                            if (!empty($aVariant['annotation']['source'])) {
                                // Simply append the source to the reported description.
                                $aVariant['genomic_native_reported'] .= ' (source: ' . $aVariant['annotation']['source'] . ')';
                            }
                        }
                        $this->data_rejected[$sCenter][] = $aVariant;
                        $this->statistics['errors'][$sCenter] = ($this->statistics['errors'][$sCenter] ?? 0) + 1;
                        unset($this->data[$sCenter][$i]); // Don't export this data.
                        continue;
                    }
                }

                // This still hasn't been validated on the sequence level.
                $sVariant = $HGVS->getCorrectedValue();
                if ($aVariant['type'] == 'CNV') {
                    $aVariant['genomic_native_normalized'] = $sVariant;
                    $this->data[$sCenter][$i] = $aVariant;
                    continue;
                }

                // This checks if we have a VV corrected variant, and if we have some mapping/liftover info.
                // If not, it calls VV and stores the VV corrected variant and mapping/liftover info.
                $b = Caches::buildCaches($sVariant, $sBuild);
                // null  : Internal failure with the cache files.
                // true  : Success.
                // 1     : Addition was not needed, variant already known (includes previously known errors, e.g., EREF).
                // 0     : Internal failure with VV. Failure may be non-permanent.
                // false : Internal failure when adding data to the cache. Failure is likely permanent.
                if ($b === null) {
                    throw new \Exception('Internal failure with the cache files');
                } elseif ($b === true) {
                    // Successfully added the variant.
                    echo '+';
                    $nCharactersPrinted ++;
                } elseif ($b === 1) {
                    // Nothing to do. But we don't want to leave the user blind, either, when we don't have many new variants.
                    if (($iVariant % 1000) === 0) {
                        echo '.';
                        $nCharactersPrinted ++;
                    }
                } elseif ($b === 0) {
                    // This used to happen quite often, but not on the new VV API.
                    // For now, die, but if this starts happening randomly, then make sure we simply try again.
                    throw new \Exception("Failed to process the mapping data for {$aVariant['genomic_native']} ($sVariant), perhaps there is a data irregularity");
                } else {
                    throw new \Exception("Failed to store {$aVariant['genomic_native']} ($sVariant) in the cache");
                }

                if ($nCharactersPrinted >= $nLineLength || $iVariant == $nVariants) {
                    $nPerc = round(($iVariant/$nVariants)*100);
                    $s = "Processed $iVariant/$nVariants variants... ({$nPerc}%)";
                    if ($this->Log) {
                        // Log this, which means that it will end up on the screen as well.
                        echo "\n";
                        $this->Log->add($s);
                    } else {
                        echo "\n";
                    }
                    $nCharactersPrinted = 0;
                }

                // If the variant is in error, reject it.
                if (Caches::hasErrors($sVariant)) {
                    // These are things like EREFs.
                    // Report the variant instead of processing it any further. Let's collect some information.
                    $aErrors = Caches::getErrors($sVariant);
                    $aVariant['error'] = implode(
                        ' ',
                        array_map(
                            function ($sCode, $sMessage)
                            {
                                return "$sCode: $sMessage";
                            }, array_keys($aErrors), array_values($aErrors)
                        )
                    );
                    if ($aVariant['annotation']) {
                        $aVariant['annotation'] = json_decode($aVariant['annotation'], true);
                        if (!empty($aVariant['annotation']['source'])) {
                            // Simply append the source to the reported description.
                            $aVariant['genomic_native_reported'] .= ' (source: ' . $aVariant['annotation']['source'] . ')';
                        }
                    }
                    $this->data_rejected[$sCenter][] = $aVariant;
                    $this->statistics['errors'][$sCenter] = ($this->statistics['errors'][$sCenter] ?? 0) + 1;
                    unset($this->data[$sCenter][$i]); // Don't export this data.
                    continue;
                }

                // We didn't actually collect the results yet.
                $sVariant = Caches::getCorrectedNC($sVariant);
                $aLiftOver = Caches::getLiftOver($sVariant, $sBuild);
                $aMappings = Caches::getMapping($sVariant, $sBuild);
                if (!$sVariant || !$aLiftOver || !$aMappings) {
                    throw new \Exception("Failed to retrieve the data for {$aVariant['genomic_native']} ($sVariant) from the cache");
                }
                if (!$sBuild) {
                    $sBuild = array_search($sVariant, $aLiftOver);
                }
                $aVariant['genomic_native_normalized'] = $sVariant;
                unset($aLiftOver[$sBuild]);
                if ($aLiftOver) {
                    $aVariant['genomic_liftover_normalized'] = current($aLiftOver);
                }
                $this->data[$aVariant['center']][$i] = $aVariant;
            }
        }

        return true;
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
            // Store the data grouped by the center, making sure the output is sorted per center.
            $this->data[$aVariant['center']][] = $aVariant;
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

        // Start by sorting by center.
        ksort($this->data);

        // Then loop per center and sort those variants on the genomic_native_normalized field.
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
}
?>
