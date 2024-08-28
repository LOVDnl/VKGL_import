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
