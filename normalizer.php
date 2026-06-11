#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-03-10
 * Modified    : 2026-06-11
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\VKGL;

require_once(__DIR__ . '/libs/HGVS-syntax-checker/HGVS.php');
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
    private $Log; // Holding the Log object so we can log our progress.

    public static function normalize (string $sFile, Log $Log = null): Normalizer
    {
        // Parse the given file and normalize the data.
        $o = new Normalizer();
        if ($Log) {
            $o->Log = $Log; // So we can log our progress, not leaving the user in the dark.
        }
        $o->parse($sFile);
        $o->normalizeData();
        return $o;
    }





    public function normalizeData (): bool
    {
        // Normalize all the stored data, converting non-HGVS to HGVS, normalizing everything,
        //  and collecting liftover and mapping information for all variants.

        $nVariants = array_sum(array_map('count', $this->data));
        $nLineLength = 80; // The width of the screen before we wrap to a new line.
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
                if (str_starts_with($aVariant['genomic_native'], 'NC_')) {
                    // This is HGVS already.
                    $HGVS = HGVS::checkVariant($aVariant['genomic_native']);
                    $sBuild = false; // Caches will figure this one out.
                } else {
                    // We received VCF or VCF-like descriptions (CNVs).
                    if ($aVariant['type'] == 'SNV') {
                        // These are real VCFs and need to be processed as such.
                        $HGVS = HGVS_VCF::check($aVariant['genomic_native']);
                        $sBuild = ($HGVS->Genome->getCorrectedValue() ?? false);
                    } else {
                        // CNVs aren't described as real VCFs, so need special handling.
                        $HGVS = HGVS::check($aVariant['genomic_native']);
                        $sBuild = ($HGVS->ReferenceSequence->Genome->getCorrectedValue() ?? false);
                        continue;
                    }
                }
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
}
?>
