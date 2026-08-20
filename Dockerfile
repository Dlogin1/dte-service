# dte-service en contenedor (Debian) — porque el OpenSSL de Vercel/Amazon Linux
# bloquea las firmas SHA1 que el SII exige. En Debian, SHA1 funciona de fábrica.
#
# El código PHP es EXACTAMENTE el mismo que corría en Vercel; solo cambia dónde
# vive. Se despliega en Render (o cualquier plataforma que corra un Dockerfile).

FROM php:8.5-cli-bookworm

# Extensiones que LibreDTE necesita y NO vienen por defecto en la imagen oficial:
#   mbstring (libonig), soap (libxml2), xsl (libxslt), zip (libzip),
#   bcmath (matemática de folios/RUT), intl (libicu), gmp (libgmp).
# (curl, openssl, dom, libxml, SimpleXML, xmlwriter, sodium ya vienen incluidas.)
# gd NO se instala: el timbre PDF417 lo dibuja el flujo de PDF de regsi, no este
# servicio, así que se finge en composer.json y se salta con --ignore-platform-reqs.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libonig-dev libxml2-dev libxslt1-dev libzip-dev \
        libicu-dev libgmp-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring soap xsl zip bcmath intl gmp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (desde su imagen oficial, sin instalarlo a mano).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Dependencias PHP. --no-dev = sin las de desarrollo; usa composer.lock para que
# el build sea reproducible (los mismos commits de dev-master que en Vercel).
# --ignore-platform-reqs: no abortar el build por extensiones que exige el árbol
# pero que este servicio no usa en runtime (gd, y las opcionales de dev). Las que
# SÍ se usan se instalan arriba con docker-php-ext-install.
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_PROCESS_TIMEOUT=0

# Con dev-master + las deps dev-main de derafu, composer clona muchos repos de
# GitHub. En un build SIN caché eso puede chocar con el rate limit de GitHub
# (sin token) y fallar (exit 100). Si Render tiene la env GITHUB_TOKEN, se usa
# (sube el límite de 60/h a 5000/h). Y se reintenta hasta 3 veces por si el fallo
# es transitorio de red. --no-progress para logs más limpios.
ARG GITHUB_TOKEN=""
RUN if [ -n "$GITHUB_TOKEN" ]; then composer config -g github-oauth.github.com "$GITHUB_TOKEN"; fi \
    && for i in 1 2 3; do \
         composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
           --ignore-platform-reqs --no-progress && break; \
         echo ">> composer install falló (intento $i/3); reintento en 15s..."; sleep 15; \
       done \
    && test -f vendor/autoload.php

# Render (y la mayoría) inyecta el puerto por la variable $PORT. El servidor
# embebido de PHP + router.php replican el ruteo por archivos de Vercel:
# /api/salud → api/salud.php. Suficiente para el tráfico de este servicio
# (webhooks de pago + cron); si algún día necesita concurrencia alta, se cambia
# a php-fpm + nginx sin tocar el código.
ENV PORT=8000
EXPOSE 8000
CMD php -S 0.0.0.0:$PORT -t /app router.php
