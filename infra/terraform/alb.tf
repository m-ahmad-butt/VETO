resource "aws_lb" "stage" {
  name               = "veto-stage-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = data.aws_subnets.default.ids

  tags = {
    Name = "veto-stage-alb"
    Env  = "stage"
  }
}

resource "aws_lb" "prod" {
  name               = "veto-prod-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = data.aws_subnets.default.ids

  tags = {
    Name = "veto-prod-alb"
    Env  = "prod"
  }
}

resource "aws_lb_target_group" "stage" {
  name     = "veto-stage-tg"
  port     = 30080
  protocol = "HTTP"
  vpc_id   = data.aws_vpc.default.id

  health_check {
    enabled             = true
    path                = "/"
    port                = "30080"
    protocol            = "HTTP"
    matcher             = "200-399"
    interval            = 30
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "veto-stage-tg"
  }
}

resource "aws_lb_target_group" "prod" {
  name     = "veto-prod-tg"
  port     = 30080
  protocol = "HTTP"
  vpc_id   = data.aws_vpc.default.id

  health_check {
    enabled             = true
    path                = "/"
    port                = "30080"
    protocol            = "HTTP"
    matcher             = "200-399"
    interval            = 30
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "veto-prod-tg"
  }
}

resource "aws_lb_listener" "stage_http" {
  load_balancer_arn = aws_lb.stage.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.stage.arn
  }
}

resource "aws_lb_listener" "prod_http" {
  load_balancer_arn = aws_lb.prod.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.prod.arn
  }
}
