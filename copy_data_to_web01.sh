#!/bin/bash

echo "This will overwrite the remote caches. Are you sure?";
read;

rsync -av $(ls -1 /www/git/VKGL_import/vkgl_consensus_$(date +%Y-%m)* | grep -v chr1 | tr '\n' ' ') ifokkema@web01:/home/ifokkema/git/VKGL_import/
rsync -av /www/git/caches/NC_cache.txt /www/git/caches/mapping_cache.txt ifokkema@web01:/home/ifokkema/git/caches/

