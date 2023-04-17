#!/bin/bash

# Internal conflicts:
cat stats.txt | grep -A 1000 \
  $(grep vkgl_consensus_ stats.txt | tail -n 1 | cut -d '[' -f 2 | cut -d ']' -f 1) \
  | grep internal \
  | sed -r 's/^\s+//' > vkgl_internal_conflicts_$(date +%Y-%m).txt
echo "Created vkgl_internal_conflicts_$(date +%Y-%m).txt.";



# Errors:
LAST_FILE=$(ls -1t output_202*full-run* | head -n 1);
LAST_MONTH=$(echo $LAST_FILE | cut -d _ -f 2 | cut -d . -f 1);

grep "{" $LAST_FILE | grep Error | tr '|' '\t' | cut -f 2- | sed 's/}$//' | sort -g > vkgl_errors_${LAST_MONTH}.txt

for CHR in $(cat chromosomes.txt);
do
  grep "^${CHR}\s" vkgl_errors_${LAST_MONTH}.txt | sort -k 2 -g;
done > vkgl_errors_${LAST_MONTH}.sorted.txt

mv vkgl_errors_${LAST_MONTH}.sorted.txt vkgl_errors_${LAST_MONTH}.txt
echo "Created vkgl_errors_${LAST_MONTH}.txt.";



# Summary:
echo "Summary of errors:";
cut -f 7 vkgl_errors_${LAST_MONTH}.txt | grep -v '^$' | tr ',' '\n' | sort | uniq -c | sort -g



# Conflicts:
grep "{" $LAST_FILE | grep ConflictHeader | tr '|' '\t' | cut -f 2- \
  | sed 's/}$//' > vkgl_opposites_${LAST_MONTH}.txt
grep "{" $LAST_FILE | grep Conflict | head -n -1 | sort -g | tr '|' '\t' \
  | cut -f 2- | sed 's/}$//' >> vkgl_opposites_${LAST_MONTH}.txt
echo "Created vkgl_opposites_${LAST_MONTH}.txt.";

