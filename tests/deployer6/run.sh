#!/bin/sh
# Runs the end-to-end scenarios of this directory against a throwaway deploy target
set -eu

cd "$(dirname "$0")"

KEYS_DIR=$(mktemp -d)
export KEYS_DIR
trap 'docker compose down -v --remove-orphans >/dev/null 2>&1; rm -rf "$KEYS_DIR"' EXIT

ssh-keygen -q -t ed25519 -N '' -f "$KEYS_DIR/id" -C e2e
docker compose up -d --build --quiet-pull sshd mock >/dev/null
docker compose run --rm driver
