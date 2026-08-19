# dte-service en contenedor (Debian) — porque el OpenSSL de Vercel/Amazon Linux
# bloquea las firmas SHA1 que el SII exige. En Debian, SHA1 funciona de fábrica.
#
# El código PHP es EXACTAMENTE el mismo que corría en Vercel; solo cambia dónde
# vive. Se despliega en Koyeb (o cualquier plataforma que corra un Dockerfile).

FROM php:8.5-cli-bookworm

# Extensiones que LibreDTE necesita y NO vienen por defecto en la imagen oficial:
#   mbstring (libonig), soap (libxml2), xsl (libxslt), zip (libzip).
# (curl, openssl, dom, libxml, SimpleXML, xmlwriter ya vienen incluidas.)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libonig-dev libxml2-dev libxslt1-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring soap xsl zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (desde su imagen oficial, sin instalarlo a mano).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Dependencias PHP. --no-dev = sin las de desarrollo; usa composer.lock para que
# el build sea reproducible (los mismos commits de dev-master que en Vercel).
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Koyeb (y la mayoría) inyecta el puerto por la variable $PORT. El servidor
# embebido de PHP + router.php replican el ruteo por archivos de Vercel:
# /api/salud → api/salud.php. Suficiente para el tráfico de este servicio
# (webhooks de pago + cron); si algún día necesita concurrencia alta, se cambia
# a php-fpm + nginx sin tocar el código.
ENV PORT=8000
EXPOSE 8000
CMD php -S 0.0.0.0:$PORT -t /app router.php
