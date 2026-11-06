#!/usr/bin/env bash
# Scale ASG to max (2) or back to desired (1) so you can demo ALB + 2 nodes.
# Usage:
#   TARGET_ENV=stage STRESS_ACTION=scale_out bash scripts/ci/stress-asg.sh
#   TARGET_ENV=stage STRESS_ACTION=scale_in  bash scripts/ci/stress-asg.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TF_DIR="$ROOT/infra/terraform"
TARGET_ENV="${TARGET_ENV:-stage}"
STRESS_ACTION="${STRESS_ACTION:-scale_out}"
AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-ap-south-1}"
export AWS_DEFAULT_REGION

if [ "$TARGET_ENV" != "stage" ] && [ "$TARGET_ENV" != "prod" ]; then
  echo "TARGET_ENV must be stage or prod" >&2
  exit 1
fi

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing command: $1" >&2
    exit 1
  }
}

need_cmd aws

if command -v terraform >/dev/null 2>&1; then
  ASG_NAME="${ASG_NAME:-$(terraform -chdir="$TF_DIR" output -raw "${TARGET_ENV}_asg_name" 2>/dev/null || true)}"
  ALB_DNS="${ALB_DNS:-$(terraform -chdir="$TF_DIR" output -raw "${TARGET_ENV}_alb_dns" 2>/dev/null || true)}"
else
  ASG_NAME="${ASG_NAME:-}"
  ALB_DNS="${ALB_DNS:-}"
fi

# Allow CI dotenv / manual override: STAGE_ASG_NAME / PROD_ASG_NAME
if [ -z "$ASG_NAME" ]; then
  if [ "$TARGET_ENV" = "stage" ]; then
    ASG_NAME="${STAGE_ASG_NAME:-veto-stage-asg}"
    ALB_DNS="${STAGE_ALB_DNS:-$ALB_DNS}"
  else
    ASG_NAME="${PROD_ASG_NAME:-veto-prod-asg}"
    ALB_DNS="${PROD_ALB_DNS:-$ALB_DNS}"
  fi
fi

echo "stress-asg: env=$TARGET_ENV action=$STRESS_ACTION asg=$ASG_NAME"

case "$STRESS_ACTION" in
  scale_out)
    DESIRED=2
    ;;
  scale_in)
    DESIRED=1
    ;;
  *)
    echo "STRESS_ACTION must be scale_out or scale_in" >&2
    exit 1
    ;;
esac

aws autoscaling set-desired-capacity \
  --auto-scaling-group-name "$ASG_NAME" \
  --desired-capacity "$DESIRED" \
  --no-honor-cooldown

echo "stress-asg: desired capacity set to $DESIRED — waiting for InService instances..."

for i in $(seq 1 36); do
  COUNT="$(aws autoscaling describe-auto-scaling-groups \
    --auto-scaling-group-names "$ASG_NAME" \
    --query "AutoScalingGroups[0].Instances[?LifecycleState=='InService'] | length(@)" \
    --output text 2>/dev/null || echo 0)"
  echo "  attempt $i: InService=$COUNT (want $DESIRED)"
  if [ "$COUNT" = "$DESIRED" ]; then
    break
  fi
  sleep 10
done

aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names "$ASG_NAME" \
  --query "AutoScalingGroups[0].{Desired:DesiredCapacity,Min:MinSize,Max:MaxSize,Instances:Instances[*].{Id:InstanceId,State:LifecycleState,AZ:AvailabilityZone}}" \
  --output table

if [ -n "$ALB_DNS" ]; then
  echo ""
  echo "ALB: http://${ALB_DNS}/"
  echo "Refresh the homepage — footer should show node IP/pod when 2 WP pods are up."
fi

echo "stress-asg: done ($STRESS_ACTION)"
