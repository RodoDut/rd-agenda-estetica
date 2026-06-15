#!/usr/bin/env bash
# generate-sftp.sh - generate .vscode/sftp.json from .env
set -euo pipefail
if [ ! -f .env ]; then
  echo "Missing .env. Copy .env.example -> .env and fill the values." >&2
  exit 1
fi
# shellcheck disable=SC1091
set -a
. ./.env
set +a

# Determine port default (22 for sftp, 21 for ftp)
PORT=${SFTP_PORT:-}
if [ -z "$PORT" ]; then
  if [ "${SFTP_PROTOCOL:-ftp}" = "sftp" ]; then
    PORT=22
  else
    PORT=21
  fi
fi

mkdir -p .vscode
cat > .vscode/sftp.json <<JSON
{
  "name": "SFTP (local)",
  "host": "${SFTP_HOST}",
  "protocol": "${SFTP_PROTOCOL:-ftp}",
  "port": ${PORT},
  "passive": ${SFTP_PASSIVE:-true},
  "username": "${SFTP_USER}",
$( if [ "${SFTP_PROTOCOL:-ftp}" = "sftp" ] && [ -n "${SFTP_PRIVATE_KEY:-}" ]; then
     echo "  \"privateKeyPath\": \"${SFTP_PRIVATE_KEY}\",";
     if [ -n "${SFTP_PASSPHRASE:-}" ]; then echo "  \"passphrase\": \"${SFTP_PASSPHRASE}\","; fi
   else
     echo "  \"password\": \"${SFTP_PASSWORD}\",";
   fi )
  "context": "${SFTP_CONTEXT:-c:/rdtecnobelleza}",
  "remotePath": "${SFTP_REMOTE_PATH:-/public_html}",
  "uploadOnSave": false,
  "downloadOnOpen": false,
  "syncMode": "full",
  "ignore": [
    "**/.vscode/**",
    "**/.git/**",
    "**/.DS_Store"
  ],
  "watcher": {
    "files": "**/*",
    "autoUpload": false,
    "autoDelete": false
  },
  "remoteTimeOffsetInHours": 0
}
JSON

echo "Wrote .vscode/sftp.json (DO NOT commit this file)."
