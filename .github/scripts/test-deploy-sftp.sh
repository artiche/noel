#!/usr/bin/env bash
set -euo pipefail

test_root="$(mktemp -d)"
cleanup() {
  rm -f "$test_root/bin/sshpass" "$test_root/home/.ssh/known_hosts" \
    "$test_root/home/.local/share/lftp/transfer_log" \
    "$test_root/runner/noel-api/index.php" "$test_root/runner/noel-seed/users.json" \
    "$test_root/runner/noel-config.local.php" \
    "$test_root/remote/api/index.php" "$test_root/remote/api/data/.htaccess" \
    "$test_root/remote/api/data/config.php" "$test_root/remote/api/data/config.local.php" \
    "$test_root/remote/api/data/users.json" "$test_root/remote/api/data/existing.json" \
    "$test_root/collision/api/index.php" "$test_root/collision/api/data"
  rmdir "$test_root/bin" "$test_root/home/.ssh" "$test_root/home/.config/lftp" \
    "$test_root/home/.config" "$test_root/home/.local/share/lftp" \
    "$test_root/home/.local/share" "$test_root/home/.local" "$test_root/home" \
    "$test_root/runner/noel-api" "$test_root/runner/noel-seed" "$test_root/runner" \
    "$test_root/remote/api/data" "$test_root/remote/api" "$test_root/remote" \
    "$test_root/collision/api" "$test_root/collision" "$test_root" 2>/dev/null || true
}
trap cleanup EXIT

mkdir -p "$test_root/bin" "$test_root/home" "$test_root/runner/noel-api" \
  "$test_root/runner/noel-seed" "$test_root/remote" "$test_root/collision/api"
cat > "$test_root/bin/sshpass" <<'EOF'
#!/usr/bin/env bash
exec "$TEST_SFTP_SERVER" -e
EOF
chmod +x "$test_root/bin/sshpass"
printf 'api' > "$test_root/runner/noel-api/index.php"
printf 'seed' > "$test_root/runner/noel-seed/users.json"
printf 'config' > "$test_root/runner/noel-config.local.php"

export HOME="$test_root/home" RUNNER_TEMP="$test_root/runner"
export PATH="$test_root/bin:$PATH" TEST_SFTP_SERVER="${TEST_SFTP_SERVER:-/usr/lib/openssh/sftp-server}"
export SFTP_HOST_KEY='test-host ssh-ed25519 test-key' SFTP_HOST='test-host' SFTP_USER='test'
export DEPLOY_API=true DEPLOY_UI=false SFTP_REMOTE_ROOT="$test_root/remote"

bash .github/scripts/deploy-sftp.sh
test "$(cat "$test_root/remote/api/data/users.json")" = 'seed'
printf 'existing users' > "$test_root/remote/api/data/users.json"
printf 'existing data' > "$test_root/remote/api/data/existing.json"
bash .github/scripts/deploy-sftp.sh
test "$(cat "$test_root/remote/api/data/users.json")" = 'existing users'
test "$(cat "$test_root/remote/api/data/existing.json")" = 'existing data'

printf 'not a directory' > "$test_root/collision/api/data"
export SFTP_REMOTE_ROOT="$test_root/collision"
if bash .github/scripts/deploy-sftp.sh; then
  echo 'Deployment unexpectedly succeeded with an invalid data directory' >&2
  exit 1
fi
test "$(cat "$test_root/collision/api/data")" = 'not a directory'
echo 'SFTP deployment tests passed.'
