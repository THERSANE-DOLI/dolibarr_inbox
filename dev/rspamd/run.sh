#!/bin/sh
# Rspamd and Redis for the anti-spam engine of inbox (1.88.0), with docker-compose.yml:
# - check 127.0.0.1:11333, controller 127.0.0.1:11334;
# - the password of the controller: RSPAMD_PASSWORD, else a random one shown once (never a password known in
#   advance, audit INBX-078); written hashed in override.d/worker-controller.inc, to type the same in Settings >
#   Anti-spam of inbox.
# The configuration of local.d/ and override.d/ asks no list of reputation nor update outside; Rspamd still asks
# the DNS for SPF, DKIM and DMARC of the senders of the mails it judges (see the README of inbox).
# Usage: [RSPAMD_PASSWORD=...] dev/rspamd/run.sh [stop]
cd "$(dirname "$0")" || exit 1
if [ "$1" = "stop" ]; then
	docker compose down
	exit 0
fi
# The image of docker-compose.yml (kept up to date by Dependabot), to hash the password with the same version
RSPAMD_IMAGE=$(sed -n 's#^ *image: *\(rspamd/rspamd:[^ ]*\).*#\1#p' docker-compose.yml)
[ -n "$RSPAMD_IMAGE" ] || { echo "No image rspamd/rspamd in docker-compose.yml" >&2; exit 1; }
PASSWORD=$RSPAMD_PASSWORD
if [ -z "$PASSWORD" ]; then
	PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-24)
	echo "Password of the controller (shown once, type it in Settings > Anti-spam): $PASSWORD"
fi
# Containers of a former version of this script (docker run): replaced by the ones of compose
docker rm -f inbox-rspamd inbox-redis >/dev/null 2>&1
HASH=$(docker run --rm "$RSPAMD_IMAGE" rspamadm pw -p "$PASSWORD" 2>/dev/null | tail -n 1)
case "$HASH" in
	'$'*) ;;
	*) echo "Hash of the password failed" >&2; exit 1 ;;
esac
mkdir -p override.d
printf 'password = "%s";\nenable_password = "%s";\nbind_socket = "*:11334";\nallow_file_and_shm_inputs = false;\n' "$HASH" "$HASH" > override.d/worker-controller.inc
# Read by the user of Rspamd in the container: readable by all, the password is hashed
chmod 644 override.d/worker-controller.inc
docker compose up -d
echo "Rspamd: http://127.0.0.1:11333 (check), http://127.0.0.1:11334 (controller)"
