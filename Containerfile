FROM debian:trixie-slim AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        diffutils \
        git \
        gnupg \
        unzip \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /etc/apt/keyrings/sury-php.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ trixie main" \
        > /etc/apt/sources.list.d/sury-php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli \
        php8.5-xml \
    && ln -sf /usr/bin/php8.5 /usr/local/bin/php \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer


FROM php-base AS php-check-tools

ENV COMPOSER_HOME=/opt/composer

RUN composer global require --dev --no-interaction --prefer-dist \
    phpstan/phpstan:^2.1 \
    squizlabs/php_codesniffer:^4.0 \
    spaze/phpstan-disallowed-calls:^4.8 \
    psr/log:^3.0 \
    monolog/monolog:^3.0 \
    adhocore/cli:^1.9 \
    && ln -s /opt/composer/vendor/bin/phpstan /usr/local/bin/phpstan \
    && ln -s /opt/composer/vendor/bin/phpcs /usr/local/bin/phpcs


FROM python:3.14-slim AS python-check-tools

COPY tester/requirements.txt /opt/src/tester/requirements.txt

RUN pip install --no-cache-dir \
    -r /opt/src/tester/requirements.txt \
    "ruff~=0.14.4" \
    "mypy~=1.19.1"


FROM php-check-tools AS check

COPY --from=python-check-tools /usr/local /usr/local

ENTRYPOINT ["/bin/bash"]


FROM php-base AS php-runtime-deps

WORKDIR /opt/int

COPY int/ /opt/int/

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader


FROM php-base AS runtime

WORKDIR /opt/int

COPY --from=php-runtime-deps /opt/int /opt/int

ENTRYPOINT ["php", "/opt/int/src/solint.php"]


FROM python:3.14-slim AS python-test-deps

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        libxml2-dev \
        libxslt1-dev \
        zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

COPY tester/requirements.txt /opt/src/tester/requirements.txt
COPY tester/sol2xml/requirements.txt /opt/src/tester/sol2xml/requirements.txt

RUN pip install --no-cache-dir \
    -r /opt/src/tester/requirements.txt \
    -r /opt/src/tester/sol2xml/requirements.txt


FROM runtime AS test

COPY --from=python-test-deps /usr/local /usr/local

WORKDIR /opt/tester

COPY tester/ /opt/tester/

ENV PYTHONPATH=/opt/tester/src \
    SOL2XML_COMMAND="python /opt/tester/sol2xml/sol_to_xml.py" \
    SOL26_INTERPRETER_COMMAND="php /opt/int/src/solint.php" \
    SOL26_DIFF_COMMAND="diff"

ENTRYPOINT ["python", "/opt/tester/src/tester.py"]
