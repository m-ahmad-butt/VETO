terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
    local = {
      source  = "hashicorp/local"
      version = "~> 2.0"
    }
  }
}

provider "aws" {
  region = "ap-south-1"
}

locals {
  instance_type = "m7i-flex.large"
  volume_size   = 50
}

resource "tls_private_key" "server_key" {
  algorithm = "RSA"
  rsa_bits  = 4096
}

resource "aws_key_pair" "server_key" {
  key_name   = "server-web-key"
  public_key = tls_private_key.server_key.public_key_openssh
}

resource "local_sensitive_file" "pem" {
  content         = tls_private_key.server_key.private_key_pem
  filename        = "${path.module}/server-web.pem"
  file_permission = "0600"
}

resource "aws_security_group" "server_sg" {
  name        = "server-web-sg"
  description = "Server Web Security Group"

  ingress {
    description = "SSH"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTP"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "server-web-sg"
  }
}

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"]

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

resource "aws_instance" "stage" {
  ami           = data.aws_ami.ubuntu.id
  instance_type = local.instance_type
  key_name      = aws_key_pair.server_key.key_name

  vpc_security_group_ids = [
    aws_security_group.server_sg.id
  ]

  root_block_device {
    volume_size = local.volume_size
    volume_type = "gp3"
  }

  tags = {
    Name = "veto-stage"
    Role = "stage"
    Env  = "stage"
  }
}

resource "aws_instance" "prod" {
  ami           = data.aws_ami.ubuntu.id
  instance_type = local.instance_type
  key_name      = aws_key_pair.server_key.key_name

  vpc_security_group_ids = [
    aws_security_group.server_sg.id
  ]

  root_block_device {
    volume_size = local.volume_size
    volume_type = "gp3"
  }

  tags = {
    Name = "veto-prod"
    Role = "prod"
    Env  = "prod"
  }
}

resource "aws_eip" "stage" {
  domain = "vpc"

  tags = {
    Name = "veto-stage-eip"
    Role = "stage"
  }
}

resource "aws_eip" "prod" {
  domain = "vpc"

  tags = {
    Name = "veto-prod-eip"
    Role = "prod"
  }
}

resource "aws_eip_association" "stage" {
  instance_id   = aws_instance.stage.id
  allocation_id = aws_eip.stage.id
}

resource "aws_eip_association" "prod" {
  instance_id   = aws_instance.prod.id
  allocation_id = aws_eip.prod.id
}

moved {
  from = aws_instance.blue
  to   = aws_instance.stage
}

moved {
  from = aws_instance.green
  to   = aws_instance.prod
}

moved {
  from = aws_eip.blue
  to   = aws_eip.stage
}

moved {
  from = aws_eip.green
  to   = aws_eip.prod
}

moved {
  from = aws_eip_association.blue
  to   = aws_eip_association.stage
}

moved {
  from = aws_eip_association.green
  to   = aws_eip_association.prod
}

moved {
  from = aws_instance.server
  to   = aws_instance.prod
}

moved {
  from = aws_eip.server_ip
  to   = aws_eip.prod
}

moved {
  from = aws_eip_association.server_ip
  to   = aws_eip_association.prod
}

resource "aws_s3_bucket" "public" {
  bucket_prefix = "server-web-public-"

  tags = {
    Name = "server-web-public"
  }
}

resource "aws_s3_bucket_public_access_block" "public" {
  bucket = aws_s3_bucket.public.id

  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = false
  restrict_public_buckets = false
}

resource "aws_s3_bucket_policy" "public" {
  bucket = aws_s3_bucket.public.id

  depends_on = [
    aws_s3_bucket_public_access_block.public
  ]

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "PublicRead"
        Effect    = "Allow"
        Principal = "*"
        Action    = "s3:GetObject"
        Resource  = "${aws_s3_bucket.public.arn}/*"
      }
    ]
  })
}

output "stage_ip" {
  value = aws_eip.stage.public_ip
}

output "prod_ip" {
  value = aws_eip.prod.public_ip
}

output "staging_ip" {
  value = aws_eip.stage.public_ip
}

output "production_ip" {
  value = aws_eip.prod.public_ip
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
