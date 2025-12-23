# Custom Dockerfile for nr_llm TYPO3 extension development
# Adds PHP extensions and tools needed for LLM development

# Install additional PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PCOV for fast code coverage (alternative to Xdebug coverage)
RUN pecl install pcov && docker-php-ext-enable pcov

# Configure PHP for development
RUN echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize=50M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size=50M" >> /usr/local/etc/php/conf.d/custom.ini

# Set PCOV as default coverage driver (faster than Xdebug)
RUN echo "pcov.enabled=1" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "pcov.directory=/var/www/html/packages/nr_llm" >> /usr/local/etc/php/conf.d/custom.ini
