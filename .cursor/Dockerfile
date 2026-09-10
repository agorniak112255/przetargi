FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        php8.3-cli php8.3-common php8.3-mbstring php8.3-xml php8.3-curl \
        php8.3-bcmath php8.3-gd php8.3-zip php8.3-sqlite3 php8.3-intl php8.3-mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN userdel -r ubuntu 2>/dev/null || true \
    && useradd -m -s /bin/bash ubuntu

USER ubuntu
