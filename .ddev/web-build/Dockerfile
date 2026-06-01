# Custom Dockerfile for nr_llm TYPO3 extension development
# Adds PHP extensions and tools needed for LLM development

# Install PCOV for fast code coverage using apt-get (pecl not available in DDEV)
RUN apt-get update && apt-get install -y php${PHP_VERSION}-pcov && rm -rf /var/lib/apt/lists/*

# Upgrade PHP to latest patch version (get fixes before DDEV updates)
# Use dist-upgrade to handle dependency changes between versions.
# Run non-interactively and keep DDEV's existing conffiles (e.g.
# php8.4-fpm.conf) so the build does not stall on a dpkg conffile prompt.
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confold" && \
    rm -rf /var/lib/apt/lists/*

# Copy landing page to web root with correct permissions
COPY --chmod=644 landing-index.html /var/www/html/index.html
