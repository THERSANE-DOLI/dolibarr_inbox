#!/bin/sh
# Start the local test Dovecot of inbox: IMAP 127.0.0.1:10143, ManageSieve 127.0.0.1:14190, SMTP submission
# 127.0.0.1:10587 (AUTH only, never relays).
# Test values only (conf/users, conf/master-users). Usage: dev/dovecot/run.sh [stop]
cd "$(dirname "$0")" || exit 1
docker rm -f inbox-dovecot >/dev/null 2>&1
[ "$1" = "stop" ] && exit 0
docker run -d --name inbox-dovecot -p 127.0.0.1:10143:143 -p 127.0.0.1:14190:4190 -p 127.0.0.1:10587:587 \
	-v "$(pwd)/conf:/etc/dovecot:ro" -v inbox-dovecot-mail:/srv/mail dovecot/dovecot:2.3.21 /usr/sbin/dovecot -F
