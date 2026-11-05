#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TF_DIR="$ROOT/infra/terraform"
ANSIBLE_DIR="$ROOT/infra/ansible"
INVENTORY="$ANSIBLE_DIR/inventory/hosts.yml"
SSH_KEY="${ANSIBLE_SSH_KEY:-${SSH_PRIVATE_KEY_FILE:-}}"

if [ -z "$SSH_KEY" ] || [ ! -f "$SSH_KEY" ]; then
  echo "Set ANSIBLE_SSH_KEY or SSH_PRIVATE_KEY_FILE to a readable private key file." >&2
  exit 1
fi

chmod 600 "$SSH_KEY" || true

GREEN_IP="$(terraform -chdir="$TF_DIR" output -raw green_public_ip 2>/dev/null || true)"
BLUE_IP="$(terraform -chdir="$TF_DIR" output -raw blue_public_ip 2>/dev/null || true)"

if [ -z "$GREEN_IP" ] && [ -z "$BLUE_IP" ]; then
  echo "No terraform outputs (green_public_ip / blue_public_ip). Run terraform apply first." >&2
  exit 1
fi

mkdir -p "$ANSIBLE_DIR/inventory"
{
  echo "all:"
  echo "  children:"
  echo "    veto_lab:"
  echo "      hosts:"
  if [ -n "$GREEN_IP" ]; then
    echo "        green:"
    echo "          ansible_host: $GREEN_IP"
  fi
  if [ -n "$BLUE_IP" ]; then
    echo "        blue:"
    echo "          ansible_host: $BLUE_IP"
  fi
  echo "  vars:"
  echo "    ansible_user: ubuntu"
  echo "    ansible_ssh_private_key_file: $SSH_KEY"
  echo "    ansible_ssh_common_args: '-o StrictHostKeyChecking=no'"
} > "$INVENTORY"

ansible-playbook -i "$INVENTORY" "$ANSIBLE_DIR/playbooks/site.yml"
