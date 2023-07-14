#!/bin/bash

# Created  : 2023-07-12
# Modified : 2023-07-13

# This will check if we have the data ready on kg-web01.

DIR="$(dirname $0)/$(date +%Y-%m)";
HOST="kg-web01";
REMOTE="/home/${USER}/git/VKGL_export/$(date +%Y)/$(date +%Y-%m)";
LOG="${DIR}/status.log";

if [ ! -d "${DIR}" ];
then
    mkdir "${DIR}";
fi;

# If the log indicates we were done before, then we don't have to do anything.
if [ "$(grep " OK " "${LOG}" | wc -l)" -gt "0" ];
then
    # We're done already. If they really want to re-run, we should either introduce a -f, or the log should be emptied.
    exit 0;
fi;



echo "" >> "${LOG}";
echo "$(date '+%Y-%m-%d %H:%M:%S')    Checking for remote files..." >> "${LOG}";

FILES=$(ssh ${HOST} ls "${REMOTE}" | grep -E "^(alissa.tar|lumc.txt|radboud_mumc.txt)\.gz$");
if [ "$(echo "${FILES}" | wc -l)" -eq "0" ];
then
    # If there's nothing to do, just die here.
    echo "$(date '+%Y-%m-%d %H:%M:%S')    No remote files found (after filtering)." >> "${LOG}";
    exit 1;
fi;

# Only download what we don't have already.
for FILE in $FILES;
do
    if [ ! -f "${DIR}/${FILE}" ] && [ ! -f "$(echo "${DIR}/${FILE}" | sed 's/\.gz$//')" ];
    then
        # We don't have the file.
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Downloading ${FILE}..." >> "${LOG}";
        rsync -aq "${HOST}:${REMOTE}/${FILE}" "${DIR}" >> "${LOG}" 2>&1;
        if [ "$?" -ne "0" ];
        then
            echo "$(date '+%Y-%m-%d %H:%M:%S')    Download failed." >> "${LOG}";
            exit 2;
        fi;
        echo "$(date '+%Y-%m-%d %H:%M:%S')    Success." >> "${LOG}";

        # Extract the data.
        if [ "$(echo "$FILE" | grep -E "\.(tar.gz|tgz)$")" ];
        then
            echo "$(date '+%Y-%m-%d %H:%M:%S')    Extracting tarball..." >> "${LOG}";
            # Note that -C doesn't affect -f.
            tar -C "${DIR}" -zxf "${DIR}/${FILE}" >> "${LOG}" 2>&1;
            if [ "$?" -ne "0" ];
            then
                echo "$(date '+%Y-%m-%d %H:%M:%S')    Extract failed." >> "${LOG}";
                exit 3;
            fi;
            echo "$(date '+%Y-%m-%d %H:%M:%S')    Success." >> "${LOG}";

        elif [ "$(echo "$FILE" | grep "\.gz$")" ]
        then
            echo "$(date '+%Y-%m-%d %H:%M:%S')    Unzipping..." >> "${LOG}";
            unpigz "${DIR}/${FILE}" >> "${LOG}" 2>&1;
            if [ "$?" -ne "0" ];
            then
                echo "$(date '+%Y-%m-%d %H:%M:%S')    Extract failed." >> "${LOG}";
                exit 4;
            fi;
            echo "$(date '+%Y-%m-%d %H:%M:%S')    Success." >> "${LOG}";
        fi;
    fi;
done;



# Check if we have everything.
DIFF=$(diff <(echo -e "amc.txt\nerasmus.txt\nlumc.txt\nnki.txt\nradboud_mumc.txt\numcg.txt\numcu.txt\nvumc.txt") <(cd "${DIR}"; ls -1) | grep -v "^[0-9>]");
if [ "${DIFF}" ];
then
    echo "$(date '+%Y-%m-%d %H:%M:%S')    One or more data files are missing:" >> "${LOG}";
    echo "${DIFF}" | sed 's/^</                      /' >> "${LOG}";
    exit 5;
fi;

# If we get here, we have what we need. We will also have more (status.log, alissa.tar.gz), but that's OK.
# Adding this line also signals to the next step, that we're ready.
echo "$(date '+%Y-%m-%d %H:%M:%S') OK All files are ready, ready for the next step." >> "${LOG}";
