#!/bin/sh
# Rspamd of the tests of inbox (1.88.0), beside the test mail server (dev/dovecot/run.sh, network inbox-dev):
# - inbox-rspamd: check 127.0.0.1:11333, controller 127.0.0.1:11334 (password below, test value only);
# - inbox-redis: its learning (bayes).
# The configuration of local.d/ never asks rspamd.com (no public fuzzy storage, no remote maps, no monitoring)
# and keeps the lists of reputation by DNS for inbox to turn on (header « Settings » of each check).
# Usage: dev/rspamd/run.sh [stop]
cd "$(dirname "$0")" || exit 1
RSPAMD_IMAGE=rspamd/rspamd:3.11.1
REDIS_IMAGE=redis:7.4-alpine
PASSWORD=inbox-rspamd-test-password
docker rm -f inbox-rspamd inbox-redis >/dev/null 2>&1
[ "$1" = "stop" ] && exit 0
docker network inspect inbox-dev >/dev/null 2>&1 || docker network create inbox-dev >/dev/null
docker run -d --name inbox-redis --network inbox-dev -v inbox-rspamd-redis:/data "$REDIS_IMAGE" redis-server --appendonly yes >/dev/null
# The controller asks a hashed password: made by rspamadm of the same image
HASH=$(docker run --rm "$RSPAMD_IMAGE" rspamadm pw -p "$PASSWORD" 2>/dev/null | tail -n 1)
mkdir -p override.d
printf 'password = "%s";\nenable_password = "%s";\nbind_socket = "*:11334";\n' "$HASH" "$HASH" > override.d/worker-controller.inc
docker run -d --name inbox-rspamd --network inbox-dev -p 127.0.0.1:11333:11333 -p 127.0.0.1:11334:11334 \
	-v "$(pwd)/local.d:/etc/rspamd/local.d:ro" -v "$(pwd)/override.d:/etc/rspamd/override.d:ro" "$RSPAMD_IMAGE" >/dev/null
echo "Rspamd: http://127.0.0.1:11333 (check), http://127.0.0.1:11334 (controller, password $PASSWORD)"
