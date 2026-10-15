#!/usr/bin/env bash
set -euo pipefail

readonly EXPECTED_REMOTE="https://github.com/salesmanno/www.git"
readonly DISPATCH_URL="https://api.github.com/repos/salesmanno/www/actions/workflows/Ftp_deployment.yaml/dispatches"

: "${MY_PERSONAL_KEY:?MY_PERSONAL_KEY is not configured in Replit Secrets}"

branch="$(git branch --show-current)"
if [[ "$branch" != "main" ]]; then
  echo "Publish stopped: this workflow only publishes the main branch (current: $branch)." >&2
  exit 1
fi

remote="$(git remote get-url origin)"
if [[ "$remote" != "$EXPECTED_REMOTE" ]]; then
  echo "Publish stopped: origin is not the expected www repository. No files were pushed." >&2
  exit 1
fi

git add --all

# Never include likely secrets or private connection/config files in an automatic commit.
if git diff --cached --name-only | grep -Eiq '(^|/)(\.env([^/]*)?|[^/]*(database|credential|secret|password|token)[^/]*\.(php|json|ya?ml|ini|conf|pem|key))$'; then
  echo "Publish stopped: staged changes include a possible secret/configuration file. Review it before publishing." >&2
  exit 1
fi

if ! git diff --cached --quiet; then
  echo "Saving project changes to Git..."
  git -c user.name="Replit Publish Workflow" \
      -c user.email="replit-publish@users.noreply.github.com" \
      commit -m "Publish website update $(date -u +'%Y-%m-%d %H:%M UTC')"
else
  echo "No uncommitted changes; publishing the latest main commit."
fi

export GIT_TERMINAL_PROMPT=0
echo "Pushing main to GitHub..."
git -c credential.helper= \
    -c 'credential.helper=!f() { if [ "$1" = get ]; then printf "%s\n" "username=x-access-token" "password=$MY_PERSONAL_KEY"; fi; }; f' \
    push origin main

echo "Starting the approved FTP publish to salesman.no..."
if ! curl --silent --show-error --fail \
    --request POST "$DISPATCH_URL" \
    --header "Accept: application/vnd.github+json" \
    --header "Authorization: Bearer $MY_PERSONAL_KEY" \
    --header "X-GitHub-Api-Version: 2022-11-28" \
    --header "Content-Type: application/json" \
    --data '{"ref":"main","inputs":{"publish":"true","inspect_target":"false"}}'; then
  echo "GitHub received the code push, but did not start the FTP publish. Check the token's repository Actions permissions, then run the publish action from GitHub." >&2
  exit 1
fi

echo "Publish requested. Check GitHub Actions → Domeneshop FTP for completion."
