#!/bin/bash

DATE=$(pwd | rev | cut -d / -f 1 | rev);

# Internal conflicts:
grep internal formatting.log \
  | sed -r 's/^\s+//' > "vkgl_internal_conflicts_${DATE}.log"
echo "Created vkgl_internal_conflicts_${DATE}.log.";



# Errors:
LAST_FILE="output.01.debugging-to-build-caches.log";

grep "{" "${LAST_FILE}" | grep Error | tr '|' '\t' | cut -f 2- | sed 's/}$//' | sort -g > "vkgl_errors_${DATE}.log"

for CHR in $(cat ../chromosomes.txt);
do
  grep "^${CHR}\s" vkgl_errors_${DATE}.log | sort -k 2 -g;
done > vkgl_errors_${DATE}.sorted.log

mv vkgl_errors_${DATE}.sorted.log vkgl_errors_${DATE}.log
echo "Created vkgl_errors_${DATE}.log.";



# Conflicts:
grep "{" "${LAST_FILE}" | grep ConflictHeader | tr '|' '\t' | cut -f 2- \
  | sed 's/}$//' > vkgl_opposites_${DATE}.log
grep "{" "${LAST_FILE}" | grep Conflict | head -n -1 | sort -g | tr '|' '\t' \
  | cut -f 2- | sed 's/}$//' >> vkgl_opposites_${DATE}.log
echo "Created vkgl_opposites_${DATE}.log.";



# Generate statistics for the VKGL email.
# All stats can be fetched from the last run.
DATA=$(grep -EA4 "\[(100.0%|Totals)\]" output.03.full-run-with-deletes.log)
echo "
Unique variants received : $(echo "$DATA" | grep "VKGL file successfully parsed," | sed 's/^ *//' | cut -d \  -f 8) (after filtering out internal conflicts)
Unique variants in error : $(echo "$DATA" | grep "Variants lost:" | sed 's/^ *//' | cut -d \  -f 3 | cut -d . -f 1)    -
Unique variants merged   : $(echo "$DATA" | grep "variants merged. Variants left:" | sed 's/^ *//' | cut -d \  -f 3)  -
                           ======
Unique variants left     : $(echo "$DATA" | grep "variants merged. Variants left:" | sed 's/^ *//' | cut -d \  -f 8 | cut -d . -f 1)
                           ======
$(echo "$DATA" | grep -A 3 "Single-lab" | sed 's/^        //')
=================================
Total classifications    : $(echo "$DATA" | tail | grep " Variants " | grep -v "deleted" | cut -b 20- | cut -d : -f 2- | sed 's/\.$//' | paste -sd+ | bc)
                           ======
$(echo "$DATA" | tail | grep " Variants " | cut -b 20- | sed 's/\.$//' | sed 's/Variants/Classifications/' | sed 's/:/  :/')
";



# Summaries:
echo "Summary of errors:";
cut -f 7 vkgl_errors_${DATE}.log | grep -v '^$' | tr ',' '\n' | sort | uniq -c | sort -g;

echo "Summary of internal conflicts:";
cut -d \  -f 3 vkgl_internal_conflicts_${DATE}.log | sort | uniq -c;
