FROM mysql:8.4

# The tuning file is copied in rather than bind-mounted: a bind mount from a
# Windows host arrives world-writable, and MySQL silently ignores config files
# that are world-writable ("World-writable config file ... is ignored").
ARG MYSQL_CONF=conf.d/10-defaults.cnf
COPY ${MYSQL_CONF} /etc/mysql/conf.d/zz-tuning.cnf
RUN chown root:root /etc/mysql/conf.d/zz-tuning.cnf \
    && chmod 0644 /etc/mysql/conf.d/zz-tuning.cnf
