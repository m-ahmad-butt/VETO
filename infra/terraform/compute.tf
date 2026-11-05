resource "aws_launch_template" "stage" {
  name_prefix   = "veto-stage-"
  image_id      = data.aws_ami.ubuntu.id
  instance_type = local.instance_type
  key_name      = aws_key_pair.server_key.key_name

  vpc_security_group_ids = [aws_security_group.node.id]

  block_device_mappings {
    device_name = "/dev/sda1"
    ebs {
      volume_size = local.volume_size
      volume_type = "gp3"
    }
  }

  tag_specifications {
    resource_type = "instance"
    tags = {
      Name = "veto-stage"
      Role = "stage"
      Env  = "stage"
    }
  }
}

resource "aws_launch_template" "prod" {
  name_prefix   = "veto-prod-"
  image_id      = data.aws_ami.ubuntu.id
  instance_type = local.instance_type
  key_name      = aws_key_pair.server_key.key_name

  vpc_security_group_ids = [aws_security_group.node.id]

  block_device_mappings {
    device_name = "/dev/sda1"
    ebs {
      volume_size = local.volume_size
      volume_type = "gp3"
    }
  }

  tag_specifications {
    resource_type = "instance"
    tags = {
      Name = "veto-prod"
      Role = "prod"
      Env  = "prod"
    }
  }
}

resource "aws_autoscaling_group" "stage" {
  name                      = "veto-stage-asg"
  vpc_zone_identifier       = data.aws_subnets.default.ids
  desired_capacity          = 1
  min_size                  = 1
  max_size                  = 2
  health_check_type         = "EC2"
  health_check_grace_period = 120

  launch_template {
    id      = aws_launch_template.stage.id
    version = "$Latest"
  }

  target_group_arns = [aws_lb_target_group.stage.arn]

  tag {
    key                 = "Name"
    value               = "veto-stage"
    propagate_at_launch = true
  }

  tag {
    key                 = "Env"
    value               = "stage"
    propagate_at_launch = true
  }
}

resource "aws_autoscaling_group" "prod" {
  name                      = "veto-prod-asg"
  vpc_zone_identifier       = data.aws_subnets.default.ids
  desired_capacity          = 1
  min_size                  = 1
  max_size                  = 2
  health_check_type         = "EC2"
  health_check_grace_period = 120

  launch_template {
    id      = aws_launch_template.prod.id
    version = "$Latest"
  }

  target_group_arns = [aws_lb_target_group.prod.arn]

  tag {
    key                 = "Name"
    value               = "veto-prod"
    propagate_at_launch = true
  }

  tag {
    key                 = "Env"
    value               = "prod"
    propagate_at_launch = true
  }
}

resource "aws_autoscaling_policy" "stage_cpu" {
  name                   = "veto-stage-cpu-target"
  autoscaling_group_name = aws_autoscaling_group.stage.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value = 70.0
  }
}

resource "aws_autoscaling_policy" "prod_cpu" {
  name                   = "veto-prod-cpu-target"
  autoscaling_group_name = aws_autoscaling_group.prod.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value = 70.0
  }
}
