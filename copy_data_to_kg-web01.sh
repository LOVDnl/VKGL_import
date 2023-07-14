#!/bin/bash

rsync -av $(ls -1 /www/git/VKGL_import/$(date +%Y-%m)/vkgl_consensus_* | tr '\n' ' ') "kg-web01:/home/${USER}/git/VKGL_import/"

