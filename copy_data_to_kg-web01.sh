#!/bin/bash

PWD="$(dirname $0)";
DATE="$(${PWD}/get_run_date.sh)";
DIR="${PWD}/${DATE}";

rsync -av ${DIR}/vkgl_consensus_* "kg-web01:/home/${USER}/git/VKGL_import/"

