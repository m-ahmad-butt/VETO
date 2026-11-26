output "wordpress_public_dns" {
  value = aws_instance.wordpress.public_dns
}

output "veto_public_dns" {
  value = aws_instance.veto.public_dns
}

output "wordpress_url" {
  value = "http://${aws_instance.wordpress.public_dns}"
}

output "wordpress_instance_id" {
  value = aws_instance.wordpress.id
}

output "veto_instance_id" {
  value = aws_instance.veto.id
}
