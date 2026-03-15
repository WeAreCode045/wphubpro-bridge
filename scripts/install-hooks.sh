#!/bin/sh
# Install git hooks for the bridge plugin (run from wphubpro-bridge root).
# Copies hooks/* to .git/hooks/ so that e.g. post-commit runs after each commit.
HOOKS_SRC="$(dirname "$0")/../hooks"
GIT_HOOKS="$(git rev-parse --git-dir)/hooks"
if [ ! -d "$HOOKS_SRC" ] || [ ! -d "$GIT_HOOKS" ]; then
  echo "Missing hooks dir or .git/hooks"
  exit 1
fi
for f in "$HOOKS_SRC"/*; do
  [ -f "$f" ] || continue
  name="$(basename "$f")"
  cp "$f" "${GIT_HOOKS}/${name}"
  chmod +x "${GIT_HOOKS}/${name}"
  echo "Installed hook: $name"
done
echo "Hooks installed. On each commit, version will be bumped and a zip uploaded to Appwrite bucket 'bridge'."
