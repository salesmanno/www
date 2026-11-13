#!/usr/bin/env bash
set -euo pipefail

readonly EXPECTED_REMOTE="https://github.com/salesmanno/www.git"
readonly REPOSITORY="salesmanno/www"
readonly WORKFLOW="Ftp_deployment.yaml"

check_only=false
case "${1:-}" in
  "") ;;
  --check) check_only=true ;;
  *) echo "Usage: bash .github/scripts/publish_from_replit.sh [--check]" >&2; exit 2 ;;
esac
if [[ "$#" -gt 1 ]]; then
  echo "Usage: bash .github/scripts/publish_from_replit.sh [--check]" >&2
  exit 2
fi

for executable in git gh; do
  if ! command -v "$executable" >/dev/null; then
    echo "Publish stopped: required command '$executable' is unavailable." >&2
    exit 1
  fi
done

branch="$(git branch --show-current)"
if [[ "$branch" != "main" ]]; then
  echo "Publish stopped: this workflow only publishes the main branch (current: $branch)." >&2
  exit 1
fi

# `get-url` applies Replit's internal GitHub URL rewrite. Validate the
# repository URL as configured instead, before Git applies that transport rewrite.
remote="$(git config --get remote.origin.url)"
if [[ "$remote" != "$EXPECTED_REMOTE" ]]; then
  echo "Publish stopped: origin is not the expected www repository. No files were pushed." >&2
  exit 1
fi

export GIT_TERMINAL_PROMPT=0 GH_PROMPT_DISABLED=1
# Agent's managed GitHub proxy is not available in ordinary workflow processes.
# Use one explicit credential for both Git and the GitHub Actions API.
export GH_TOKEN="${GH_TOKEN:-${MY_PERSONAL_KEY:-}}"
if [[ -z "$GH_TOKEN" ]]; then
  echo "Publish stopped: set MY_PERSONAL_KEY in Replit Secrets to a valid GitHub token with repository write and workflow access. Never paste it in chat. Nothing was committed or uploaded." >&2
  exit 1
fi

github_push() {
  git -c credential.helper= \
    -c 'credential.helper=!f() { if [ "$1" = get ]; then printf "%s\n" "username=x-access-token" "password=$GH_TOKEN"; fi; }; f' \
    push "$@"
}

echo "Checking GitHub workflow access..."
if ! workflow_state="$(gh api "repos/$REPOSITORY/actions/workflows/$WORKFLOW" --jq '.state')"; then
  echo "Publish stopped: GitHub rejected access to the workflow. Replace MY_PERSONAL_KEY in Replit Secrets with a valid token for salesmanno/www, including repository write and Actions access. Nothing was committed or uploaded." >&2
  exit 1
fi
if [[ "$workflow_state" != "active" ]]; then
  echo "Publish stopped: the GitHub FTP workflow is not active. Nothing was committed or uploaded." >&2
  exit 1
fi

echo "Checking push access without changing GitHub..."
if ! github_push --dry-run origin main; then
  echo "Publish stopped: push preflight failed. Resolve the reported access or branch conflict first. Nothing was committed or uploaded." >&2
  exit 1
fi
if [[ "$check_only" == true ]]; then
  echo "Checks passed. No commit, push, workflow dispatch or FTP upload was performed."
  exit 0
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

echo "Pushing main to GitHub..."
github_push origin main

echo "Starting the approved FTP publish to salesman.no..."
if ! gh workflow run "$WORKFLOW" --repo "$REPOSITORY" --ref main \
    -f publish=true -f inspect_target=false; then
  echo "GitHub received the code push, but did not start the FTP publish. Check Actions permissions for the connected GitHub account, then run the publish action from GitHub." >&2
  exit 1
fi

echo "Publish requested. Check GitHub Actions → Domeneshop FTP for completion."
