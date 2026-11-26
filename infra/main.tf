data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
  filter {
    name   = "default-for-az"
    values = ["true"]
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

resource "aws_security_group" "wordpress" {
  name_prefix = "${var.project_name}-wordpress-"
  description = "Public WordPress demo and restricted SSH"
  vpc_id      = data.aws_vpc.default.id
  tags        = { Name = "${var.project_name}-wordpress" }
}

resource "aws_security_group" "veto" {
  name_prefix = "${var.project_name}-veto-"
  description = "VETO demo with restricted SSH; UI reached through SSH tunnel"
  vpc_id      = data.aws_vpc.default.id
  tags        = { Name = "${var.project_name}-veto" }
}

resource "aws_vpc_security_group_ingress_rule" "wordpress_http" {
  security_group_id = aws_security_group.wordpress.id
  cidr_ipv4         = "0.0.0.0/0"
  from_port         = 80
  to_port           = 80
  ip_protocol       = "tcp"
  description       = "Public demo website"
}

resource "aws_vpc_security_group_ingress_rule" "wordpress_ssh" {
  security_group_id = aws_security_group.wordpress.id
  cidr_ipv4         = var.admin_cidr
  from_port         = 22
  to_port           = 22
  ip_protocol       = "tcp"
  description       = "Demo operator SSH"
}

resource "aws_vpc_security_group_ingress_rule" "veto_ssh" {
  security_group_id = aws_security_group.veto.id
  cidr_ipv4         = var.admin_cidr
  from_port         = 22
  to_port           = 22
  ip_protocol       = "tcp"
  description       = "Demo operator SSH and UI tunnel"
}

resource "aws_vpc_security_group_ingress_rule" "veto_http" {
  security_group_id = aws_security_group.veto.id
  cidr_ipv4         = var.admin_cidr
  from_port         = 80
  to_port           = 80
  ip_protocol       = "tcp"
  description       = "VETO dashboard for demo operator"
}

resource "aws_vpc_security_group_egress_rule" "wordpress_all" {
  security_group_id = aws_security_group.wordpress.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "-1"
}

resource "aws_vpc_security_group_egress_rule" "veto_all" {
  security_group_id = aws_security_group.veto.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "-1"
}

resource "aws_instance" "wordpress" {
  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.instance_type
  subnet_id                   = sort(data.aws_subnets.default.ids)[0]
  associate_public_ip_address = true
  key_name                    = var.key_name
  vpc_security_group_ids      = [aws_security_group.wordpress.id]
  metadata_options {
    http_tokens = "required"
  }
  root_block_device {
    volume_type = "gp3"
    volume_size = 30
    encrypted   = true
  }
  tags = { Name = "${var.project_name}-wordpress", Project = var.project_name }
}

resource "aws_instance" "veto" {
  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.veto_instance_type
  subnet_id                   = sort(data.aws_subnets.default.ids)[0]
  associate_public_ip_address = true
  key_name                    = var.key_name
  vpc_security_group_ids      = [aws_security_group.veto.id]
  metadata_options {
    http_tokens = "required"
  }
  root_block_device {
    volume_type = "gp3"
    volume_size = 50
    encrypted   = true
  }
  tags = { Name = "${var.project_name}-veto", Project = var.project_name }
}
