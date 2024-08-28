#!/bin/bash

# This script determines which is the latest run date, and returns the string.
# It's sort of a library that's usable for bash and PHP scripts alike.

YEAR=$(date +%Y);
MONTH=$(date +%m);

# We have runs in January, April, July, and October.
if [ $MONTH -ge 10 ];
then
    MONTH="10";
elif [ $MONTH -ge 7 ];
then
    MONTH="07";
elif [ $MONTH -ge 4 ];
then
    MONTH="04";
else
    MONTH="01";
fi;

echo "${YEAR}-${MONTH}";
