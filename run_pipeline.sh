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
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed formatting and grouping the files. Check ${OUTFILE} for more information." >> "${LOG}";
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
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed syncing the caches." >> "${LOG}";
    cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
    exit 1;
else
    echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
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
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed completing the run. Check ${OUTFILE} for more information." >> "${LOG}";
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
CACHETAINTED=0; # Store whether or not the cache is tainted still.
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
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed verifying the cache. Check ${OUTFILE} for more information." >> "${LOG}";
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
            # Store the cache as tainted, so we will never do a full run.
            CACHETAINTED=1;
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
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed syncing the caches." >> "${LOG}";
        cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
        exit 1;
    else
        echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully synced the caches." >> "${LOG}";
        tail -n 1 "${LOG}";
    fi;
fi;





# Check the stats. We have only done some debugging so far, so the numbers created and deleted should be 0.
TAIL=$(tail output.01.debugging-to-build-caches.log | grep -A 5 Totals);
CREATED=$(echo "${TAIL}" | grep "Variants created" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
UPDATED=$(echo "${TAIL}" | grep "Variants updated" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
DELETED=$(echo "${TAIL}" | grep "Variants deleted" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
SKIPPED=$(echo "${TAIL}" | grep "Variants skipped" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);

if [ "${CREATED}" -ne "0" ] || [ "${UPDATED}" -gt "1500" ] || [ "${DELETED}" -ne "0" ] || [ "$(($SKIPPED / $UPDATED))" -lt "175" ];
then
    # The numbers look odd.
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! The debugging run seems to have had some unexpected results." >> "${LOG}";
    echo "                       Variants were created or deleted, or the percentage of updated variants is too high." >> "${LOG}";
    echo "                       Please check the debugging run yourself and see how to proceed." >> "${LOG}";
    tail -n 3 "${LOG}";
    exit 1;
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') OK The numbers generated by the debugging run seem legit." >> "${LOG}";
    tail -n 1 "${LOG}";
fi;





# We will only continue with a full run, if the verification of the cache succeeded fully.
# Otherwise, we'll end up with incorrect information in the database.
if [ "${CACHETAINTED}" -gt "0" ];
then
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! We will stop here, as questions were skipped during the cache verification." >> "${LOG}";
    echo "                    !! Manually re-run the cache verification, and then re-run the pipeline." >> "${LOG}";
    tail -n 2 "${LOG}";
fi;





# If we have not run the full process yet, do so now.
OUTFILE="output.03.full-run-with-deletes.log";
if [ ! -f "${OUTFILE}" ];
then
    # Go ahead and fully process the data, including the database updates.
    echo "$(date '+%Y-%m-%d %H:%M:%S')    Starting the full run, with deletes..." >> "${LOG}";
    tail -n 1 "${LOG}";
    # Also pipe STDERR to the log file so we can catch what went wrong.
    ../process_VKGL_data.php "${FILE}" -y > "${OUTFILE}" 2>&1;
    # The process above always throws warnings and, therefore, never returns 0.
    if [ $? -ne 64 ];
    then
        # This failed.
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed completing the run. Check ${OUTFILE} for more information." >> "${LOG}";
        tail -n 1 "${LOG}";
        exit 1;
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully completed the run." >> "${LOG}";
        tail -n 1 "${LOG}";
        SYNCAGAIN=1;
    fi;
fi;





# Check the stats again. Now we have the full run, we should see all numbers clearly.
TAIL=$(tail "${OUTFILE}" | grep -A 5 Totals);
CREATED=$(echo "${TAIL}" | grep "Variants created" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
UPDATED=$(echo "${TAIL}" | grep "Variants updated" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
DELETED=$(echo "${TAIL}" | grep "Variants deleted" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);
SKIPPED=$(echo "${TAIL}" | grep "Variants skipped" | tr ' ' '\n' | tail -n 1 | cut -d . -f 1);

if [ "${CREATED}" -gt "10000" ] || [ "${UPDATED}" -gt "7500" ] || [ "${DELETED}" -gt "250" ] || [ "$(($SKIPPED / $UPDATED))" -lt "35" ];
then
    # The numbers look odd.
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! The full run seems to have had some unexpected results." >> "${LOG}";
    echo "                       Too many variants created, updated, or deleted, or the percentage of updated variants is too high." >> "${LOG}";
    echo "                       Please check the run yourself and see how to proceed." >> "${LOG}";
    tail -n 3 "${LOG}";
    exit 1;
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') OK The numbers generated by the debugging run seem legit." >> "${LOG}";
    tail -n 1 "${LOG}";
fi;





# Run copy_data_* scripts to copy the data to the servers.
echo "$(date '+%Y-%m-%d %H:%M:%S')    Copying the data to the remote servers..." >> "${LOG}";
tail -n 1 "${LOG}";
LOGCOUNT=$(cat "${LOG}" | wc -l);

OUTPUT=$(../copy_data_to_web01.sh 2>&1);
if [ $? -ne 0 ];
then
    # This failed.
    echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed copying the data to Web01." >> "${LOG}";
    cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
    exit 1;
else
    echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
    echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully copied the data to Web01." >> "${LOG}";

    OUTPUT=$(../copy_data_to_kg.sh 2>&1);
    if [ $? -ne 0 ];
    then
        # This failed.
        echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
        echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed copying the data to KG-Web01." >> "${LOG}";
        cat "${LOG}" | tail -n +$(($LOGCOUNT + 1));
        exit 1;
    else
        echo "${OUTPUT}" | sed "s/^/                       /" >> "${LOG}";
        echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully copied the data to both servers." >> "${LOG}";
        tail -n 1 "${LOG}";
    fi;
fi;





# Generate the final report; it doesn't take much time, so let's always generate it.
OUTFILE="output.04.final-report.log";
echo "$(date '+%Y-%m-%d %H:%M:%S')    Generating final report..." >> "${LOG}";
tail -n 1 "${LOG}";

# Also pipe STDERR to the output file so we can catch what went wrong.
../generate_reports.sh > "${OUTFILE}" 2>&1;
if [ $? -ne 0 ];
then
    # This failed.
    echo "$(date '+%Y-%m-%d %H:%M:%S') !! Failed generating the final report. Check ${OUTFILE} for more information." >> "${LOG}";
    tail -n 1 "${LOG}";
    exit 1;
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') OK Successfully generated the final report." >> "${LOG}";
    tail -n 1 "${LOG}";
fi;





# Close the log. Adding this string will prevent the pipeline from running again.
echo "$(date '+%Y-%m-%d %H:%M:%S') OK All done." >> "${LOG}";
tail -n 1 "${LOG}";
