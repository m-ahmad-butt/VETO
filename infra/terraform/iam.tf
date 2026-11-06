data "aws_caller_identity" "current" {}

data "aws_region" "current" {}

data "aws_iam_policy_document" "node_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "node" {
  name               = "veto-node-role"
  assume_role_policy = data.aws_iam_policy_document.node_assume.json

  tags = {
    Name = "veto-node-role"
  }
}

data "aws_iam_policy_document" "node" {
  statement {
    sid = "SsmK3sJoin"
    actions = [
      "ssm:PutParameter",
      "ssm:GetParameter",
      "ssm:GetParameters",
      "ssm:DeleteParameter",
      "ssm:DescribeParameters",
    ]
    resources = [
      "arn:aws:ssm:${data.aws_region.current.name}:${data.aws_caller_identity.current.account_id}:parameter/veto/*",
    ]
  }

  statement {
    sid = "S3Manifests"
    actions = [
      "s3:GetObject",
      "s3:ListBucket",
    ]
    resources = [
      aws_s3_bucket.public.arn,
      "${aws_s3_bucket.public.arn}/*",
    ]
  }
}

resource "aws_iam_role_policy" "node" {
  name   = "veto-node-policy"
  role   = aws_iam_role.node.id
  policy = data.aws_iam_policy_document.node.json
}

resource "aws_iam_instance_profile" "node" {
  name = "veto-node-profile"
  role = aws_iam_role.node.name
}
