#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
K8S_DIR="$ROOT/deploy/k8s"

export KUBECONFIG="${KUBECONFIG:-/etc/rancher/k3s/k3s.yaml}"

kubectl apply -f "$K8S_DIR/namespace.yaml"
kubectl apply -f "$K8S_DIR/lab/"
kubectl apply -f "$K8S_DIR/monitoring/"
kubectl -n veto-lab rollout status deployment/wordpress --timeout=180s || true
kubectl -n monitoring rollout status deployment/grafana --timeout=180s || true
kubectl get ingress -A
kubectl get pods -A
echo "deploy-k8s: ok"
