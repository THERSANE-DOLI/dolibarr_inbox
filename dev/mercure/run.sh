#!/bin/sh
# Mercure hub for the instant refresh of inbox (1.124.0), with docker-compose.yml, on 127.0.0.1:3001.
# - First start: two random keys and the settings written in .env (readable by you only), shown once to type in
#   Inbox > Settings > Server > Push service. Never keys known in advance.
# - DOLIBARR_ORIGIN: address of Dolibarr as the browsers see it (scheme + host, no path), allowed to read the stream.
# Usage: DOLIBARR_ORIGIN=https://erp.example.com dev/mercure/run.sh [stop]
cd "$(dirname "$0")" || exit 1
if [ "$1" = "stop" ]; then
	docker compose down
	exit 0
fi
if [ ! -f .env ]; then
	if [ -z "$DOLIBARR_ORIGIN" ]; then
		echo "DOLIBARR_ORIGIN is needed the first time, for example DOLIBARR_ORIGIN=https://erp.example.com $0" >&2
		exit 1
	fi
	umask 077
	PUB=$(head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-48)
	SUB=$(head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-48)
	printf 'MERCURE_PUBLISHER_JWT_KEY=%s\nMERCURE_SUBSCRIBER_JWT_KEY=%s\nMERCURE_TRUSTED_ISSUERS=%s\nDOLIBARR_ORIGIN=%s\n' "$PUB" "$SUB" "$DOLIBARR_ORIGIN" "$DOLIBARR_ORIGIN" > .env
	echo "Settings of the push service in inbox (shown once, kept in dev/mercure/.env):"
	echo "  Trusted issuer:        $DOLIBARR_ORIGIN"
	echo "  Key of the publishers: $PUB"
	echo "  Key of the subscribers: $SUB"
fi
docker compose up -d
echo "Mercure: http://127.0.0.1:3001/.well-known/mercure (address for the server)"
