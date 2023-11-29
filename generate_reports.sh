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



# Summary:
echo "Summary of errors:";
cut -f 7 vkgl_errors_${DATE}.log | grep -v '^$' | tr ',' '\n' | sort | uniq -c | sort -g



# Conflicts:
grep "{" "${LAST_FILE}" | grep ConflictHeader | tr '|' '\t' | cut -f 2- \
  | sed 's/}$//' > vkgl_opposites_${DATE}.log
grep "{" "${LAST_FILE}" | grep Conflict | head -n -1 | sort -g | tr '|' '\t' \
  | cut -f 2- | sed 's/}$//' >> vkgl_opposites_${DATE}.log
echo "Created vkgl_opposites_${DATE}.log.";

