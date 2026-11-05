data "aws_instances" "stage" {
  instance_tags = {
    Env = "stage"
  }
  instance_state_names = ["running", "pending"]

  depends_on = [aws_autoscaling_group.stage]
}

data "aws_instances" "prod" {
  instance_tags = {
    Env = "prod"
  }
  instance_state_names = ["running", "pending"]

  depends_on = [aws_autoscaling_group.prod]
}

output "stage_ip" {
  value = try(sort(data.aws_instances.stage.public_ips)[0], "")
}

output "prod_ip" {
  value = try(sort(data.aws_instances.prod.public_ips)[0], "")
}

output "stage_alb_dns" {
  value = aws_lb.stage.dns_name
}

output "prod_alb_dns" {
  value = aws_lb.prod.dns_name
}

output "stage_asg_name" {
  value = aws_autoscaling_group.stage.name
}

output "prod_asg_name" {
  value = aws_autoscaling_group.prod.name
}

output "pem_file" {
  value = local_sensitive_file.pem.filename
}

output "bucket_name" {
  value = aws_s3_bucket.public.id
}

output "bucket_url" {
  value = "https://${aws_s3_bucket.public.bucket_regional_domain_name}"
}

output "instance_type" {
  value = local.instance_type
}
