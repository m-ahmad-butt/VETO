FROM wordpress:6.8-php8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf
COPY runtime/ /opt/veto/
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
