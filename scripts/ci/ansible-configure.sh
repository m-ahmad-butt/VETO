#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TF_DIR="$ROOT/infra/terraform"
ANSIBLE_DIR="$ROOT/infra/ansible"
INVENTORY="$ANSIBLE_DIR/inventory/hosts.yml"
SSH_KEY="${ANSIBLE_SSH_KEY:-${SSH_PRIVATE_KEY_FILE:-}}"
TARGET_ENV="${TARGET_ENV:-all}"

if [ -z "$SSH_KEY" ] || [ ! -f "$SSH_KEY" ]; then
  echo "Set ANSIBLE_SSH_KEY or SSH_PRIVATE_KEY_FILE to a readable private key file." >&2
  exit 1
fi

chmod 600 "$SSH_KEY" || true

STAGE_IP="${STAGE_IP:-$(terraform -chdir="$TF_DIR" output -raw stage_ip 2>/dev/null || true)}"
PROD_IP="${PROD_IP:-$(terraform -chdir="$TF_DIR" output -raw prod_ip 2>/dev/null || true)}"

if [ -z "$STAGE_IP" ] && [ -z "$PROD_IP" ]; then
  echo "No terraform outputs (stage_ip / prod_ip). Run terraform apply first." >&2
  exit 1
fi

mkdir -p "$ANSIBLE_DIR/inventory"
{
  echo "all:"
  echo "  children:"
  echo "    veto_lab:"
  echo "      hosts:"
  if [ "$TARGET_ENV" = "all" ] || [ "$TARGET_ENV" = "stage" ]; then
    if [ -n "$STAGE_IP" ]; then
      echo "        stage:"
      echo "          ansible_host: $STAGE_IP"
      echo "          veto_env: stage"
    fi
  fi
  if [ "$TARGET_ENV" = "all" ] || [ "$TARGET_ENV" = "prod" ]; then
    if [ -n "$PROD_IP" ]; then
      echo "        prod:"
      echo "          ansible_host: $PROD_IP"
      echo "          veto_env: prod"
    fi
  fi
  echo "  vars:"
  echo "    ansible_user: ubuntu"
  echo "    ansible_ssh_private_key_file: $SSH_KEY"
  echo "    ansible_ssh_common_args: '-o StrictHostKeyChecking=no'"
} > "$INVENTORY"

ansible-playbook -i "$INVENTORY" "$ANSIBLE_DIR/playbooks/site.yml"
