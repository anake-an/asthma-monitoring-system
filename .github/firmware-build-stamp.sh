#!/bin/sh
# Build stamp for a firmware folder: today's date (YYMMDD, in $TZ), plus ".N" when this is the
# Nth commit on main today that changed that folder. Used by the "Publish firmware" CI job.
folder=$1
day=$(date +%y%m%d)
n=$(git log --first-parent --since="$(date +%Y-%m-%d) 00:00" --format=%H -- "$folder" | wc -l)
if [ "$n" -gt 1 ]; then echo "$day.$n"; else echo "$day"; fi
