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
