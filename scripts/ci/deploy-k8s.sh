#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
K8S_DIR="$ROOT/deploy/k8s"

export KUBECONFIG="${KUBECONFIG:-/etc/rancher/k3s/k3s.yaml}"

kubectl apply -f "$K8S_DIR/namespace.yaml"
# yaml only — skip mu-plugins/*.php reference copies
find "$K8S_DIR/lab" -type f \( -name '*.yaml' -o -name '*.yml' \) -print0 | sort -z | xargs -0 -r kubectl apply -f
find "$K8S_DIR/monitoring" -type f \( -name '*.yaml' -o -name '*.yml' \) -print0 | sort -z | xargs -0 -r kubectl apply -f
kubectl -n veto-lab rollout status deployment/wordpress --timeout=180s || true
kubectl -n monitoring rollout status deployment/grafana --timeout=180s || true
kubectl get ingress -A
kubectl get pods -A
echo "deploy-k8s: ok"
