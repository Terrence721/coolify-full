#!/usr/bin/env bash
#
# Removes every untracked .log file in the repo, found via git ls-files
# rather than a hand-maintained list of directories/filenames to skip -
# respects .gitignore automatically (including storage/logs/.gitignore's
# blanket Laravel convention), and stays correct for any future .log file
# without the script itself needing updates.
set -euo pipefail
cd "$(dirname "$0")/.."

# --exclude-standard alone drops any .log file that already matches a
# .gitignore pattern (e.g. storage/logs/.gitignore's "*", npm-debug.log)
# instead of finding it - pairing it with --ignored flips to the opposite,
# ignored-only set. Neither call alone covers every .log file, so both are
# unioned; --exclude-standard still does its job either way, pruning most
# of the tree via .gitignore.
#
# node_modules/ and vendor/ are filtered out separately even though both
# are gitignored too: the --ignored pass would otherwise also reach
# genuine .log files shipped inside installed npm/Composer packages, which
# isn't what "this repo's logs" means.
mapfile -t log_files < <(
  { git ls-files --others --exclude-standard '*.log'
    git ls-files --others --ignored --exclude-standard '*.log'; } \
    | sort -u \
    | { grep -Ev '^(node_modules|vendor)/' || true; }
)

if [ "${#log_files[@]}" -eq 0 ]; then
  echo "No log files found."
  exit 0
fi

for f in "${log_files[@]}"; do
  rm -f -- "$f"
  echo "Removed $f"
done
