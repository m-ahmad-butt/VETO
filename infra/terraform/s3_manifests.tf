# all local yaml files
locals {
  k8s_files = fileset("${path.module}/../../deploy/k8s", "**/*.{yaml,yml}")
}

# upload all those in s3
resource "aws_s3_object" "k8s_manifests" {
  for_each = local.k8s_files

  bucket = aws_s3_bucket.public.id
  key    = "deploy/k8s/${each.value}"
  source = "${path.module}/../../deploy/k8s/${each.value}"
  etag   = filemd5("${path.module}/../../deploy/k8s/${each.value}")

  content_type = "application/yaml"
}
