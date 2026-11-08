#!/bin/sh
# Start the local test servers of inbox:
# - Dovecot: IMAP 127.0.0.1:10143, ManageSieve 127.0.0.1:14190, SMTP submission 127.0.0.1:10587 (AUTH, then relays
#   to Mailpit only);
# - Mailpit: catches every mail sent, delivers nothing (web and API 127.0.0.1:18025);
# - Meilisearch (lot W7 1.20.0): full text search of the tests, 127.0.0.1:17700, test master key below.
# Test values only (conf/users, conf/master-users). Usage: dev/dovecot/run.sh [stop]
cd "$(dirname "$0")" || exit 1
docker rm -f inbox-dovecot inbox-mailpit inbox-meili >/dev/null 2>&1
[ "$1" = "stop" ] && exit 0
docker network inspect inbox-dev >/dev/null 2>&1 || docker network create inbox-dev >/dev/null
docker run -d --name inbox-mailpit --network inbox-dev -p 127.0.0.1:18025:8025 \
	-e MP_SMTP_AUTH_ACCEPT_ANY=1 -e MP_SMTP_AUTH_ALLOW_INSECURE=1 axllent/mailpit:v1.27.0 >/dev/null
docker run -d --name inbox-dovecot --network inbox-dev -p 127.0.0.1:10143:143 -p 127.0.0.1:14190:4190 -p 127.0.0.1:10587:587 \
	-v "$(pwd)/conf:/etc/dovecot:ro" -v inbox-dovecot-mail:/srv/mail dovecot/dovecot:2.3.21 /usr/sbin/dovecot -F
docker run -d --name inbox-meili --network inbox-dev -p 127.0.0.1:17700:7700 -e MEILI_ENV=development \
	-e MEILI_MASTER_KEY=inbox-meili-test-master-key -e MEILI_NO_ANALYTICS=true -v inbox-meili-data:/meili_data getmeili/meilisearch:v1.54.3 >/dev/null
