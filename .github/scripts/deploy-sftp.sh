#!/usr/bin/env bash
set -euo pipefail

mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
printf '%s\n' "$SFTP_HOST_KEY" > "$HOME/.ssh/known_hosts"
chmod 600 "$HOME/.ssh/known_hosts"

remote_root="${SFTP_REMOTE_ROOT%/}"
api_root="$remote_root/api"
remote_root="${remote_root:-/}"
lftp_settings="set cmd:fail-exit yes; set net:max-retries 2; set net:timeout 30; set sftp:connect-program 'sshpass -e ssh -a -x -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$HOME/.ssh/known_hosts'; open -u $SFTP_USER, sftp://$SFTP_HOST"

if [ "$DEPLOY_API" = "true" ]; then
  lftp -c "$lftp_settings; mirror --reverse --delete --transfer-all --exclude '(^|/)data(/|$)' '$RUNNER_TEMP/noel-api' '$api_root'"
  if ! lftp -c "$lftp_settings; set cmd:verify-path yes; cd '$api_root/data'"; then
    lftp -c "$lftp_settings; mkdir '$api_root/data'"
  fi
  lftp -c "$lftp_settings; put src_php/data/.htaccess -o '$api_root/data/.htaccess'; put src_php/data/config.php -o '$api_root/data/config.php'; put '$RUNNER_TEMP/noel-config.local.php' -o '$api_root/data/config.local.php'; mirror --reverse --only-missing '$RUNNER_TEMP/noel-seed' '$api_root/data'"
fi

if [ "$DEPLOY_UI" = "true" ]; then
  lftp -c "$lftp_settings; mirror --reverse --delete --transfer-all --exclude '(^|/)api(/|$)' 'src_angular/dist/noel/browser' '$remote_root'"
fi
