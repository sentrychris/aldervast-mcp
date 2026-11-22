#!/usr/bin/env bash
# Copies the website's code to the server and rebuilds its Docker container. No sudo needed: the
# account only has to be in the "docker" group. Run it from the project folder, in Git Bash:
#   bash deploy/deploy.sh                  # to chris@versyxdigital.net
#   bash deploy/deploy.sh user@other-host  # somewhere else
#
# Only the files that the image needs are sent, so .env, logs/ and cache/ never leave this PC.
# On the server, the code goes in ~/aldervast-mcp; settings such as the API key go in
# ~/aldervast-mcp/.env there, which this script never touches.
set -euo pipefail

HOST="${1:-chris@versyxdigital.net}"
DIR=aldervast-mcp

tar -czf - composer.json composer.lock Dockerfile docker-compose.yml .dockerignore docker deploy public src show-chat-usage.php \
  | ssh "$HOST" "mkdir -p $DIR && cd $DIR && rm -rf docker deploy public src && tar -xzf - && docker compose up -d --build"

echo "Deployed. Check it with: curl -s http://127.0.0.1:8090/ on the server, or the public URL."
