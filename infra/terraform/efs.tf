resource "aws_security_group" "efs" {
  name        = "veto-efs-sg"
  description = "EFS NFS from ASG nodes"
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description     = "NFS from nodes"
    from_port       = 2049 # nfs only k3s ec2 can mount
    to_port         = 2049
    protocol        = "tcp"
    security_groups = [aws_security_group.node.id] # only asg nodes
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "veto-efs-sg"
  }
}

# token will help to avoid duplicate efs
resource "aws_efs_file_system" "stage" {
  creation_token = "veto-stage-efs"
  encrypted      = true

  tags = {
    Name = "veto-stage-efs"
    Env  = "stage"
  }
}

resource "aws_efs_file_system" "prod" {
  creation_token = "veto-prod-efs"
  encrypted      = true

  tags = {
    Name = "veto-prod-efs"
    Env  = "prod"
  }
}

# mount targers (mount per subnet within asg)
resource "aws_efs_mount_target" "stage" {
  for_each = toset(data.aws_subnets.default.ids)

  file_system_id  = aws_efs_file_system.stage.id
  subnet_id       = each.value
  security_groups = [aws_security_group.efs.id]
}

resource "aws_efs_mount_target" "prod" {
  for_each = toset(data.aws_subnets.default.ids)

  file_system_id  = aws_efs_file_system.prod.id
  subnet_id       = each.value
  security_groups = [aws_security_group.efs.id]
}
