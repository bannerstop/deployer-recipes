FROM php:8.5-cli-bookworm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git openssh-client rsync unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/deployer
RUN composer require deployer/deployer:^7.5 --no-interaction --no-progress
