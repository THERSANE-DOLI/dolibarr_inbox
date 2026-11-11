#!/bin/sh
# Rspamd and Redis for the anti-spam engine of inbox (1.88.0), with docker-compose.yml:
# - check 127.0.0.1:11333, controller 127.0.0.1:11334;
# - the password of the controller: RSPAMD_PASSWORD (production), else the password of the tests below;
#   written hashed in override.d/worker-controller.inc, to type the same in Settings > Anti-spam of inbox.
# The configuration of local.d/ and override.d/ never asks outside (see the README of inbox).
# Usage: [RSPAMD_PASSWORD=...] dev/rspamd/run.sh [stop]
cd "$(dirname "$0")" || exit 1
if [ "$1" = "stop" ]; then
	docker compose down
	exit 0
fi
RSPAMD_IMAGE=rspamd/rspamd:3.11.1
PASSWORD=${RSPAMD_PASSWORD:-inbox-rspamd-test-password}
[ -z "$RSPAMD_PASSWORD" ] && echo "Test password of the controller (set RSPAMD_PASSWORD for a production)"
# Containers of a former version of this script (docker run): replaced by the ones of compose
docker rm -f inbox-rspamd inbox-redis >/dev/null 2>&1
HASH=$(docker run --rm "$RSPAMD_IMAGE" rspamadm pw -p "$PASSWORD" 2>/dev/null | tail -n 1)
case "$HASH" in
	'$'*) ;;
	*) echo "Hash of the password failed" >&2; exit 1 ;;
esac
mkdir -p override.d
printf 'password = "%s";\nenable_password = "%s";\nbind_socket = "*:11334";\n' "$HASH" "$HASH" > override.d/worker-controller.inc
# Read by the user of Rspamd in the container: readable by all, the password is hashed
chmod 644 override.d/worker-controller.inc
docker compose up -d
echo "Rspamd: http://127.0.0.1:11333 (check), http://127.0.0.1:11334 (controller)"
