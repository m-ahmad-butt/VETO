variable "aws_region" {
  description = "AWS Region in which to create both demo servers."
  type        = string
}

variable "key_name" {
  description = "Name of an existing EC2 key pair in aws_region."
  type        = string
}

variable "admin_cidr" {
  description = "Your public IPv4 address with /32, used for SSH and the VETO tunnel."
  type        = string
  validation {
    condition     = can(cidrhost(var.admin_cidr, 0)) && endswith(var.admin_cidr, "/32")
    error_message = "Set a single public IPv4 address, for example 203.0.113.10/32."
  }
}

variable "project_name" {
  type    = string
  default = "veto-demo"
}

variable "instance_type" {
  type    = string
  default = "m7i-flex.large"
}

variable "veto_instance_type" {
  description = "VETO server size. Full Docker sandbox analysis may exceed the memory of t3.small."
  type        = string
  default     = "t3.small"
}
