#!/bin/bash

# A root sidecar must not publish a root-owned application var directory on
# the shared checkout. Prepare its owner/mode before the atomic rename so PHP
# can start concurrently, and retain an existing application's custom mode.
prepare_worker_runtime_directory() {
  local directory=$1 reference=$2 temporary
  if [[ -d "$directory" ]]; then
    if [[ $(id -u) == 0 && ! -L "$directory" && $(stat -c %u "$directory") == 0 && $(stat -c %u "$reference") != 0 ]]; then
      chown --reference="$reference" "$directory"
    fi
    return
  fi
  if [[ -e "$directory" || -L "$directory" ]]; then
    echo 'The worker runtime directory is not a directory.' >&2
    return 1
  fi
  temporary=$(mktemp -d "${directory}.worker-XXXXXXXX")
  if ! chmod 0755 "$temporary" || { [[ $(id -u) == 0 ]] && ! chown --reference="$reference" "$temporary"; }; then
    rmdir "$temporary"
    return 1
  fi
  if mv -T --no-clobber -- "$temporary" "$directory" 2>/dev/null && [[ ! -d "$temporary" ]]; then return; fi
  # Another worker or the web process published the directory first. Never
  # replace a populated directory or alter its contents during that race.
  rmdir "$temporary"
  [[ -d "$directory" ]]
}
