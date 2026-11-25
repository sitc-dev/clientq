FROM jenkins/jenkins:lts-jdk17

# Switch to root user to install system packages
USER root

# Install system dependencies, PHP, and common extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    git \
    unzip \
    zip \
    # Install PHP and extensions needed for Composer/PHPUnit
    php-cli \
    php-common \
    php-curl \
    php-mbstring \
    php-xml \
    php-zip \
    php-bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
