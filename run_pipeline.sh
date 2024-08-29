#!/bin/bash

# Created  : 2023-10-10
# Modified : 2024-08-28

# Because the crontab got too complex, better make this a script.

if [ "${USER}" == "" ];
then
    # Running through cron. Export user, used by several scripts.
    export USER=$(whoami);
fi;

# Enable the keys, so we can safely SSH everywhere.
eval $(keychain --eval --agents ssh id_rsa 2> /dev/null);

PWD="$(dirname $0)";
DATE="$(${PWD}/get_run_date.sh)";
DIR="${PWD}/${DATE}";
LOG="${DIR}/status.log";

if [ ! -d "${DIR}" ];
then
    mkdir "${DIR}";
fi;
if [ ! -f "${LOG}" ];
then
    touch "${LOG}";
fi;

# If the log indicates we were done before, then we don't have to do anything.
if [ "$(grep " OK All done." "${LOG}" | wc -l)" -gt "0" ];
then
    # We're done already. If they really want to re-run, we should either introduce a -f, or the log should be emptied.
    exit 0;
fi;

# Open the log.
echo "" >> "${LOG}";
echo "$(date '+%Y-%m-%d %H:%M:%S')    Checking current status..." >> "${LOG}";
tail -n 1 "${LOG}";
LOGCOUNT=$(cat "${LOG}" | wc -l);

cd "${DIR}";





# Check if we still need to fetch the data from the server.
if [ "$(grep " OK All files are ready" "${LOG}" | wc -l)" -eq "0" ];
then
    # Attempt to fetch data from the server.
    # Provide progress feedback, but don't send it to the log, because this script adds to the log.
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Checking for remote files...";
    ../fetch_data_from_kg-web01.sh;
    if [ $? -ne 0 ];
    then
        # This failed. Get the error from the log, and return.
        cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
        exit 1;
    else
        tail -n 1 "${LOG}";
    fi;
fi;





# Check if we have a file already. Grab the last one.
FILE=$(ls -1 ${DIR}/vkgl_consensus_20??-??-??.tsv 2> /dev/null | tail -n 1);
OUTFILE="formatting.log";

# If we have no file, create it. Also run when we don't have a formatting.log file yet, as we need it for reporting.
if [ ! -f "${FILE}" ] || [ ! -f "${OUTFILE}" ];
then
    # Format the files.
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Formatting and grouping the files..." >> "${LOG}";
    tail -n 1 "${LOG}";
    # Also pipe STDERR to the log file so we can catch what went wrong.
    ../format_raw_VKGL_files.php *.txt -y > "${OUTFILE}" 2>&1;
    if [ $? -ne 0 ];
    then
        # This failed.
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Failed formatting and grouping the files. Check ${OUTFILE} for more information." >> "${LOG}";
        tail -n 1 "${LOG}";
        exit 1;
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully formatted and grouped the files." >> "${LOG}";
        tail -n 1 "${LOG}";
        FILE="${DIR}/vkgl_consensus_$(date '+%Y-%m-%d').tsv";
    fi;
fi;





# Next, sync the caches. Just always do that, because we can't tell if it's needed or not.
echo "$(date '+%Y-%m-%d %H:%M:%S')    Syncing the caches..." >> "${LOG}";
tail -n 1 "${LOG}";
LOGCOUNT=$(cat "${LOG}" | wc -l);

OUTPUT=$(/www/git/caches/sync_caches.sh 2> /dev/null);
if [ $? -ne 0 ];
then
    # This failed.
    echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Failed syncing the caches." >> "${LOG}";
    cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
    exit 1;
else
    echo "${OUTPUT}" | sed "s/^/$(date '+%Y-%m-%d %H:%M:%S')    /" >> "${LOG}";
    echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully synced the caches." >> "${LOG}";
    tail -n 1 "${LOG}";
fi;

# Store whether or not we should sync the caches again.
SYNCAGAIN=0;





# If we have not run the debugging yet to build up the caches, do so now.
OUTFILE="output.01.debugging-to-build-caches.log";
if [ ! -f "${OUTFILE}" ];
then
    # Format the files.
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Starting the first run, debug flags on, to build the cache..." >> "${LOG}";
    tail -n 1 "${LOG}";
    # Also pipe STDERR to the log file so we can catch what went wrong.
    ../process_VKGL_data.php "${FILE}" -ny > "${OUTFILE}" 2>&1;
    # The process above always throws warnings and, therefore, never returns 0.
    if [ $? -ne 64 ];
    then
        # This failed.
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Failed completing the run. Check ${OUTFILE} for more information." >> "${LOG}";
        tail -n 1 "${LOG}";
        exit 1;
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully completed the run." >> "${LOG}";
        tail -n 1 "${LOG}";
        SYNCAGAIN=1;
    fi;
fi;





# If we have not run the cache verification yet, do so now.
# This will add VV output to the variant mappings and correct Mutalyzer mappings.
OUTFILE="output.02.verify-cache.log";
if [ ! -f "${OUTFILE}" ];
then
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Verifying the cache..." >> "${LOG}";
    tail -n 1 "${LOG}";
    # Also pipe STDERR to the log file so we can catch what went wrong.
    ../verify_cache.php > "${OUTFILE}" 2>&1;
    if [ $? -ne 0 ];
    then
        # This failed.
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Failed verifying the cache. Check ${OUTFILE} for more information." >> "${LOG}";
        tail -n 1 "${LOG}";
        exit 1;
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully verified the cache." >> "${LOG}";
        tail -n 1 "${LOG}";
        # Do check if we ran into issues that were skipped because we ran non-interactively.
        if [ "$(grep "Running non-interactively, skipping question." "${OUTFILE}" | wc -l)" -gt "0" ];
        then
            echo "$(date '+%Y-%m-%d %H:%M:%S') !! Manually re-run the cache verification; questions were skipped." >> "${LOG}";
            tail -n 1 "${LOG}";
        fi;
        SYNCAGAIN=1;
    fi;
fi;





# Then, sync the caches again, but only if we were requested to do so.
if [ "${SYNCAGAIN}" -gt "0" ];
then
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Syncing the caches..." >> "${LOG}";
    tail -n 1 "${LOG}";
    LOGCOUNT=$(cat "${LOG}" | wc -l);

    OUTPUT=$(/www/git/caches/sync_caches.sh 2> /dev/null);
    if [ $? -ne 0 ];
    then
        # This failed.
        echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Failed syncing the caches." >> "${LOG}";
        cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
        exit 1;
    else
        echo "${OUTPUT}" | sed "s/^/$(date '+%Y-%m-%d %H:%M:%S')    /" >> "${LOG}";
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully synced the caches." >> "${LOG}";
        tail -n 1 "${LOG}";
    fi;
fi;
