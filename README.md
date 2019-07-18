# VKGL import

Every three months, the Dutch genome diagnostic laboratories release their new data.
This script will take their export file, normalize and map the variants,
 regroup the variants, and import everything into an LOVD3 instance.
If previous records are found, they will be updated.
Records no longer found in LOVD3 will only be marked as removed, if the user requests so.

### Invoking the script

```
process_VKGL_data.php file_to_import.tsv [-y]
```
Note that the script is interactive.
The first time that it will run, it will ask you all the information it needs to process the data.
Settings are stored in a `settings.json` file.
Only when settings have been provided before, the `-y` flag can be used.
Passing the `-y` flag will accept all previous settings.

### Output

The script will write output to the terminal, informing you of its progress.
Note that the script will only write a new line in case of an error, or if the progress has increased by at least 0.1%.
Running large files with 100,000 variants or more may cause the script to create no new output for a few minutes.

### Caches

This script caches data from Mutalyzer in an `NC_cache.txt` file and an `mapping_cache.txt` file.

##### NC cache

This file contains variant descriptions on the genome (NC reference sequences) and their normalized counterparts.
The file does not need to be sorted.
An example line looks like:

```
NC_000001.10:g.100387136_100387137insA  NC_000001.10:g.100387137dup
```

Note that both values may be the same, in the case the variant can not be normalized.
This script will build this cache if you do not have it, but since building the cache may take a long time,
 it is recommended to use the NC cache from the [caches](https://github.com/LOVDnl/caches) project.

The script will store errors like so:

```
NC_000001.10:g.150771703C>T     {"EREF":"C not found at position 150771703, found T instead."}
```

##### Mapping cache

The mapping cache contains mapping data from two Mutalyzer webservices, both the `runMutalyzerLight` and
 the `numberConversion` methods.
Because both methods provide partially overlapping data, the results are stored together.
The cache stores the method(s) used; if the runMutalyzerLight webservice didn't provide enough transcripts, the numberConversion service can be used and the additional data is added to the cache in a new line.

The file does not need to be sorted, but sorting may help in finding duplicate variants.
An example line looks like:

```
NC_000001.10:g.100154502A>G     {"NM_017734.4":{"c":"c.686A>G","p":"p.(Asn229Ser)"},"methods":["runMutalyzerLight"]}
NC_000001.10:g.13413980G>A      {"NM_001291381.1":{"c":"c.923G>A","p":"p.?"},"methods":["runMutalyzerLight","numberConversion"]}
NC_000001.10:g.13634793G>T      {"methods":["runMutalyzerLight","numberConversion"]}
```

The third line in this example shows a variant where no mapping data could be found, using either Mutalyzer method.
