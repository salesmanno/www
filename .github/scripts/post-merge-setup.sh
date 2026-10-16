#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

if git rev-parse --verify HEAD^ >/dev/null 2>&1; then
  changed_files="$(git diff --name-only HEAD^ HEAD)"
else
  changed_files="$(git ls-files)"
fi

if grep -Eq '(^|/)composer\.(json|lock)$' <<<"$changed_files"; then
  if [[ -f composer.json && -f composer.lock ]]; then
    composer install --no-interaction --prefer-dist --no-progress
  fi
else
  echo "Composer manifests unchanged; skipping dependency install."
fi

if git rev-parse --verify HEAD^ >/dev/null 2>&1 && [[ -f package.json ]]; then
  previous_dependencies="$(git show HEAD^:package.json | jq -cS '[.dependencies // {}, .devDependencies // {}, .optionalDependencies // {}, .peerDependencies // {}]')"
  current_dependencies="$(jq -cS '[.dependencies // {}, .devDependencies // {}, .optionalDependencies // {}, .peerDependencies // {}]' package.json)"
  dependencies_changed=false
  [[ "$previous_dependencies" == "$current_dependencies" ]] || dependencies_changed=true
  lockfile_changed=false
  git diff --quiet HEAD^ HEAD -- package-lock.json || lockfile_changed=true
  if [[ "$dependencies_changed" == true && "$lockfile_changed" != true ]]; then
    echo "Node dependencies changed without an updated package-lock.json." >&2
    exit 1
  fi
  if [[ "$lockfile_changed" == true ]]; then
    npm ci --no-audit --no-fund
  else
    echo "Node dependencies unchanged; skipping network install."
  fi
elif [[ -f package.json && -f package-lock.json ]]; then
  npm ci --no-audit --no-fund
else
  echo "No locked Node dependencies to install."
fi

npm test --if-present
